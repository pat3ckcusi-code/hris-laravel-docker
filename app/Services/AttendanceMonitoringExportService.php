<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\EmployeeShiftSchedule;
use App\Models\Eta;
use App\Models\Holiday;
use App\Models\HRAuditTrail;
use App\Models\LeaveDate;
use App\Models\Locator;
use App\Models\Setting;
use App\Models\UniformInspectionDetail;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Support\WorkSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceMonitoringExportService
{
    /**
     * Compute one row per employee for the given departments and month.
     *
     * @param  Collection<int, Department>  $departments
     * @return Collection<int, array>
     */
    public function getRows(Collection $departments, int $month, int $year, ?string $employeeType = null): Collection
    {
        $deptIds = $departments->pluck('Dept_id')->toArray();

        $employees = User::active()->whereIn('Dept_id', $deptIds)
            ->with('shift', 'department')
            ->when($employeeType, fn ($q, $t) => $q->where('employee_type', $t))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $employeeIds = $employees->pluck('id')->toArray();

        // Bulk-load - no N+1
        $dtrs = Dtr::whereIn('employee_id', $employeeIds)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get()
            ->groupBy('employee_id');

        $approvedLeaveDatesByUser = LeaveDate::where('is_cancelled', false)
            ->whereHas('leaveRequest', function ($q) use ($employeeIds) {
                $q->whereIn('user_id', $employeeIds)->where('status', 'approved');
            })
            ->whereYear('leave_date', $year)
            ->whereMonth('leave_date', $month)
            ->with('leaveRequest:id,user_id,leave_type')
            ->get()
            ->groupBy(fn ($ld) => $ld->leaveRequest->user_id ?? 0);

        $locators = Locator::whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->whereYear('travel_date', $year)
            ->whereMonth('travel_date', $month)
            ->get()
            ->groupBy('user_id');

        // ETAs that overlap with the month (departure or arrival within the month)
        $etas = Eta::whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->where(function ($q) use ($month, $year) {
                $q->where(function ($q2) use ($month, $year) {
                    $q2->whereYear('departure_date', $year)->whereMonth('departure_date', $month);
                })->orWhere(function ($q2) use ($month, $year) {
                    $q2->whereYear('arrival_date', $year)->whereMonth('arrival_date', $month);
                });
            })
            ->get()
            ->groupBy('user_id');

        $uniformViolations = UniformInspectionDetail::with('inspection')
            ->whereIn('employee_id', $employeeIds)
            ->whereHas('inspection', fn ($q) => $q
                ->whereYear('inspection_date', $year)
                ->whereMonth('inspection_date', $month)
            )
            ->get()
            ->groupBy('employee_id');

        $dtrExcuses = DtrExcuse::whereIn('user_id', $employeeIds)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get()
            ->groupBy('user_id');

        $periodStart = Carbon::createFromDate($year, $month, 1)->toDateString();
        $periodEnd = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $allAssignments = EmployeeShiftSchedule::whereIn('user_id', $employeeIds)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get()
            ->groupBy('user_id')
            ->map(fn ($group) => $group->keyBy(fn ($a) => $a->date->toDateString()));

        // Warm the shift-assignment-history memo once for the whole department so
        // the per-date WorkSchedule calls in the per-employee map() below stay O(1).
        WorkSchedule::preloadShiftAssignments($employeeIds);

        $empNos = $employees->pluck('EmpNo')->filter()->values()->toArray();
        $officeOrdersByEmpNo = empty($empNos) ? collect() : DB::table('office_orders')
            ->join('office_order_employees', 'office_orders.id', '=', 'office_order_employees.office_order_id')
            ->whereIn('office_order_employees.emp_no', $empNos)
            ->where('office_orders.issued_date', '<=', $periodEnd)
            ->where(function ($q) use ($periodStart): void {
                $q->where('office_orders.effective_date', '>=', $periodStart)
                    ->orWhere(function ($q2) use ($periodStart): void {
                        $q2->whereNull('office_orders.effective_date')
                            ->where('office_orders.issued_date', '>=', $periodStart);
                    });
            })
            ->select('office_order_employees.emp_no', 'office_orders.office_order_num', 'office_orders.effective_date', 'office_orders.issued_date')
            ->get()
            ->groupBy('emp_no');

        $travelOrdersByEmpNo = empty($empNos) ? collect() : DB::table('travel_orders')
            ->join('travel_order_employees', 'travel_orders.id', '=', 'travel_order_employees.travel_order_id')
            ->whereIn('travel_order_employees.emp_no', $empNos)
            ->where('travel_orders.status', 'Approved')
            ->where('travel_orders.start_date', '<=', $periodEnd)
            ->where('travel_orders.end_date', '>=', $periodStart)
            ->select('travel_order_employees.emp_no', 'travel_orders.travel_order_num', 'travel_orders.start_date', 'travel_orders.end_date')
            ->get()
            ->groupBy('emp_no');

        // Public holidays in the period are never counted as absence days, regardless
        // of punch/coverage state.
        $holidays = Holiday::whereBetween('holiday_date', [$periodStart, $periodEnd])
            ->pluck('holiday_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        // Declared work suspensions in the period - a full-day suspension is never
        // counted as an absence day, same as a holiday; a partial-day suspension
        // instead excludes just the slots it covers (per employee schedule, resolved
        // below) via WorkSchedule::applySuspension().
        $suspensions = WorkSuspension::whereBetween('suspension_date', [$periodStart, $periodEnd])->get();
        $fullDaySuspensionDates = $suspensions->filter(fn (WorkSuspension $s) => $s->suspension_time === null)
            ->pluck('suspension_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->flip();

        return $employees->map(function (User $emp) use ($dtrs, $approvedLeaveDatesByUser, $locators, $etas, $uniformViolations, $dtrExcuses, $month, $year, $allAssignments, $officeOrdersByEmpNo, $travelOrdersByEmpNo, $holidays, $suspensions, $fullDaySuspensionDates, $periodStart, $periodEnd) {
            $empDtrs = $dtrs->get($emp->id, collect());
            $empLeaveDates = $approvedLeaveDatesByUser->get($emp->id, collect());
            $empLocators = $locators->get($emp->id, collect());
            $empEtas = $etas->get($emp->id, collect());
            $empViolations = $uniformViolations->get($emp->id, collect());
            $empAssignments = $allAssignments->get($emp->id, collect());

            // Keyed by date string for O(1) excuse lookup.
            $empExcusesByDate = $dtrExcuses->get($emp->id, collect())
                ->keyBy(fn ($e) => Carbon::parse($e->date)->toDateString());

            // Keyed by date string for O(1) per-slot punch lookup - needed below by
            // $isSlotCovered's half-day-leave branch, so this is built ahead of that
            // closure rather than at its original, later position in this method.
            $empDtrsByDate = $empDtrs->keyBy(fn ($d) => Carbon::parse($d->date)->toDateString());

            // Exclude DTR records that fall on rest days (per-date shift schedule).
            $workDtrs = $empDtrs->filter(
                fn ($d) => ! WorkSchedule::isRestDay($emp, Carbon::parse($d->date), $empAssignments)
            );

            // dateStr => days (float). Kept as the real leave 'days' value (not a bare
            // presence flag) so $isSlotCovered below can tell a full-day leave (days >=
            // 1, covers the whole day unconditionally) apart from a half-day leave
            // (days < 1, covers only the slot(s) with no real punch).
            $approvedLeaveDateStrings = $empLeaveDates
                ->mapWithKeys(fn ($ld) => [Carbon::parse($ld->leave_date)->toDateString() => (float) $ld->days]);

            $unfiledCount = $workDtrs->filter(function ($d) use ($approvedLeaveDateStrings, $empExcusesByDate) {
                if (! $d->is_absent) {
                    return false;
                }
                $dateStr = Carbon::parse($d->date)->toDateString();
                if ($approvedLeaveDateStrings->has($dateStr)) {
                    return false;
                }
                $excuse = $empExcusesByDate[$dateStr] ?? null;

                return ! ($excuse && $excuse->is_full_day);
            })->count();

            $officialLeaveCount = $empLeaveDates->count();

            $personalLocators = $empLocators->filter(fn ($l) => strtolower((string) $l->application_type) === 'personal');

            // Locator slot coverage per date (any application_type - the point is whether
            // there's a paper trail, not whether it was "official" business).
            $locatorSlotMap = [];
            foreach ($empLocators as $l) {
                if (! $l->intended_departure_time || ! $l->intended_arrival_time) {
                    continue;
                }
                $dateStr = Carbon::parse($l->travel_date)->toDateString();
                $schedule = WorkSchedule::forUserOnDate($emp, Carbon::parse($l->travel_date), $empAssignments);
                $slots = Locator::coveredSlotKeys((string) $l->intended_departure_time, (string) $l->intended_arrival_time, $schedule);
                $locatorSlotMap[$dateStr] = array_unique(array_merge($locatorSlotMap[$dateStr] ?? [], $slots));
            }

            // ETA covers the whole day it spans.
            $etaCoveredDates = [];
            foreach ($empEtas as $eta) {
                $start = Carbon::parse($eta->departure_date)->startOfDay();
                $end = Carbon::parse($eta->arrival_date ?? $eta->departure_date)->startOfDay();
                for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                    $etaCoveredDates[$d->toDateString()] = true;
                }
            }

            // Office Order covers every day from issued_date through effective_date
            // (or just issued_date if effective_date isn't set), clamped to the period.
            $officeOrderCoveredDates = [];
            foreach ($officeOrdersByEmpNo->get($emp->EmpNo, collect()) as $oo) {
                if (! $oo->issued_date) {
                    continue;
                }
                $start = Carbon::parse($oo->issued_date)->startOfDay();
                $end = Carbon::parse($oo->effective_date ?? $oo->issued_date)->startOfDay();
                $start = $start->lt(Carbon::parse($periodStart)) ? Carbon::parse($periodStart) : $start;
                $end = $end->gt(Carbon::parse($periodEnd)) ? Carbon::parse($periodEnd) : $end;
                for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                    $officeOrderCoveredDates[$d->toDateString()] = true;
                }
            }

            // Approved Travel Order covers every day of its (month-clamped) date range.
            $travelOrderCoveredDates = [];
            foreach ($travelOrdersByEmpNo->get($emp->EmpNo, collect()) as $to) {
                if (! $to->start_date || ! $to->end_date) {
                    continue;
                }
                $start = Carbon::parse($to->start_date)->startOfDay();
                $end = Carbon::parse($to->end_date)->startOfDay();
                $start = $start->lt(Carbon::parse($periodStart)) ? Carbon::parse($periodStart) : $start;
                $end = $end->gt(Carbon::parse($periodEnd)) ? Carbon::parse($periodEnd) : $end;
                for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                    $travelOrderCoveredDates[$d->toDateString()] = true;
                }
            }

            // Per-date suspension-driven excluded slots for this employee (depends on
            // their own schedule per date, so it's resolved here rather than at the
            // top-level query like $holidays/$fullDaySuspensionDates). Only the
            // slot-excluding tiers of applySuspension() contribute here - the
            // capped-workEnd-only tier returns no slots and is instead handled where
            // the phantom-undertime loop resolves its own per-date schedule below.
            $suspensionsByDate = $suspensions->keyBy(fn (WorkSuspension $s) => Carbon::parse($s->suspension_date)->toDateString());
            $empIsFrontlineExempt = $emp->isFrontlineExempt();
            $empSuspensionSlotMap = [];
            if (! $empIsFrontlineExempt) {
                foreach ($suspensionsByDate as $dateStr => $suspension) {
                    $sSchedule = WorkSchedule::forUserOnDate($emp, Carbon::parse($suspension->suspension_date), $empAssignments);
                    [, $slots] = $sSchedule->applySuspension($suspension->suspension_time);
                    if (! empty($slots)) {
                        $empSuspensionSlotMap[$dateStr] = array_keys($slots);
                    }
                }
            }

            // Single source of truth for "is this slot explained by an approved Leave/ETA/
            // Office Order/Travel Order (whole-day) or a Locator/DtrExcuse/WorkSuspension
            // (per-slot)", shared by unofficialExitCount and the phantom-undertime
            // computation below so the rules never disagree on what counts as covered.
            // A full-day leave (days >= 1) still covers the whole day unconditionally,
            // same as ETA/Office Order/Travel Order. A half-day leave (days < 1) only
            // covers the slot(s) with no real punch - "which half" isn't stored
            // anywhere, so it's inferred from the punch data itself, same convention
            // as DtrController/Form48ExportService use for the same half-day-leave case.
            $isSlotCovered = function (string $dateStr, string $slot) use ($locatorSlotMap, $etaCoveredDates, $officeOrderCoveredDates, $travelOrderCoveredDates, $approvedLeaveDateStrings, $empExcusesByDate, $empSuspensionSlotMap, $empDtrsByDate): bool {
                if (isset($etaCoveredDates[$dateStr]) || isset($officeOrderCoveredDates[$dateStr])
                    || isset($travelOrderCoveredDates[$dateStr])) {
                    return true;
                }
                if ($approvedLeaveDateStrings->has($dateStr)) {
                    if ($approvedLeaveDateStrings->get($dateStr) >= 1.0) {
                        return true;
                    }
                    $dtr = $empDtrsByDate->get($dateStr);
                    $punchValue = match ($slot) {
                        'am_in' => $dtr?->time_in_am,
                        'am_out' => $dtr?->time_out_am,
                        'pm_in' => $dtr?->time_in_pm,
                        'pm_out' => $dtr?->time_out_pm,
                        default => null,
                    };
                    if (empty($punchValue)) {
                        return true;
                    }
                    // A real punch exists for this slot on a half-day leave date - fall
                    // through to the other, per-slot sources below instead of treating
                    // the whole day as covered.
                }
                $excuse = $empExcusesByDate[$dateStr] ?? null;
                $coveredSlots = array_unique(array_merge(
                    $locatorSlotMap[$dateStr] ?? [],
                    $excuse ? $excuse->excludedSlotKeys() : [],
                    $empSuspensionSlotMap[$dateStr] ?? []
                ));

                return in_array($slot, $coveredSlots, true);
            };

            // Late/undertime-specific views of $isSlotCovered, checking the same slots
            // the pre-existing DtrExcuse-only logic used to check directly (tardiness ->
            // am_in/pm_in, undertime -> pm_out). Since $isSlotCovered already folds
            // DtrExcuse::excludedSlotKeys() into its per-slot check, this reproduces the
            // old excuse-only behavior unchanged while additionally suppressing
            // tardiness/undertime on any Office-Order/Travel-Order/ETA/approved-Leave/
            // Locator/Suspension-covered day - closing the gap where those whole-day
            // sources explained an absence but not a same-day late/undertime charge.
            $isLateCovered = fn (string $dateStr): bool => $isSlotCovered($dateStr, 'am_in') || $isSlotCovered($dateStr, 'pm_in');
            $isUndertimeCovered = fn (string $dateStr): bool => $isSlotCovered($dateStr, 'pm_out');

            $undertimeCount = $workDtrs->filter(
                fn ($d) => $d->undertime_minutes > 0 && ! $isUndertimeCovered(Carbon::parse($d->date)->toDateString())
            )->count();

            $tardinessCount = $workDtrs->filter(
                fn ($d) => $d->late_minutes > 0 && ! $isLateCovered(Carbon::parse($d->date)->toDateString())
            )->count();

            // Flagged when a scheduled, already-ended workday has no punched logs at all
            // and nothing (Leave/Locator/ETA/Office Order/Travel Order/DtrExcuse) explains
            // the absence. DTR-exempt employees are never expected to punch, so they're
            // never flagged. ($empDtrsByDate itself is built earlier now, ahead of
            // $isSlotCovered's own use of it above.)
            $periodStartDate = Carbon::parse($periodStart);
            $periodEndDate = Carbon::parse($periodEnd);

            $unofficialExitDays = [];
            $unfiledLeaveDays = [];

            // Job Order staff are blocked from filing leave entirely (DenyJobOrder
            // middleware) and Elected Officials don't accrue standard civil-service
            // leave credits, so a fully blank day for them stays classified as
            // Unofficial Exit rather than Unfiled Leave.
            $exemptFromUnfiledLeave = in_array($emp->employee_type, ['Elected Officials', 'Job Orders'], true);

            if (! $emp->dtr_exempt) {
                $serviceStart = ($emp->date_hired && $emp->date_hired->gt($periodStartDate))
                    ? $emp->date_hired->copy()->startOfDay()
                    : $periodStartDate->copy();

                for ($date = $serviceStart->copy(); $date->lte($periodEndDate); $date->addDay()) {
                    $dateStr = $date->toDateString();

                    if (WorkSchedule::isFieldWork($emp, $date, $empAssignments) || WorkSchedule::isWfh($emp, $date, $empAssignments)) {
                        continue;
                    }

                    if (! WorkSchedule::isWorkday($emp, $date, $empAssignments) || isset($holidays[$dateStr])
                        || (isset($fullDaySuspensionDates[$dateStr]) && ! $empIsFrontlineExempt)) {
                        continue;
                    }

                    $schedule = WorkSchedule::forUserOnDate($emp, $date, $empAssignments);
                    if (Carbon::now()->lt($schedule->referenceDateTime($dateStr, $schedule->workEnd))) {
                        continue;
                    }

                    $dtr = $empDtrsByDate->get($dateStr);

                    // The schedule's real required-slot set: a normal 4-slot day needs
                    // all four Form 48 slots; a no-break day only ever has am_in/pm_out
                    // (no break in the middle, so am_out/pm_in are never real slots);
                    // an in_only/out_only Field Work Shift day is excluded entirely
                    // (empty set) - WeeklyPunchPairReconciliationService already owns
                    // that pairing's absence logic week-by-week via its own
                    // field_work_unconfirmed override, and a naive per-day required-
                    // slot check here would double-flag/conflict with that more
                    // sophisticated, week-aware mechanism.
                    $requiredSlots = match (true) {
                        $schedule->punchRequirement !== 'both' => [],
                        $schedule->noBreak => ['am_in', 'pm_out'],
                        default => ['am_in', 'am_out', 'pm_in', 'pm_out'],
                    };

                    if ($dtr && $requiredSlots !== []) {
                        $punchedSlots = array_keys(array_filter([
                            'am_in' => $dtr->time_in_am,
                            'am_out' => $dtr->time_out_am,
                            'pm_in' => $dtr->time_in_pm,
                            'pm_out' => $dtr->time_out_pm,
                        ]));
                        $punchedRequired = array_intersect($punchedSlots, $requiredSlots);

                        // At least one required slot was punched - proof of presence
                        // for the day - but any OTHER required slot that's still blank
                        // and not individually covered (Locator/DtrExcuse/Suspension)
                        // is an unofficial exit. This single rule replaces the old
                        // narrow "PM In present, PM Out missing" special case, which is
                        // now just one shape it produces (missingRequired === ['pm_out']).
                        if ($punchedRequired !== []) {
                            $missingRequired = array_values(array_filter(
                                array_diff($requiredSlots, $punchedSlots),
                                fn (string $slot): bool => ! $isSlotCovered($dateStr, $slot)
                            ));

                            if ($missingRequired !== []) {
                                // Keep the exact pre-existing reason/label for the
                                // single "PM Out only" shape so existing remark-text
                                // assertions are unaffected; any broader gap (e.g. an
                                // AM-In-only day) gets a distinct, more accurate reason.
                                $unofficialExitDays[$dateStr] = $missingRequired === ['pm_out']
                                    ? 'no_time_out'
                                    : 'incomplete_punches';
                            }

                            continue;
                        }
                    }

                    // Also recognizes an OUT-side-only punch (e.g. a Field
                    // Work out_only Friday, which never has an AM punch by
                    // design) as proof of presence - not just an IN-side one.
                    // This is also the fallback for in_only/out_only days
                    // (requiredSlots === [], skipped above) and any dtr row
                    // whose only punches fall outside this schedule's
                    // required-slot set.
                    if ($dtr && ($dtr->time_in_am || $dtr->time_in_pm || $dtr->time_out_am || $dtr->time_out_pm)) {
                        continue;
                    }

                    if ($isSlotCovered($dateStr, 'am_in') && $isSlotCovered($dateStr, 'pm_in')) {
                        continue;
                    }

                    // A fully blank day - no punches at all.
                    if ($exemptFromUnfiledLeave) {
                        $unofficialExitDays[$dateStr] = 'absent';
                    } else {
                        $unfiledLeaveDays[$dateStr] = true;
                    }
                }
            }

            $unofficialExitCount = count($unofficialExitDays);

            // An employee with zero Dtr rows for the whole month (biometric device
            // outage, an EmpNo mismatch, or a department not yet onboarded) shouldn't
            // read as "confirmed absent every workday" - flag it distinctly so the
            // report doesn't cry wolf during a real data gap. Only trips when the
            // absence loop above actually found unexplained days, so an employee fully
            // covered by leave/etc. with no Dtr rows is never mislabeled.
            $unofficialExitNoData = $unofficialExitCount > 0 && $empDtrs->isEmpty();
            $unfiledLeaveNoData = count($unfiledLeaveDays) > 0 && $empDtrs->isEmpty();

            // Blank days for leave-accruing employee types add to the same "Unfiled
            // Leave" total as any manually-flagged (is_absent) Dtr rows above.
            $unfiledCount += count($unfiledLeaveDays);

            $tardinessMinutes = $workDtrs->sum(
                fn ($d) => $isLateCovered(Carbon::parse($d->date)->toDateString()) ? 0 : (int) $d->late_minutes
            );

            $undertimeMinutes = $workDtrs->sum(
                fn ($d) => $isUndertimeCovered(Carbon::parse($d->date)->toDateString()) ? 0 : (int) $d->undertime_minutes
            );

            $totalMinutes = $tardinessMinutes + $undertimeMinutes;

            // A day whose PM Out is missing (but PM In exists) means the employee never
            // logged their departure - since there's no punch proving they stayed later,
            // charge the shift's afternoon block (break-in -> shift end) as undertime,
            // unless something (Locator/ETA/Office Order/DtrExcuse) explains the gap.
            // Mirrors DtrController's identical imputation for the DTR page so the two
            // screens never disagree.
            $punchResolver = new DtrPunchResolver;
            $phantomUndertimeByDate = [];
            foreach ($workDtrs as $d) {
                $dateStr = Carbon::parse($d->date)->toDateString();

                if ($isSlotCovered($dateStr, 'pm_out')) {
                    continue;
                }

                $schedule = WorkSchedule::forUserOnDate($emp, Carbon::parse($d->date), $empAssignments);
                if (($suspension = $suspensionsByDate->get($dateStr)) !== null && ! $empIsFrontlineExempt) {
                    [$schedule] = $schedule->applySuspension($suspension->suspension_time);
                }
                $mins = $punchResolver->imputedUndertimeMinutes($d->time_in_am, $d->time_out_am, $d->time_in_pm, $d->time_out_pm, $dateStr, $schedule);

                if ($mins > 0) {
                    $phantomUndertimeByDate[$dateStr] = $mins;
                }
            }

            $undertimeCount += count($phantomUndertimeByDate);
            $undertimeMinutes += array_sum($phantomUndertimeByDate);
            $totalMinutes += array_sum($phantomUndertimeByDate);

            $personalLocatorMinutes = $personalLocators->sum(function ($l) {
                if (! $l->intended_departure_time || ! $l->intended_arrival_time) {
                    return 0;
                }
                try {
                    $dep = Carbon::createFromFormat('H:i:s', $l->intended_departure_time)
                        ?? Carbon::createFromFormat('H:i', $l->intended_departure_time);
                    $arr = Carbon::createFromFormat('H:i:s', $l->intended_arrival_time)
                        ?? Carbon::createFromFormat('H:i', $l->intended_arrival_time);

                    return max(0, $dep->diffInMinutes($arr));
                } catch (\Exception) {
                    return 0;
                }
            });

            // --- Build unified remarks sorted by day ---
            // Each entry: ['day' => int, 'label' => string]
            $remarkEntries = collect();

            // DTR: tardiness days (skip any day already explained by Office Order/Travel
            // Order/ETA/approved Leave/Locator/DtrExcuse - see $isLateCovered above).
            foreach ($workDtrs->filter(fn ($d) => $d->late_minutes > 0 && ! $isLateCovered(Carbon::parse($d->date)->toDateString())) as $d) {
                $day = Carbon::parse($d->date)->day;
                $remarkEntries->push(['day' => $day, 'label' => $day.'-Tardy ('.$d->late_minutes.' mins)']);
            }

            // DTR: undertime days (including missing-PM-Out days charged as phantom
            // undertime), skipping any day already explained per $isUndertimeCovered above.
            foreach ($workDtrs->filter(fn ($d) => $d->undertime_minutes > 0 && ! $isUndertimeCovered(Carbon::parse($d->date)->toDateString())) as $d) {
                $day = Carbon::parse($d->date)->day;
                $remarkEntries->push(['day' => $day, 'label' => $day.'-Undertime ('.$d->undertime_minutes.' mins)']);
            }
            foreach ($phantomUndertimeByDate as $dateStr => $mins) {
                $day = Carbon::parse($dateStr)->day;
                $remarkEntries->push(['day' => $day, 'label' => $day.'-Undertime ('.$mins.' mins)']);
            }

            // Unofficial exits - either a punched-in-but-never-punched-out day, or (for
            // employee types that don't accrue leave) a fully blank day. Collapsed to a
            // single note when it's a whole-month data gap rather than per-day entries,
            // to avoid flooding the Remarks column.
            if ($unofficialExitNoData) {
                $remarkEntries->push(['day' => 0, 'label' => 'No DTR data recorded this month - verify biometric import']);
            } else {
                foreach ($unofficialExitDays as $dateStr => $reason) {
                    $day = Carbon::parse($dateStr)->day;
                    $label = match ($reason) {
                        'no_time_out' => $day.'-Unofficial Exit (No Time Out)',
                        'incomplete_punches' => $day.'-Unofficial Exit (Incomplete Punches)',
                        default => $day.'-Absent (Unofficial Exit)', // 'absent'
                    };
                    $remarkEntries->push(['day' => $day, 'label' => $label]);
                }
            }

            // Unfiled leave - a fully blank day for a leave-accruing employee type.
            if ($unfiledLeaveNoData) {
                $remarkEntries->push(['day' => 0, 'label' => 'No DTR data recorded this month - verify biometric import']);
            } else {
                foreach (array_keys($unfiledLeaveDays) as $dateStr) {
                    $day = Carbon::parse($dateStr)->day;
                    $remarkEntries->push(['day' => $day, 'label' => $day.'-Absent (Unfiled Leave)']);
                }
            }

            // Approved leave dates
            foreach ($empLeaveDates as $ld) {
                $day = Carbon::parse($ld->leave_date)->day;
                $type = trim((string) ($ld->leaveRequest->leave_type ?? ''));
                if ($type !== '') {
                    $remarkEntries->push(['day' => $day, 'label' => $day.'-'.$type]);
                }
            }

            // Official locator slips
            $officialLocators = $empLocators->filter(fn ($l) => strtolower((string) $l->application_type) === 'official');
            foreach ($officialLocators as $l) {
                $day = Carbon::parse($l->travel_date)->day;
                $detail = trim((string) ($l->detail ?? $l->location ?? ''));
                if ($detail !== '') {
                    $remarkEntries->push(['day' => $day, 'label' => $day.'-'.$detail]);
                }
            }

            // Personal locator slips
            foreach ($personalLocators as $l) {
                $day = Carbon::parse($l->travel_date)->day;
                $detail = trim((string) ($l->detail ?? $l->location ?? ''));
                $label = $detail !== '' ? $day.'-Locator ('.$detail.')' : $day.'-Locator';
                $remarkEntries->push(['day' => $day, 'label' => $label]);
            }

            // ETAs - expand each ETA to individual days within the month
            foreach ($empEtas as $eta) {
                $dest = trim((string) ($eta->destination ?? $eta->purpose ?? ''));
                $start = Carbon::parse($eta->departure_date)->startOfDay();
                $end = Carbon::parse($eta->arrival_date)->startOfDay();
                $monthStart = Carbon::createFromDate($year, $month, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $cursor = $start->lt($monthStart) ? $monthStart->copy() : $start->copy();
                $until = $end->gt($monthEnd) ? $monthEnd->copy() : $end->copy();

                while ($cursor->lte($until)) {
                    $day = $cursor->day;
                    $label = $dest !== '' ? $day.'-ETA ('.$dest.')' : $day.'-ETA';
                    $remarkEntries->push(['day' => $day, 'label' => $label]);
                    $cursor->addDay();
                }
            }

            // Excused days
            foreach ($empExcusesByDate as $dateStr => $excuse) {
                $day = Carbon::parse($dateStr)->day;

                $typeLabel = match ($excuse->excuse_type) {
                    'power_interruption' => 'Power Interruption',
                    'system_failure' => 'System Failure',
                    'weather_disturbance' => 'Weather Disturbance',
                    'emergency' => 'Emergency',
                    default => 'Other',
                };

                if ($excuse->is_full_day) {
                    $scope = 'Full Day';
                } else {
                    $slots = [];
                    if ($excuse->excuse_am_in) {
                        $slots[] = 'AM In';
                    }
                    if ($excuse->excuse_am_out) {
                        $slots[] = 'AM Out';
                    }
                    if ($excuse->excuse_pm_in) {
                        $slots[] = 'PM In';
                    }
                    if ($excuse->excuse_pm_out) {
                        $slots[] = 'PM Out';
                    }
                    $scope = $slots ? implode(', ', $slots) : '';
                }

                $parts = array_filter([$typeLabel, $scope, trim((string) ($excuse->reason ?? ''))]);
                $remarkEntries->push(['day' => $day, 'label' => $day.'-Excused: '.implode(' | ', $parts)]);
            }

            // Uniform violations
            foreach ($empViolations as $v) {
                $day = $v->inspection?->inspection_date?->day;
                if (! $day) {
                    continue;
                }
                $label = $day.'-Uniform Violation ('.$v->violation_type.')';
                if (! empty($v->remarks)) {
                    $label .= ': '.$v->remarks;
                }
                $remarkEntries->push(['day' => $day, 'label' => $label]);
            }

            // Office orders - expand each order to individual days within the month
            foreach ($officeOrdersByEmpNo->get($emp->EmpNo, collect()) as $oo) {
                if (! $oo->issued_date) {
                    continue;
                }
                $start = Carbon::parse($oo->issued_date)->startOfDay();
                $end = Carbon::parse($oo->effective_date ?? $oo->issued_date)->startOfDay();
                $start = $start->lt($periodStartDate) ? $periodStartDate->copy() : $start;
                $end = $end->gt($periodEndDate) ? $periodEndDate->copy() : $end;
                for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
                    $day = $cursor->day;
                    $remarkEntries->push(['day' => $day, 'label' => $day.'-Office Order']);
                }
            }

            // Travel orders - expand each order to individual days within the month
            foreach ($travelOrdersByEmpNo->get($emp->EmpNo, collect()) as $to) {
                if (! $to->start_date || ! $to->end_date) {
                    continue;
                }
                $start = Carbon::parse($to->start_date)->startOfDay();
                $end = Carbon::parse($to->end_date)->startOfDay();
                $start = $start->lt($periodStartDate) ? $periodStartDate->copy() : $start;
                $end = $end->gt($periodEndDate) ? $periodEndDate->copy() : $end;
                for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
                    $day = $cursor->day;
                    $remarkEntries->push(['day' => $day, 'label' => $day.'-Travel Order No. '.$to->travel_order_num]);
                }
            }

            $fullRemarks = $remarkEntries
                ->sortBy('day')
                ->pluck('label')
                ->implode(', ');

            $name = trim(implode(', ', array_filter([
                $emp->last_name ?? '',
                implode(' ', array_filter([$emp->first_name ?? '', $emp->middle_name ?? ''])),
            ])));
            if (empty($name)) {
                $name = $emp->name ?? '';
            }

            return [
                'user_id' => $emp->id,
                'emp_no' => $emp->EmpNo,
                'department' => $emp->department->Dept_name ?? '',
                'name' => $name,
                'position' => $emp->designation ?? $emp->position ?? '',
                'employee_type' => $emp->employee_type ?? '',
                'is_exempt' => (bool) $emp->dtr_exempt,
                'undertime_count' => $undertimeCount,
                'tardiness_count' => $tardinessCount,
                'unfiled_count' => $unfiledCount,
                'unfiled_leave_no_data' => $unfiledLeaveNoData,
                'official_leave_count' => $officialLeaveCount,
                'unofficial_exit_count' => $unofficialExitCount,
                'unofficial_exit_no_data' => $unofficialExitNoData,
                'total_minutes' => $totalMinutes,
                'tardiness_minutes' => $tardinessMinutes,
                'undertime_minutes' => $undertimeMinutes,
                'personal_locator_minutes' => $personalLocatorMinutes,
                'remarks' => $fullRemarks,
            ];
        });
    }

    private function buildFullName(?User $user): string
    {
        if (! $user) {
            return '';
        }
        $parts = array_filter([
            $user->first_name ?? '',
            $user->middle_name ?? '',
            $user->last_name ?? '',
        ]);

        return implode(' ', $parts) ?: ($user->name ?? '');
    }

    /**
     * Build the spreadsheet object without streaming it.
     * Pass $actor explicitly when calling from a queue job (Auth::user() won't work there).
     *
     * @param  Collection<int, Department>  $departments
     * @return array{0: Spreadsheet, 1: string} [spreadsheet, filename]
     */
    public function buildSpreadsheet(Collection $departments, int $month, int $year, ?User $actor = null, ?string $employeeType = null): array
    {
        $actor ??= Auth::user();
        $deptName = $departments->count() > 3
            ? 'All Departments'
            : $departments->pluck('Dept_name')->filter()->implode(' / ');
        $rows = $this->getRows($departments, $month, $year, $employeeType);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Attendance Monitoring Matrix')
            ->setSubject($deptName)
            ->setCreator('HRIS');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Matrix');

        // Print-ready: landscape, Folio (8.5" x 13"), narrow margins, and fit all
        // columns to one page wide so the matrix prints cleanly.
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_FOLIO)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()
            ->setTop(0.75)->setBottom(0.75)
            ->setLeft(0.25)->setRight(0.25)
            ->setHeader(0.3)->setFooter(0.3);

        $monthLabel = Carbon::createFromDate($year, $month, 1)->format('F Y');

        // Row 1: Department name
        $sheet->mergeCells('A1:K1');
        $sheet->setCellValue('A1', strtoupper($deptName));
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(20);

        // Row 2: Report subtitle
        $sheet->mergeCells('A2:K2');
        $sheet->setCellValue('A2', strtoupper($deptName).' CGC Employees\' Attendance, Leave and Locator Monitoring Matrix');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);

        // Row 3: Month label
        $sheet->mergeCells('A3:K3');
        $sheet->setCellValue('A3', 'For the Month of: '.$monthLabel);
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(16);

        // Row 4: Column headers
        $headers = [
            'A4' => '#',
            'B4' => 'NAME',
            'C4' => 'POSITION',
            'D4' => "NO. OF\nUNDER-\nTIME",
            'E4' => "NO. OF\nTARDI-\nNESS",
            'F4' => "NO. OF\nUNFILED\nLEAVE",
            'G4' => "NO. OF\nDAYS\nABSENT W/\nOFFICIAL\nLEAVE",
            'H4' => "NO. OF\nDAYS\nABSENT W/\nUN-\nOFFICIAL\nEXIT",
            'I4' => "NO. OF\nMINUTES /\nTARDINESS/\nUNDER-\nTIME\nTIME",
            'J4' => "NO. OF\nMINUTES ON\nLOCATOR\n(PERSONAL)",
            'K4' => 'REMARKS',
        ];

        $headerStyle = [
            'font' => ['bold' => true, 'size' => 8],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'BDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];

        foreach ($headers as $cell => $label) {
            $sheet->setCellValue($cell, $label);
            $sheet->getStyle($cell)->applyFromArray($headerStyle);
        }
        $sheet->getRowDimension(4)->setRowHeight(72);

        $dataStyle = [
            'font' => ['size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];
        $nameStyle = array_merge($dataStyle, ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]]);
        $remarksStyle = array_merge($dataStyle, ['font' => ['size' => 8], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]]);

        $rowNum = 5;
        foreach ($rows as $i => $row) {
            $sheet->setCellValue("A{$rowNum}", $i + 1);
            $sheet->setCellValue("B{$rowNum}", $row['name']);
            $sheet->setCellValue("C{$rowNum}", $row['position']);
            // DTR-derived columns read "EXEMPT" for biometric/DTR-exempt employees
            // (they have no DTR), while leave/locator columns stay populated.
            $sheet->setCellValue("D{$rowNum}", $row['is_exempt'] ? 'EXEMPT' : ($row['undertime_count'] ?: 0));
            $sheet->setCellValue("E{$rowNum}", $row['is_exempt'] ? 'EXEMPT' : ($row['tardiness_count'] ?: 0));
            $sheet->setCellValue("F{$rowNum}", $row['is_exempt']
                ? 'EXEMPT'
                : ($row['unfiled_leave_no_data'] ? 'NO DTR DATA' : ($row['unfiled_count'] ?: 0)));
            $sheet->setCellValue("G{$rowNum}", $row['official_leave_count'] ?: 0);
            $sheet->setCellValue("H{$rowNum}", $row['is_exempt']
                ? 'EXEMPT'
                : ($row['unofficial_exit_no_data'] ? 'NO DTR DATA' : ($row['unofficial_exit_count'] ?: 0)));
            $sheet->setCellValue("I{$rowNum}", $row['is_exempt'] ? 'EXEMPT' : ($row['total_minutes'] ?: 0));
            $sheet->setCellValue("J{$rowNum}", $row['personal_locator_minutes'] ?: 0);
            $sheet->setCellValue("K{$rowNum}", $row['remarks']);

            $sheet->getStyle("A{$rowNum}:J{$rowNum}")->applyFromArray($dataStyle);
            $sheet->getStyle("B{$rowNum}")->applyFromArray($nameStyle);
            $sheet->getStyle("K{$rowNum}")->applyFromArray($remarksStyle);
            $sheet->getRowDimension($rowNum)->setRowHeight(-1);

            $rowNum++;
        }

        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getColumnDimension('C')->setWidth(14);
        foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(10);
        }
        $sheet->getColumnDimension('K')->setWidth(42);
        $sheet->freezePane('A5');

        // --- Signature block ---
        $aoName = $this->buildFullName($actor);
        $aoDesignation = $actor->designation ?? $actor->position ?? 'Administrative Officer';

        $dept = $departments->first();
        $deptHeadName = '';
        $deptHeadDesig = '';
        // Only resolve a single "department head" signatory when the export
        // covers exactly one department (e.g. Administrative Officer) - a
        // company-wide, multi-department export (e.g. Time Keeper) has no
        // single department head to attribute the "Approved by" line to.
        if ($departments->count() === 1 && $dept && ! empty($dept->EmpNo) && $dept->EmpNo !== 'UNASSIGNED') {
            $head = User::where('EmpNo', $dept->EmpNo)->first();
            if ($head) {
                $deptHeadName = $this->buildFullName($head);
                $deptHeadDesig = $head->designation ?? $head->position ?? 'Department Head';
            }
        }

        $sigRow = $rowNum + 1; // one blank row after last data row

        $labelStyle = [
            'font' => ['size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $sigNameStyle = [
            'font' => ['bold' => true, 'size' => 10, 'underline' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $sigDesigStyle = [
            'font' => ['size' => 9, 'italic' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];

        // "Prepared by:" label
        $sheet->mergeCells("B{$sigRow}:D{$sigRow}");
        $sheet->setCellValue("B{$sigRow}", 'Prepared by:');
        $sheet->getStyle("B{$sigRow}")->applyFromArray($labelStyle);

        // "Approved by:" label
        $sheet->mergeCells("H{$sigRow}:K{$sigRow}");
        $sheet->setCellValue("H{$sigRow}", 'Approved by:');
        $sheet->getStyle("H{$sigRow}")->applyFromArray($labelStyle);

        // AO name
        $sheet->mergeCells('B'.($sigRow + 1).':D'.($sigRow + 1));
        $sheet->setCellValue('B'.($sigRow + 1), strtoupper($aoName));
        $sheet->getStyle('B'.($sigRow + 1))->applyFromArray($sigNameStyle);

        // Dept head name
        $sheet->mergeCells('H'.($sigRow + 1).':K'.($sigRow + 1));
        $sheet->setCellValue('H'.($sigRow + 1), strtoupper($deptHeadName));
        $sheet->getStyle('H'.($sigRow + 1))->applyFromArray($sigNameStyle);

        // AO designation
        $sheet->mergeCells('B'.($sigRow + 2).':D'.($sigRow + 2));
        $sheet->setCellValue('B'.($sigRow + 2), $aoDesignation);
        $sheet->getStyle('B'.($sigRow + 2))->applyFromArray($sigDesigStyle);

        // Dept head designation
        $sheet->mergeCells('H'.($sigRow + 2).':K'.($sigRow + 2));
        $sheet->setCellValue('H'.($sigRow + 2), $deptHeadDesig);
        $sheet->getStyle('H'.($sigRow + 2))->applyFromArray($sigDesigStyle);

        foreach ([$sigRow, $sigRow + 1, $sigRow + 2] as $r) {
            $sheet->getRowDimension($r)->setRowHeight(18);
        }

        $typeLabel = $employeeType ? ucwords(str_replace('-', ' ', $employeeType)) : 'All';
        $typeSafe = preg_replace('/[^A-Za-z0-9]+/', '_', $typeLabel) ?: 'All';
        $filename = 'Monitoring-Matrix-'.$monthLabel.'-'.$typeSafe.'-'.now()->format('Ymd-His').'.xlsx';

        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'monitoring_matrix',
                'action' => 'export',
                'target_type' => 'department',
                'target_id' => $departments->first()?->Dept_id,
                'details' => [
                    'month' => $month,
                    'year' => $year,
                    'employee_type' => $employeeType,
                    'departments' => $departments->pluck('Dept_name')->toArray(),
                    'employee_count' => $rows->count(),
                    'filename' => $filename,
                ],
            ]);
        } catch (\Exception) {
            // audit failure must not block the download
        }

        $this->lockSheet($sheet);

        return [$spreadsheet, $filename];
    }

    /**
     * Apply password-protected sheet protection, mirroring Form48ExportService.
     * Every cell built by buildSpreadsheet() is locked by default (a fresh
     * Spreadsheet's default cell style, not a template with pre-unlocked
     * input cells), so no cell-level unlocking pass is needed.
     */
    private function lockSheet(Worksheet $sheet): void
    {
        $s = Setting::first();
        $enabled = $s?->excel_protection_enabled ?? (bool) env('EXCEL_EXPORT_PROTECTION_ENABLED', true);
        if (! $enabled) {
            return;
        }
        $password = $s?->excel_sheet_password ?? env('EXCEL_EXPORT_SHEET_PASSWORD', '');
        $sheet->getProtection()
            ->setSheet(true)
            ->setPassword((string) $password);
    }

    /**
     * Generate and stream the monthly monitoring matrix Excel file.
     *
     * @param  Collection<int, Department>  $departments
     */
    public function generateExcelResponse(Collection $departments, int $month, int $year, ?string $employeeType = null): StreamedResponse
    {
        [$spreadsheet, $filename] = $this->buildSpreadsheet($departments, $month, $year, employeeType: $employeeType);

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
