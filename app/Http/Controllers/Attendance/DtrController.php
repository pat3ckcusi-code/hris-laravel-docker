<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\EmployeeShiftSchedule;
use App\Models\Eta;
use App\Models\LeaveDate;
use App\Models\Locator;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Services\DepartmentService;
use App\Services\DtrPunchResolver;
use App\Services\Form48ExportService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DtrController extends Controller
{
    private const ADMIN_ROLES = ['hr manager', 'payroll manager', 'time keeper', 'records manager'];

    public function __construct(
        private DepartmentService $departmentService,
        private DtrPunchResolver $punchResolver,
    ) {}

    // ── PERIOD HELPERS ────────────────────────────────────────────────────────

    /**
     * Compute [from, to] date strings from a YYYY-MM month, DTR type, and half.
     *
     * @return array{0: string, 1: string}
     */
    private function resolvePeriod(string $month, string $dtrType, int $period = 1): array
    {
        $carbon = Carbon::parse($month.'-01');

        if ($dtrType === 'semi-monthly') {
            if ($period === 1) {
                return [$carbon->format('Y-m-d'), $carbon->copy()->setDay(15)->format('Y-m-d')];
            }

            return [
                $carbon->copy()->setDay(16)->format('Y-m-d'),
                $carbon->copy()->endOfMonth()->format('Y-m-d'),
            ];
        }

        return [$carbon->format('Y-m-d'), $carbon->copy()->endOfMonth()->format('Y-m-d')];
    }

    /**
     * Badge for the eight punch-path AttendanceStatus values written by the
     * new engine. Any legacy/unknown value (old rows stored plain 'present'
     * before this column carried richer statuses) falls through to Present.
     */
    private function punchStatusBadge(?string $status): string
    {
        return match ($status) {
            'late' => '<span class="hris-badge" style="background:#fee2e2;color:#991b1b;">Late</span>',
            'undertime' => '<span class="hris-badge" style="background:#ffedd5;color:#9a3412;">Undertime</span>',
            'half_day_am' => '<span class="hris-badge" style="background:#e0f2fe;color:#075985;">Half Day AM</span>',
            'half_day_pm' => '<span class="hris-badge" style="background:#e0f2fe;color:#075985;">Half Day PM</span>',
            'missing_in' => '<span class="hris-badge" style="background:#fef3c7;color:#92400e;">Missing IN</span>',
            'missing_out' => '<span class="hris-badge" style="background:#fef3c7;color:#92400e;">Missing OUT</span>',
            'incomplete' => '<span class="hris-badge" style="background:#f3e8ff;color:#6b21a8;">Incomplete Logs</span>',
            default => '<span class="hris-badge badge-approved">Present</span>',
        };
    }

    /** Human-readable label written into the Form 48 "For the Month of" cells. */
    private function resolveMonthYearLabel(string $from, string $to, string $dtrType): string
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);

        if ($dtrType === 'semi-monthly') {
            return $f->format('F').' '.$f->day.'–'.$t->day.', '.$f->year;
        }

        return $f->format('F Y');
    }

    /**
     * Resolve the acting user's access tier.
     *
     * Returns:
     *   isAdmin   - full cross-department access (ADMIN_ROLES)
     *   isOfficer - department head or administrative officer: admin-like UI scoped to their department(s)
     *   deptId    - first dept ID (for view backward compat); null for full admins and plain employees
     *   deptIds   - all dept IDs the officer manages; empty for admins/employees
     *
     * Aborts 403 for any role outside these three tiers.
     *
     * @return array{isAdmin: bool, isOfficer: bool, deptId: int|null, deptIds: int[]}
     */
    private function resolveContext(User $user): array
    {
        $role = strtolower(trim((string) ($user->access_level ?? '')));
        $isAdmin = in_array($role, self::ADMIN_ROLES, true);
        $isOfficer = in_array($role, ['administrative officer', 'department head'], true);

        if (! $isAdmin && ! $isOfficer && ! in_array($role, ['employee', 'leave manager'], true)) {
            abort(403);
        }

        $deptIds = [];
        if ($isOfficer) {
            $roleNormalized = strtolower(str_replace(['-', '_'], ' ', trim((string) ($user->access_level ?? ''))));
            $deptCollection = ($roleNormalized === 'administrative officer')
                ? $this->departmentService->resolveAllDepartmentsForAdminOfficer($user)
                : $this->departmentService->resolveAllDepartmentsForUser($user);
            $deptIds = $deptCollection->pluck('Dept_id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->values()
                ->toArray();
        }

        return [
            'isAdmin' => $isAdmin,
            'isOfficer' => $isOfficer,
            'deptId' => $deptIds[0] ?? null,
            'deptIds' => $deptIds,
        ];
    }

    // ── LIST VIEW ─────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptId' => $officerDeptId, 'deptIds' => $officerDeptIds] = $this->resolveContext($user);

        if ($isAdmin) {
            $departments = Department::orderBy('Dept_name')->get();
            $employees = User::active()->where('dtr_exempt', false)
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'employee_type']);
            $officerDepts = collect();
            $officerDept = null;
        } elseif ($isOfficer) {
            $departments = collect();
            $officerDepts = Department::whereIn('Dept_id', $officerDeptIds)->orderBy('Dept_name')->get();
            $officerDept = $officerDepts->firstWhere('Dept_id', $officerDeptId);
            $employees = User::active()->whereIn('Dept_id', $officerDeptIds)
                ->where('dtr_exempt', false)
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'employee_type']);
        } else {
            $departments = collect();
            $officerDepts = collect();
            $employees = collect();
            $officerDept = null;
        }

        return view('attendance.dtr.index', compact(
            'isAdmin', 'isOfficer', 'departments', 'employees', 'officerDeptId', 'officerDept', 'officerDepts'
        ));
    }

    // ── DATATABLE AJAX DATA ───────────────────────────────────────────────────

    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptIds' => $officerDeptIds] = $this->resolveContext($user);

        $periodRules = [
            'dtr_type' => ['required', 'in:monthly,semi-monthly'],
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'period' => ['nullable', 'in:1,2'],
        ];

        if ($isAdmin || $isOfficer) {
            $request->validate(array_merge($periodRules, [
                'employee_id' => ['required', 'integer', 'exists:users,id'],
            ]));
            $employee = User::findOrFail($request->integer('employee_id'));

            // Officers are locked to their managed departments - reject cross-dept requests.
            if ($isOfficer && ! in_array((int) $employee->Dept_id, $officerDeptIds)) {
                abort(403, 'You may only view DTR records for employees in your own department.');
            }
        } else {
            $request->validate($periodRules);
            $employee = $user;
        }

        $draw = $request->integer('draw', 1);

        // Exempt employees keep no DTR - surface the exempt state instead of rows.
        if ($employee->dtr_exempt) {
            return response()->json([
                'draw' => $draw,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'exempt' => true,
            ]);
        }

        [$from, $to] = $this->resolvePeriod(
            $request->input('month'),
            $request->input('dtr_type'),
            $request->integer('period', 1)
        );

        // Fetch biometric/manual DTR rows for the period.
        $dtrRows = Dtr::where('employee_id', $employee->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();

        // Effective shift for this employee as of the period start (template or
        // global standard day). Kept for any context that doesn't need per-date
        // resolution (e.g. headers).
        $schedule = WorkSchedule::forUserOnDate($employee, Carbon::parse($from));

        // Pre-load per-date shift assignments so each DTR row can use its own schedule.
        $shiftAssignments = EmployeeShiftSchedule::where('user_id', $employee->id)
            ->whereBetween('date', [$from, $to])
            ->with('shift')
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        // Warm the per-request ShiftAssignment-history memo once, so every
        // forUserOnDate()/isWorkday() call below (both the existing DTR-row
        // loop and the uncovered-day pass) reuses it instead of re-querying
        // ShiftAssignment per date.
        WorkSchedule::preloadShiftAssignments([$employee->id]);

        // Build leave map: date string → ['code' => leave code, 'days' => decimal]
        // (approved, non-cancelled). 'days' < 1 means a half-day leave, which must
        // not hide real punches for the half of the day actually worked - see the
        // per-slot fallback below.
        $leaveMap = LeaveDate::query()
            ->join('leave_requests', 'leave_dates.leave_request_id', '=', 'leave_requests.id')
            ->where('leave_requests.user_id', $employee->id)
            ->where('leave_requests.status', 'approved')
            ->where('leave_dates.is_cancelled', false)
            ->whereBetween('leave_dates.leave_date', [$from, $to])
            ->select('leave_dates.leave_date', 'leave_dates.is_lwop', 'leave_dates.days', 'leave_requests.leave_type', 'leave_requests.details_others_type')
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->leave_date)->format('Y-m-d'))
            ->map(fn ($r) => [
                'code' => $r->is_lwop ? 'LWOP' : Form48ExportService::toLeaveCode($r->leave_type, $r->details_others_type),
                'days' => (float) $r->days,
            ]);

        // Build ETA date set: date string → true for all days covered by approved ETA.
        $etaDateSet = [];
        Eta::where('user_id', $employee->id)
            ->where('status', 'approved')
            ->where('departure_date', '<=', $to)
            ->where(function ($q) use ($from): void {
                $q->whereNull('arrival_date')->orWhere('arrival_date', '>=', $from);
            })
            ->get(['departure_date', 'arrival_date'])
            ->each(function ($eta) use (&$etaDateSet, $from, $to): void {
                $start = Carbon::parse($eta->departure_date)->max(Carbon::parse($from));
                $end = $eta->arrival_date
                    ? Carbon::parse($eta->arrival_date)->min(Carbon::parse($to))
                    : Carbon::parse($eta->departure_date);
                for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                    $etaDateSet[$d->format('Y-m-d')] = true;
                }
            });

        // Build locator date map: 'Y-m-d' → slot coverage flags for approved locators.
        $locatorDateMap = [];
        Locator::where('user_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$from, $to])
            ->get(['travel_date', 'intended_departure_time', 'intended_arrival_time'])
            ->each(function ($locator) use (&$locatorDateMap, $employee, $shiftAssignments): void {
                $dateStr = Carbon::parse($locator->travel_date)->format('Y-m-d');
                $dep = substr((string) $locator->intended_departure_time, 0, 5);
                $arr = substr((string) $locator->intended_arrival_time, 0, 5);
                $locatorSchedule = WorkSchedule::forUserOnDate($employee, Carbon::parse($locator->travel_date), $shiftAssignments);

                $cur = $locatorDateMap[$dateStr] ?? [
                    'covers_am_in' => false, 'covers_am_out' => false,
                    'covers_pm_in' => false, 'covers_pm_out' => false,
                    'departure' => $dep,  'arrival' => $arr,
                ];
                foreach (Locator::coveredSlotKeys($dep, $arr, $locatorSchedule) as $slotKey) {
                    $cur["covers_{$slotKey}"] = true;
                }
                $cur['departure'] = min($cur['departure'], $dep);
                $cur['arrival'] = max($cur['arrival'], $arr);
                $locatorDateMap[$dateStr] = $cur;
            });

        // Build excuse map: 'Y-m-d' → DtrExcuse for the period.
        $excuseMap = DtrExcuse::where('user_id', $employee->id)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->keyBy(fn ($e) => Carbon::parse($e->date)->format('Y-m-d'));

        // Build work-suspension map: 'Y-m-d' → WorkSuspension for the period,
        // same shape as excuseMap - see WorkSchedule::applySuspension().
        $suspensionMap = WorkSuspension::whereBetween('suspension_date', [$from, $to])
            ->get()
            ->keyBy(fn ($s) => Carbon::parse($s->suspension_date)->format('Y-m-d'));

        // Build office-order date map: 'Y-m-d' → office_order_num, expanding each
        // order to every day from issued_date through effective_date (or just
        // issued_date if effective_date isn't set), clamped to the period.
        $officeOrderDateMap = [];
        if ($employee->EmpNo) {
            $rangeStart = Carbon::parse($from);
            $rangeEnd = Carbon::parse($to);

            DB::table('office_orders')
                ->join('office_order_employees', 'office_orders.id', '=', 'office_order_employees.office_order_id')
                ->where('office_order_employees.emp_no', $employee->EmpNo)
                ->where('office_orders.issued_date', '<=', $to)
                ->where(function ($q) use ($from): void {
                    $q->where('office_orders.effective_date', '>=', $from)
                        ->orWhere(function ($q2) use ($from): void {
                            $q2->whereNull('office_orders.effective_date')
                                ->where('office_orders.issued_date', '>=', $from);
                        });
                })
                ->select('office_orders.office_order_num', 'office_orders.effective_date', 'office_orders.issued_date')
                ->get()
                ->each(function ($o) use (&$officeOrderDateMap, $rangeStart, $rangeEnd): void {
                    if (! $o->issued_date) {
                        return;
                    }
                    $cursor = Carbon::parse($o->issued_date)->startOfDay();
                    $until = Carbon::parse($o->effective_date ?? $o->issued_date)->startOfDay();
                    $cursor = $cursor->lt($rangeStart) ? $rangeStart->copy() : $cursor;
                    $until = $until->gt($rangeEnd) ? $rangeEnd->copy() : $until;
                    for (; $cursor->lte($until); $cursor->addDay()) {
                        $officeOrderDateMap[$cursor->format('Y-m-d')] = $o->office_order_num;
                    }
                });
        }

        // Build travel-order date map: 'Y-m-d' => travel_order_num, for every day
        // covered by an *approved* Travel Order, clamped to the period. Unlike
        // Office Order's nullable effective_date, start_date/end_date are always
        // non-null, so no null-fallback branch is needed - a plain overlap test.
        $travelOrderDateMap = [];
        if ($employee->EmpNo) {
            $rangeStart = Carbon::parse($from);
            $rangeEnd = Carbon::parse($to);

            DB::table('travel_orders')
                ->join('travel_order_employees', 'travel_orders.id', '=', 'travel_order_employees.travel_order_id')
                ->where('travel_order_employees.emp_no', $employee->EmpNo)
                ->where('travel_orders.status', 'Approved')
                ->where('travel_orders.start_date', '<=', $to)
                ->where('travel_orders.end_date', '>=', $from)
                ->select('travel_orders.travel_order_num', 'travel_orders.start_date', 'travel_orders.end_date')
                ->get()
                ->each(function ($order) use (&$travelOrderDateMap, $rangeStart, $rangeEnd): void {
                    $cursor = Carbon::parse($order->start_date)->startOfDay();
                    $until = Carbon::parse($order->end_date)->startOfDay();
                    $cursor = $cursor->lt($rangeStart) ? $rangeStart->copy() : $cursor;
                    $until = $until->gt($rangeEnd) ? $rangeEnd->copy() : $until;
                    for (; $cursor->lte($until); $cursor->addDay()) {
                        $travelOrderDateMap[$cursor->format('Y-m-d')] = $order->travel_order_num;
                    }
                });
        }

        // Dates already covered by a DTR row.
        $dtrDates = $dtrRows->map(fn (Dtr $d) => Carbon::parse($d->date)->format('Y-m-d'))->flip()->toArray();

        $data = collect();

        foreach ($dtrRows as $dtr) {
            $dateStr = Carbon::parse($dtr->date)->format('Y-m-d');
            $rowSchedule = WorkSchedule::forUserOnDate($employee, Carbon::parse($dtr->date), $shiftAssignments);

            // A declared work suspension caps the effective schedule the same
            // way it did when this row was originally resolved/stored (see
            // PersonnelLogImportService), so imputed late/undertime and the
            // "shift ended" gate below stay consistent with the stored values.
            $suspensionRow = $suspensionMap[$dateStr] ?? null;
            $suspensionSlots = [];
            if ($suspensionRow !== null && ! $employee->isFrontlineExempt()) {
                [$rowSchedule, $suspensionSlots] = $rowSchedule->applySuspension($suspensionRow->suspension_time);
            }

            $leaveEntry = $leaveMap[$dateStr] ?? null;
            $leaveCode = $leaveEntry['code'] ?? null;
            $isFullDayLeave = ($leaveEntry['days'] ?? 1.0) >= 1.0;
            $isEtaDay = ! $leaveCode && isset($etaDateSet[$dateStr]);

            $etaPunchCount = $isEtaDay ? count(array_filter([
                $dtr->time_in_am, $dtr->time_out_am,
                $dtr->time_in_pm, $dtr->time_out_pm,
            ], fn ($v) => $v !== null && $v !== '')) : 4;

            $showEta = $isEtaDay && $etaPunchCount < 4;

            // Office Order: same priority as ETA -sits between ETA and Excuse.
            $isOoDay = ! $leaveCode && ! $isEtaDay && isset($officeOrderDateMap[$dateStr]);
            $ooNum = $isOoDay ? $officeOrderDateMap[$dateStr] : null;
            $ooPunchCount = $isOoDay ? count(array_filter([
                $dtr->time_in_am, $dtr->time_out_am,
                $dtr->time_in_pm, $dtr->time_out_pm,
            ], fn ($v) => $v !== null && $v !== '')) : 4;
            $showOo = $isOoDay && $ooPunchCount < 4;

            // Travel Order: same priority tier as Office Order, sitting right
            // after it - both represent "away on official business" and Travel
            // Order structurally mirrors Office Order elsewhere in this codebase.
            $isToDay = ! $leaveCode && ! $isEtaDay && ! $isOoDay && isset($travelOrderDateMap[$dateStr]);
            $toNum = $isToDay ? $travelOrderDateMap[$dateStr] : null;
            $toPunchCount = $isToDay ? count(array_filter([
                $dtr->time_in_am, $dtr->time_out_am,
                $dtr->time_in_pm, $dtr->time_out_pm,
            ], fn ($v) => $v !== null && $v !== '')) : 4;
            $showTo = $isToDay && $toPunchCount < 4;

            // Excuse, suspension, and locator apply only when leave, ETA, OO, and TO do
            // not take priority. A suspension with no excluded slots (the
            // capped-workEnd-only tier) has nothing to badge/decorate here - it
            // falls through to the plain branch below, which already uses the
            // capped $rowSchedule.
            $excuse = (! $leaveCode && ! $isEtaDay && ! $isOoDay && ! $isToDay) ? ($excuseMap[$dateStr] ?? null) : null;
            $suspension = (! $leaveCode && ! $isEtaDay && ! $isOoDay && ! $isToDay && ! $excuse && ! empty($suspensionSlots)) ? $suspensionRow : null;
            $loc = (! $leaveCode && ! $isEtaDay && ! $isOoDay && ! $isToDay && ! $excuse && ! $suspension) ? ($locatorDateMap[$dateStr] ?? null) : null;

            // Field Work/WFH: lowest priority of all - only when nothing else above
            // (leave/ETA/OO/TO/excuse/suspension/locator) already explains the date,
            // matching Form48ExportService's own field_work/wfh priority so the two
            // pages agree. Reuses $shiftAssignments (already loaded for schedule
            // resolution) rather than a new query - same source the "no dtrs row at
            // all" synthetic loop below reads from.
            $fieldWorkWfhAssignment = $shiftAssignments[$dateStr] ?? null;
            $isFieldWorkWfhDay = ! $leaveCode && ! $isEtaDay && ! $isOoDay && ! $isToDay
                && ! $excuse && ! $suspension && ! $loc
                && $fieldWorkWfhAssignment !== null
                && $fieldWorkWfhAssignment->shift_id === null
                && in_array($fieldWorkWfhAssignment->type, ['field_work', 'wfh'], true);

            $fieldWorkWfhPunchCount = $isFieldWorkWfhDay ? count(array_filter([
                $dtr->time_in_am, $dtr->time_out_am,
                $dtr->time_in_pm, $dtr->time_out_pm,
            ], fn ($v) => $v !== null && $v !== '')) : 4;

            $showFieldWorkWfh = $isFieldWorkWfhDay && $fieldWorkWfhPunchCount < 4;

            // Missing AM In / PM Out with nothing else explaining the gap - impute the
            // full half-day block from the shift template, mirroring the Monitoring
            // Matrix report's "unofficial exit" undertime rule (AttendanceMonitoringExportService).
            $amInImputed = false;
            $imputeAmInLate = function () use ($dtr, $rowSchedule, $dateStr, &$amInImputed): int {
                $mins = $this->punchResolver->imputedLateMinutes($dtr->time_in_am, $dtr->time_out_am, $dateStr, $rowSchedule);
                $amInImputed = $mins > 0;

                return $mins;
            };
            $pmOutImputed = false;
            $imputePmOutUndertime = function () use ($dtr, $rowSchedule, $dateStr, &$pmOutImputed): int {
                $mins = $this->punchResolver->imputedUndertimeMinutes($dtr->time_in_pm, $dtr->time_out_pm, $dateStr, $rowSchedule);
                $pmOutImputed = $mins > 0;

                return $mins;
            };

            // Resolve effective display values for each slot.
            $coversAmIn = $coversAmOut = $coversPmIn = $coversPmOut = false;
            if ($leaveCode) {
                // A full-day leave overrides every slot regardless of any incidental
                // punch (deliberate - leave takes priority for the whole day, same as
                // Form48ExportService's own leave branch). A half-day leave (days < 1)
                // only covers whichever slots have no real punch, so the half actually
                // worked shows real times instead of being hidden behind the leave code
                // - mirroring the per-slot fallback already used for ETA/OO/TO below,
                // since "which half" isn't stored anywhere and is inferred from the
                // punch data itself.
                $coversAmIn = $isFullDayLeave || empty($dtr->time_in_am);
                $coversAmOut = $isFullDayLeave || empty($dtr->time_out_am);
                $coversPmIn = $isFullDayLeave || empty($dtr->time_in_pm);
                $coversPmOut = $isFullDayLeave || empty($dtr->time_out_pm);
                $tAmIn = $coversAmIn ? $leaveCode : $dtr->time_in_am;
                $tAmOut = $coversAmOut ? $leaveCode : $dtr->time_out_am;
                $tPmIn = $coversPmIn ? $leaveCode : $dtr->time_in_pm;
                $tPmOut = $coversPmOut ? $leaveCode : $dtr->time_out_pm;

                // Recompute per-slot, same as the Locator branch below - a whole-day OR
                // zero-out would hide genuine tardiness/undertime on the half actually
                // worked. For a full-day leave every slot is covered, so these raw
                // inputs are always '' and this correctly collapses to lateMin=utMin=0,
                // matching the prior unconditional behavior.
                $rawAmIn = $coversAmIn ? '' : ($dtr->time_in_am ?? '');
                $rawPmIn = $coversPmIn ? '' : ($dtr->time_in_pm ?? '');
                $rawPmOut = $coversPmOut ? '' : ($dtr->time_out_pm ?? '');
                [$lateMin, $utMin] = Form48ExportService::computeSlotPenalties(
                    $dateStr, $rawAmIn, $rawPmIn, $rawPmOut, $rowSchedule
                );
                if ($lateMin === 0 && ! $coversAmIn) {
                    $lateMin = $imputeAmInLate();
                }
                if ($utMin === 0 && ! $coversPmOut) {
                    $utMin = $imputePmOutUndertime();
                }
            } elseif ($showEta) {
                $tAmIn = $dtr->time_in_am ?: 'ETA';
                $tAmOut = $dtr->time_out_am ?: 'ETA';
                $tPmIn = $dtr->time_in_pm ?: 'ETA';
                $tPmOut = $dtr->time_out_pm ?: 'ETA';
                $lateMin = $utMin = 0;
            } elseif ($showOo) {
                $tAmIn = $dtr->time_in_am ?: 'Office Order';
                $tAmOut = $dtr->time_out_am ?: 'Office Order';
                $tPmIn = $dtr->time_in_pm ?: 'Office Order';
                $tPmOut = $dtr->time_out_pm ?: 'Office Order';
                $lateMin = $utMin = 0;
            } elseif ($showTo) {
                $tAmIn = $dtr->time_in_am ?: 'Travel Order';
                $tAmOut = $dtr->time_out_am ?: 'Travel Order';
                $tPmIn = $dtr->time_in_pm ?: 'Travel Order';
                $tPmOut = $dtr->time_out_pm ?: 'Travel Order';
                $lateMin = $utMin = 0;
            } elseif ($excuse) {
                $coversAmIn = $excuse->excuse_am_in || $excuse->is_full_day;
                $coversAmOut = $excuse->excuse_am_out || $excuse->is_full_day;
                $coversPmIn = $excuse->excuse_pm_in || $excuse->is_full_day;
                $coversPmOut = $excuse->excuse_pm_out || $excuse->is_full_day;
                $tAmIn = $coversAmIn ? ($dtr->time_in_am ?: 'EXCUSED') : ($dtr->time_in_am ?? '-');
                $tAmOut = $coversAmOut ? ($dtr->time_out_am ?: 'EXCUSED') : ($dtr->time_out_am ?? '-');
                $tPmIn = $coversPmIn ? ($dtr->time_in_pm ?: 'EXCUSED') : ($dtr->time_in_pm ?? '-');
                $tPmOut = $coversPmOut ? ($dtr->time_out_pm ?: 'EXCUSED') : ($dtr->time_out_pm ?? '-');
                $storedLate = $dtr->late_minutes ?? 0;
                $lateMin = ($coversAmIn || $coversPmIn) ? 0 : ($storedLate > 0 ? $storedLate : $imputeAmInLate());
                $storedUt = $dtr->undertime_minutes ?? 0;
                $utMin = $coversPmOut ? 0 : ($storedUt > 0 ? $storedUt : $imputePmOutUndertime());
            } elseif ($suspension) {
                $coversAmIn = isset($suspensionSlots['am_in']);
                $coversAmOut = isset($suspensionSlots['am_out']);
                $coversPmIn = isset($suspensionSlots['pm_in']);
                $coversPmOut = isset($suspensionSlots['pm_out']);
                $tAmIn = $coversAmIn ? ($dtr->time_in_am ?: 'SUSPENDED') : ($dtr->time_in_am ?? '-');
                $tAmOut = $coversAmOut ? ($dtr->time_out_am ?: 'SUSPENDED') : ($dtr->time_out_am ?? '-');
                $tPmIn = $coversPmIn ? ($dtr->time_in_pm ?: 'SUSPENDED') : ($dtr->time_in_pm ?? '-');
                $tPmOut = $coversPmOut ? ($dtr->time_out_pm ?: 'SUSPENDED') : ($dtr->time_out_pm ?? '-');
                $storedLate = $dtr->late_minutes ?? 0;
                $lateMin = ($coversAmIn || $coversPmIn) ? 0 : ($storedLate > 0 ? $storedLate : $imputeAmInLate());
                $storedUt = $dtr->undertime_minutes ?? 0;
                $utMin = $coversPmOut ? 0 : ($storedUt > 0 ? $storedUt : $imputePmOutUndertime());
            } elseif ($loc) {
                [$rawAmIn, $rawAmOut, $rawPmIn, $rawPmOut] = Form48ExportService::resolveLocatorSlots(
                    $dtr->time_in_am, $dtr->time_out_am,
                    $dtr->time_in_pm, $dtr->time_out_pm,
                    $loc
                );
                $tAmIn = $rawAmIn ?? '-';
                $tAmOut = $rawAmOut ?? '-';
                $tPmIn = $rawPmIn ?? '-';
                $tPmOut = $rawPmOut ?? '-';
                // Recompute per-slot: the old OR logic zeroed all tardiness whenever
                // any covered slot was LOCATOR, hiding genuine late AM In punches.
                [$lateMin, $utMin] = Form48ExportService::computeSlotPenalties(
                    $dateStr, $rawAmIn ?? '', $rawPmIn ?? '', $rawPmOut ?? '', $rowSchedule
                );
                if ($lateMin === 0 && ! ($loc['covers_am_in'] ?? false)) {
                    $lateMin = $imputeAmInLate();
                }
                if ($utMin === 0 && ! ($loc['covers_pm_out'] ?? false)) {
                    $utMin = $imputePmOutUndertime();
                }
            } elseif ($showFieldWorkWfh) {
                $label = $fieldWorkWfhAssignment->type === 'wfh' ? 'Work From Home' : 'Field Work';
                $tAmIn = $dtr->time_in_am ?: $label;
                $tAmOut = $dtr->time_out_am ?: $label;
                $tPmIn = $dtr->time_in_pm ?: $label;
                $tPmOut = $dtr->time_out_pm ?: $label;
                $lateMin = $utMin = 0;
            } else {
                // A null slot only reads as "Missing" (vs. a plain "-") once its
                // window has passed - a shift still in progress shouldn't accuse
                // an employee of a missing punch that simply hasn't happened yet.
                // No-break schedules only ever expect am_in/pm_out, so am_out/pm_in
                // stay a plain dash regardless (they're not real slots to miss).
                $shiftEnded = Carbon::now()->gte($rowSchedule->referenceDateTime($dateStr, $rowSchedule->workEnd));
                $missing = fn (?string $v, bool $eligible): string => $v ?? ($eligible && $shiftEnded ? 'Missing' : '-');

                // A Field Work-style in_only/out_only day expects exactly one
                // slot - the other three (including am_in/pm_out on the side
                // that's never expected) are never real slots to miss, same
                // treatment as no_break's am_out/pm_in.
                $hasBreakSlots = ! $rowSchedule->noBreak && $rowSchedule->punchRequirement === 'both';
                $amInEligible = $rowSchedule->punchRequirement !== 'out_only';
                $pmOutEligible = $rowSchedule->punchRequirement !== 'in_only';

                $tAmIn = $missing($dtr->time_in_am, $amInEligible);
                $tAmOut = $missing($dtr->time_out_am, $hasBreakSlots);
                $tPmIn = $missing($dtr->time_in_pm, $hasBreakSlots);
                $tPmOut = $missing($dtr->time_out_pm, $pmOutEligible);
                $storedLate = $dtr->late_minutes ?? 0;
                $lateMin = $storedLate > 0 ? $storedLate : $imputeAmInLate();
                $storedUt = $dtr->undertime_minutes ?? 0;
                $utMin = $storedUt > 0 ? $storedUt : $imputePmOutUndertime();
            }

            // Display every punch as HH:MM - some branches above (e.g. Locator)
            // already trim seconds, others still carry the raw HH:MM:SS column
            // value, so normalize here once for a consistent table.
            $fmt = fn (string $v): string => preg_match('/^\d{2}:\d{2}:\d{2}$/', $v) ? substr($v, 0, 5) : $v;
            $tAmIn = $fmt($tAmIn);
            $tAmOut = $fmt($tAmOut);
            $tPmIn = $fmt($tPmIn);
            $tPmOut = $fmt($tPmOut);

            // Per-cell late/undertime flags: only highlight the slot that actually caused the penalty.
            // Using the row-level is_late class to color AM In was wrong when lateness came from PM In.
            $slotHm = fn (string $v): ?string => ! in_array($v, ['-', 'Missing', 'LOCATOR', 'ETA', 'EXCUSED', 'SUSPENDED', 'Field Work', 'Work From Home'], true) && strlen($v) >= 5
                ? substr($v, 0, 5)
                : null;
            $amInHm = $slotHm($tAmIn);
            $pmInHm = $slotHm($tPmIn);
            $pmOutHm = $slotHm($tPmOut);
            $isAmInLate = $amInImputed || ($lateMin > 0 && $amInHm !== null && $amInHm > $rowSchedule->workStart && $amInHm < $rowSchedule->morningEnd);
            $isPmInLate = $lateMin > 0 && $pmInHm !== null && $pmInHm > $rowSchedule->lunchReturn && $pmInHm < $rowSchedule->noonEnd;
            $pmOutLower = $rowSchedule->noBreak ? $rowSchedule->workStart : $rowSchedule->lunchReturn;
            $isPmOutUndertime = $pmOutImputed || ($utMin > 0 && $pmOutHm !== null && $pmOutHm >= $pmOutLower && $pmOutHm < $rowSchedule->workEnd);

            // Decorate excused/suspended slots with the reason so the cause is visible
            // without leaving this page; only applies to slots that are actually covered.
            $decorateSlot = function (string $raw, bool $covered) use ($excuse, $suspension): string {
                if ((! $excuse && ! $suspension) || ! $covered) {
                    return $raw;
                }
                $cfg = $excuse
                    ? DtrExcuse::typeConfig($excuse->excuse_type)
                    : WorkSuspension::typeConfig($suspension->type);
                $tooltip = e(($excuse ? $excuse->reason : $suspension->reason) ?: $cfg['label']);
                $badge = '<span class="hris-badge" style="background:'.$cfg['bg'].';color:'.$cfg['color'].';font-size:.65rem;padding:.15rem .5rem;" title="'.$tooltip.'"><i class="fas '.$cfg['icon'].'" style="font-size:.6rem;"></i> '.$cfg['label'].'</span>';

                if ($raw === 'EXCUSED' || $raw === 'SUSPENDED') {
                    return $badge;
                }

                // Stack the punch time above the badge instead of placing them inline:
                // a narrow auto-sized DataTables column wraps "time badge" onto two
                // independently-centered lines, making the badge look detached/misplaced.
                return '<div style="display:flex;flex-direction:column;align-items:center;gap:.2rem;line-height:1.2;"><span>'.e($raw).'</span>'.$badge.'</div>';
            };

            $statusBadge = $leaveCode
                ? '<span class="hris-badge" style="background:#fef3c7;color:#92400e;">On Leave ('.$leaveCode.')</span>'
                : ($showEta
                    ? '<span class="hris-badge" style="background:#dbeafe;color:#1e40af;">On Official Travel</span>'
                    : ($showOo
                        ? '<span class="hris-badge" style="background:#ede9fe;color:#5b21b6;">Office Order</span>'
                        : ($showTo
                            ? '<span class="hris-badge" style="background:#cffafe;color:#155e75;">Travel Order</span>'
                            : ($excuse
                                ? '<span class="hris-badge" style="background:#fef3c7;color:#92400e;">Excused</span>'
                                : ($suspension
                                    ? (function () use ($suspension) {
                                        $cfg = WorkSuspension::typeConfig($suspension->type);

                                        return '<span class="hris-badge" style="background:'.$cfg['bg'].';color:'.$cfg['color'].';"><i class="fas '.$cfg['icon'].'" style="font-size:.65rem;"></i> '.$cfg['label'].'</span>';
                                    })()
                                    : ($loc
                                        ? '<span class="hris-badge" style="background:#d1fae5;color:#065f46;">Locator</span>'
                                        : ($showFieldWorkWfh
                                            ? ($fieldWorkWfhAssignment->type === 'wfh'
                                                ? '<span class="hris-badge" style="background:#eff6ff;color:#1d4ed8;">Work From Home</span>'
                                                : '<span class="hris-badge" style="background:#f0fdf4;color:#15803d;">Field Work</span>')
                                            : ($dtr->is_absent
                                                ? '<span class="hris-badge badge-rejected">Absent</span>'
                                                : $this->punchStatusBadge($dtr->status)))))))));

            if (! empty($dtr->unmatched_logs)) {
                $unmatchedTitle = e(implode(', ', array_map(fn ($t) => substr((string) $t, 0, 5), $dtr->unmatched_logs)));
                $statusBadge .= ' <span class="hris-badge" style="background:#fef9c3;color:#854d0e;" title="Unreconciled punch(es): '.$unmatchedTitle.'">&#9888; '.count($dtr->unmatched_logs).'</span>';
            }

            $data->push([
                'date' => Carbon::parse($dtr->date)->format('M d, Y (D)'),
                'time_in_am' => $decorateSlot($tAmIn, $coversAmIn),
                'time_out_am' => $decorateSlot($tAmOut, $coversAmOut),
                'time_in_pm' => $decorateSlot($tPmIn, $coversPmIn),
                'time_out_pm' => $decorateSlot($tPmOut, $coversPmOut),
                'time_in_ot' => $dtr->time_in_ot ? substr($dtr->time_in_ot, 0, 5) : '-',
                'time_out_ot' => $dtr->time_out_ot ? substr($dtr->time_out_ot, 0, 5) : '-',
                'late_minutes' => $lateMin,
                'undertime_minutes' => $utMin,
                'hours_worked' => $dtr->hours_worked !== null ? number_format($dtr->hours_worked, 2) : '-',
                'overtime_minutes' => $dtr->overtime_minutes ?? 0,
                'is_late' => $lateMin > 0,
                'is_undertime' => $utMin > 0,
                'is_overtime' => ($dtr->overtime_minutes ?? 0) > 0,
                'is_am_in_late' => $isAmInLate,
                'is_pm_in_late' => $isPmInLate,
                'is_pm_out_undertime' => $isPmOutUndertime,
                'source_badge' => match ($dtr->source) {
                    'biometric' => '<span class="hris-badge badge-approved">Biometric</span>',
                    'manual' => '<span class="hris-badge" style="background:#e5e7eb;color:#374151;">Manual</span>',
                    default => '<span style="color:#9ca3af;">-</span>',
                },
                'status_badge' => $statusBadge,
                'office_order_badge' => $ooNum
                    ? '<span class="hris-badge" style="background:#ede9fe;color:#5b21b6;">OO #'.e($ooNum).'</span>'
                    : '',
            ]);
        }

        // Add pure leave-only rows (approved leave, no biometric record for that day).
        // No real punch data exists for these dates either way, so there's nothing
        // for a half-day leave to hide here - always shown as the leave code.
        foreach ($leaveMap as $dateStr => $leaveEntry) {
            if (isset($dtrDates[$dateStr])) {
                continue;
            }
            $leaveCode = $leaveEntry['code'];
            $data->push([
                'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                'time_in_am' => $leaveCode,
                'time_out_am' => $leaveCode,
                'time_in_pm' => $leaveCode,
                'time_out_pm' => $leaveCode,
                'time_in_ot' => '-',
                'time_out_ot' => '-',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'hours_worked' => '-',
                'overtime_minutes' => 0,
                'is_late' => false,
                'is_undertime' => false,
                'is_overtime' => false,
                'is_am_in_late' => false,
                'is_pm_in_late' => false,
                'is_pm_out_undertime' => false,
                'source_badge' => '',
                'status_badge' => '<span class="hris-badge" style="background:#fef3c7;color:#92400e;">On Leave ('.$leaveCode.')</span>',
                'office_order_badge' => '',
            ]);
        }

        // Add ETA-only rows: approved ETA days with no biometric record and no leave.
        foreach ($etaDateSet as $dateStr => $_) {
            if (isset($dtrDates[$dateStr]) || $leaveMap->has($dateStr)) {
                continue;
            }
            $data->push([
                'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                'time_in_am' => 'ETA',
                'time_out_am' => 'ETA',
                'time_in_pm' => 'ETA',
                'time_out_pm' => 'ETA',
                'time_in_ot' => '-',
                'time_out_ot' => '-',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'hours_worked' => '-',
                'overtime_minutes' => 0,
                'is_late' => false,
                'is_undertime' => false,
                'is_overtime' => false,
                'is_am_in_late' => false,
                'is_pm_in_late' => false,
                'is_pm_out_undertime' => false,
                'source_badge' => '',
                'status_badge' => '<span class="hris-badge" style="background:#dbeafe;color:#1e40af;">On Official Travel</span>',
                'office_order_badge' => '',
            ]);
        }

        // Add OO-only rows: office-order days with no biometric record, no leave, and no ETA.
        foreach ($officeOrderDateMap as $dateStr => $ooNum) {
            if (isset($dtrDates[$dateStr]) || $leaveMap->has($dateStr) || isset($etaDateSet[$dateStr])) {
                continue;
            }
            $data->push([
                'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                'time_in_am' => 'Office Order',
                'time_out_am' => 'Office Order',
                'time_in_pm' => 'Office Order',
                'time_out_pm' => 'Office Order',
                'time_in_ot' => '-',
                'time_out_ot' => '-',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'hours_worked' => '-',
                'overtime_minutes' => 0,
                'is_late' => false,
                'is_undertime' => false,
                'is_overtime' => false,
                'is_am_in_late' => false,
                'is_pm_in_late' => false,
                'is_pm_out_undertime' => false,
                'source_badge' => '',
                'status_badge' => '<span class="hris-badge" style="background:#ede9fe;color:#5b21b6;">Office Order</span>',
                'office_order_badge' => '<span class="hris-badge" style="background:#ede9fe;color:#5b21b6;">OO #'.e($ooNum).'</span>',
            ]);
        }

        // Add TO-only rows: travel-order days with no biometric record, no leave,
        // no ETA, and no OO (OO outranks Travel Order in the priority waterfall).
        foreach ($travelOrderDateMap as $dateStr => $toNum) {
            if (isset($dtrDates[$dateStr]) || $leaveMap->has($dateStr) || isset($etaDateSet[$dateStr]) || isset($officeOrderDateMap[$dateStr])) {
                continue;
            }
            $data->push([
                'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                'time_in_am' => 'Travel Order',
                'time_out_am' => 'Travel Order',
                'time_in_pm' => 'Travel Order',
                'time_out_pm' => 'Travel Order',
                'time_in_ot' => '-',
                'time_out_ot' => '-',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'hours_worked' => '-',
                'overtime_minutes' => 0,
                'is_late' => false,
                'is_undertime' => false,
                'is_overtime' => false,
                'is_am_in_late' => false,
                'is_pm_in_late' => false,
                'is_pm_out_undertime' => false,
                'source_badge' => '',
                'status_badge' => '<span class="hris-badge" style="background:#cffafe;color:#155e75;">Travel Order</span>',
                'office_order_badge' => '',
            ]);
        }

        // Add excuse-only rows: excused days with no biometric/leave/ETA/OO/TO record.
        foreach ($excuseMap as $dateStr => $excuse) {
            if (isset($dtrDates[$dateStr]) || $leaveMap->has($dateStr) || isset($etaDateSet[$dateStr]) || isset($officeOrderDateMap[$dateStr]) || isset($travelOrderDateMap[$dateStr])) {
                continue;
            }
            $excuseCfg = DtrExcuse::typeConfig($excuse->excuse_type);
            $excuseTooltip = e($excuse->reason ?: $excuseCfg['label']);
            $excuseBadge = '<span class="hris-badge" style="background:'.$excuseCfg['bg'].';color:'.$excuseCfg['color'].';font-size:.65rem;padding:.15rem .5rem;" title="'.$excuseTooltip.'"><i class="fas '.$excuseCfg['icon'].'" style="font-size:.6rem;"></i> '.$excuseCfg['label'].'</span>';
            $data->push([
                'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                'time_in_am' => ($excuse->excuse_am_in || $excuse->is_full_day) ? $excuseBadge : '-',
                'time_out_am' => ($excuse->excuse_am_out || $excuse->is_full_day) ? $excuseBadge : '-',
                'time_in_pm' => ($excuse->excuse_pm_in || $excuse->is_full_day) ? $excuseBadge : '-',
                'time_out_pm' => ($excuse->excuse_pm_out || $excuse->is_full_day) ? $excuseBadge : '-',
                'time_in_ot' => '-',
                'time_out_ot' => '-',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'hours_worked' => '-',
                'overtime_minutes' => 0,
                'is_late' => false,
                'is_undertime' => false,
                'is_overtime' => false,
                'is_am_in_late' => false,
                'is_pm_in_late' => false,
                'is_pm_out_undertime' => false,
                'source_badge' => '',
                'status_badge' => '<span class="hris-badge" style="background:#fef3c7;color:#92400e;">Excused</span>',
                'office_order_badge' => '',
            ]);
        }

        // Add locator-only rows: approved locator days with no biometric/leave/ETA/OO/TO record.
        foreach ($locatorDateMap as $dateStr => $loc) {
            if (isset($dtrDates[$dateStr]) || $leaveMap->has($dateStr) || isset($etaDateSet[$dateStr]) || isset($officeOrderDateMap[$dateStr]) || isset($travelOrderDateMap[$dateStr])) {
                continue;
            }
            $data->push([
                'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                'time_in_am' => $loc['covers_am_in'] ? 'LOCATOR' : '-',
                'time_out_am' => $loc['covers_am_out'] ? 'LOCATOR' : '-',
                'time_in_pm' => $loc['covers_pm_in'] ? 'LOCATOR' : '-',
                'time_out_pm' => $loc['covers_pm_out'] ? 'LOCATOR' : '-',
                'time_in_ot' => '-',
                'time_out_ot' => '-',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'hours_worked' => '-',
                'overtime_minutes' => 0,
                'is_late' => false,
                'is_undertime' => false,
                'is_overtime' => false,
                'is_am_in_late' => false,
                'is_pm_in_late' => false,
                'is_pm_out_undertime' => false,
                'source_badge' => '',
                'status_badge' => '<span class="hris-badge" style="background:#d1fae5;color:#065f46;">Locator</span>',
                'office_order_badge' => '',
            ]);
        }

        // Add rest-day and field-work rows: special-assignment dates with no DTR record and no leave.
        foreach ($shiftAssignments as $dateStr => $assignment) {
            if ($assignment->shift_id !== null) {
                continue; // normal shift override, handled via DTR rows
            }
            if ($assignment->type === 'standard') {
                continue; // forced Standard Day is a normal working day, not a rest/field-work special row
            }
            if (isset($dtrDates[$dateStr]) || $leaveMap->has($dateStr) || isset($etaDateSet[$dateStr])
                || isset($officeOrderDateMap[$dateStr]) || isset($travelOrderDateMap[$dateStr])
                || isset($excuseMap[$dateStr]) || isset($locatorDateMap[$dateStr])) {
                continue; // already represented by a DTR, leave, ETA, office order, travel order, excuse, or locator row
            }
            if (in_array($assignment->type, ['field_work', 'wfh'], true)
                && isset($suspensionMap[$dateStr]) && ! $employee->isFrontlineExempt()) {
                continue; // a declared suspension takes priority - let the catch-all loop below render it
            }

            if ($assignment->type === 'field_work') {
                $data->push([
                    'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                    'time_in_am' => '-', 'time_out_am' => '-',
                    'time_in_pm' => '-', 'time_out_pm' => '-',
                    'time_in_ot' => '-', 'time_out_ot' => '-',
                    'late_minutes' => 0, 'undertime_minutes' => 0, 'hours_worked' => '-', 'overtime_minutes' => 0,
                    'is_late' => false, 'is_undertime' => false, 'is_overtime' => false,
                    'is_am_in_late' => false, 'is_pm_in_late' => false, 'is_pm_out_undertime' => false,
                    'source_badge' => '<span style="color:#9ca3af;">-</span>',
                    'status_badge' => '<span class="hris-badge" style="background:#f0fdf4;color:#15803d;">Field Work</span>',
                    'office_order_badge' => '',
                ]);
            } elseif ($assignment->type === 'wfh') {
                $data->push([
                    'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                    'time_in_am' => '-', 'time_out_am' => '-',
                    'time_in_pm' => '-', 'time_out_pm' => '-',
                    'time_in_ot' => '-', 'time_out_ot' => '-',
                    'late_minutes' => 0, 'undertime_minutes' => 0, 'hours_worked' => '-', 'overtime_minutes' => 0,
                    'is_late' => false, 'is_undertime' => false, 'is_overtime' => false,
                    'is_am_in_late' => false, 'is_pm_in_late' => false, 'is_pm_out_undertime' => false,
                    'source_badge' => '<span style="color:#9ca3af;">-</span>',
                    'status_badge' => '<span class="hris-badge" style="background:#eff6ff;color:#1d4ed8;">Work From Home</span>',
                    'office_order_badge' => '',
                ]);
            } elseif ($assignment->type === 'field_work_unconfirmed') {
                // A real, consequence-bearing absence written by
                // WeeklyPunchPairReconciliationService (a Field Work Pair week
                // that resolved incomplete - see that class's docblock) - must
                // render like the plain "Absent" badge below (line ~1067),
                // not fall into the generic "Rest Day" else-branch, since
                // this date is neither a day off nor unresolved.
                $data->push([
                    'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                    'time_in_am' => '-', 'time_out_am' => '-',
                    'time_in_pm' => '-', 'time_out_pm' => '-',
                    'time_in_ot' => '-', 'time_out_ot' => '-',
                    'late_minutes' => 0, 'undertime_minutes' => 0, 'hours_worked' => '-', 'overtime_minutes' => 0,
                    'is_late' => false, 'is_undertime' => false, 'is_overtime' => false,
                    'is_am_in_late' => false, 'is_pm_in_late' => false, 'is_pm_out_undertime' => false,
                    'source_badge' => '<span style="color:#9ca3af;">-</span>',
                    'status_badge' => '<span class="hris-badge badge-rejected">Absent (Unconfirmed Field Work)</span>',
                    'office_order_badge' => '',
                ]);
            } else {
                $data->push([
                    'date' => Carbon::parse($dateStr)->format('M d, Y (D)'),
                    'time_in_am' => '-', 'time_out_am' => '-',
                    'time_in_pm' => '-', 'time_out_pm' => '-',
                    'time_in_ot' => '-', 'time_out_ot' => '-',
                    'late_minutes' => 0, 'undertime_minutes' => 0, 'hours_worked' => '-', 'overtime_minutes' => 0,
                    'is_late' => false, 'is_undertime' => false, 'is_overtime' => false,
                    'is_am_in_late' => false, 'is_pm_in_late' => false, 'is_pm_out_undertime' => false,
                    'source_badge' => '<span style="color:#9ca3af;">-</span>',
                    'status_badge' => '<span class="hris-badge" style="background:#f3f4f6;color:#6b7280;">Rest Day</span>',
                    'office_order_badge' => '',
                ]);
            }
        }

        // Add a row for every remaining date in the period not already
        // covered by one of the sources above (no DTR row, and no
        // leave/ETA/OO/TO/excuse/locator/rest-day/field-work record) - these
        // were previously silently dropped from $data entirely; now every
        // date in the period gets exactly one row (Rest Day / Work Suspended
        // / Absent / not-yet-due placeholder) so the table renders as a
        // complete calendar.
        $coveredDates = $dtrDates;
        foreach ($leaveMap->keys() as $d) {
            $coveredDates[$d] = true;
        }
        foreach (array_keys($etaDateSet) as $d) {
            $coveredDates[$d] = true;
        }
        foreach (array_keys($officeOrderDateMap) as $d) {
            $coveredDates[$d] = true;
        }
        foreach (array_keys($travelOrderDateMap) as $d) {
            $coveredDates[$d] = true;
        }
        foreach ($excuseMap->keys() as $d) {
            $coveredDates[$d] = true;
        }
        foreach (array_keys($locatorDateMap) as $d) {
            $coveredDates[$d] = true;
        }
        foreach ($shiftAssignments as $d => $assignment) {
            if ($assignment->shift_id === null && $assignment->type !== 'standard') {
                if (in_array($assignment->type, ['field_work', 'wfh'], true)
                    && isset($suspensionMap[$d]) && ! $employee->isFrontlineExempt()) {
                    continue; // left uncovered so the catch-all loop below renders the suspension
                }
                $coveredDates[$d] = true; // rest/field-work rows, already handled above
            }
        }

        $periodEnd = Carbon::parse($to);
        for ($cursor = Carbon::parse($from); $cursor->lte($periodEnd); $cursor->addDay()) {
            $dateStr = $cursor->format('Y-m-d');
            if (isset($coveredDates[$dateStr])) {
                continue;
            }

            // Ordinary weekends/off-days (no override, no ShiftAssignment
            // day-of-week match) still get a row - collapsing "explicit rest
            // override" and "implicit non-workday" into the same "Rest Day"
            // label mirrors how ResolvedScheduleService::buildMonth() already
            // displays both cases identically elsewhere in this app. The one
            // exception is a Field Work Pair gap day (e.g. Tue/Wed/Thu of a
            // Monday-in/Friday-out week) - that's also a non-workday, but
            // "Rest Day" wrongly implies a day off rather than "excluded,
            // nothing counted" - see WorkSchedule::isFieldWorkPairGapDay().
            if (! WorkSchedule::isWorkday($employee, $cursor, $shiftAssignments)) {
                $statusBadge = WorkSchedule::isFieldWorkPairGapDay($employee, $cursor, $shiftAssignments)
                    ? '<span class="hris-badge" style="background:#f0fdf4;color:#15803d;">No Punch Required</span>'
                    : '<span class="hris-badge" style="background:#f3f4f6;color:#6b7280;">Rest Day</span>';
                $data->push([
                    'date' => $cursor->format('M d, Y (D)'),
                    'time_in_am' => '-', 'time_out_am' => '-',
                    'time_in_pm' => '-', 'time_out_pm' => '-',
                    'time_in_ot' => '-', 'time_out_ot' => '-',
                    'late_minutes' => 0, 'undertime_minutes' => 0, 'hours_worked' => '-', 'overtime_minutes' => 0,
                    'is_late' => false, 'is_undertime' => false, 'is_overtime' => false,
                    'is_am_in_late' => false, 'is_pm_in_late' => false, 'is_pm_out_undertime' => false,
                    'source_badge' => '<span style="color:#9ca3af;">-</span>',
                    'status_badge' => $statusBadge,
                    'office_order_badge' => '',
                ]);

                continue;
            }

            $dateSchedule = WorkSchedule::forUserOnDate($employee, $cursor, $shiftAssignments);

            $uncoveredSuspension = $suspensionMap[$dateStr] ?? null;
            $uncoveredSuspensionSlots = [];
            if ($uncoveredSuspension !== null && ! $employee->isFrontlineExempt()) {
                [$dateSchedule, $uncoveredSuspensionSlots] = $dateSchedule->applySuspension($uncoveredSuspension->suspension_time);
            }

            if (count($uncoveredSuspensionSlots) === 4) {
                // Full-day suspension with no Dtr row at all for this date -
                // there are no punches to impute from, so just badge it.
                $uncoveredCfg = WorkSuspension::typeConfig($uncoveredSuspension->type);
                $uncoveredSlotLabel = strtoupper($uncoveredCfg['label']);
                $data->push([
                    'date' => $cursor->format('M d, Y (D)'),
                    'time_in_am' => $uncoveredSlotLabel,
                    'time_out_am' => $uncoveredSlotLabel,
                    'time_in_pm' => $uncoveredSlotLabel,
                    'time_out_pm' => $uncoveredSlotLabel,
                    'time_in_ot' => '-',
                    'time_out_ot' => '-',
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'hours_worked' => '-',
                    'overtime_minutes' => 0,
                    'is_late' => false,
                    'is_undertime' => false,
                    'is_overtime' => false,
                    'is_am_in_late' => false,
                    'is_pm_in_late' => false,
                    'is_pm_out_undertime' => false,
                    'source_badge' => '<span style="color:#9ca3af;">-</span>',
                    'status_badge' => '<span class="hris-badge" style="background:'.$uncoveredCfg['bg'].';color:'.$uncoveredCfg['color'].';"><i class="fas '.$uncoveredCfg['icon'].'" style="font-size:.65rem;"></i> '.$uncoveredCfg['label'].'</span>',
                    'office_order_badge' => '',
                ]);

                continue;
            }

            // Otherwise: no suspension, or only a partial one (afternoon-only /
            // capped-workEnd-only) - $dateSchedule's workEnd already reflects
            // any cap, so the gate below fires as soon as THAT reduced shift
            // has ended, not the unadjusted one.
            $uncoveredShiftEnded = Carbon::now()->gte($dateSchedule->referenceDateTime($dateStr, $dateSchedule->workEnd));
            if (! $uncoveredShiftEnded) {
                // Same-day/future date whose shift hasn't finished yet - not
                // missing yet, so no "Absent" claim, but the date still gets
                // a row so the month renders as a complete calendar.
                $data->push([
                    'date' => $cursor->format('M d, Y (D)'),
                    'time_in_am' => '-', 'time_out_am' => '-',
                    'time_in_pm' => '-', 'time_out_pm' => '-',
                    'time_in_ot' => '-', 'time_out_ot' => '-',
                    'late_minutes' => 0, 'undertime_minutes' => 0, 'hours_worked' => '-', 'overtime_minutes' => 0,
                    'is_late' => false, 'is_undertime' => false, 'is_overtime' => false,
                    'is_am_in_late' => false, 'is_pm_in_late' => false, 'is_pm_out_undertime' => false,
                    'source_badge' => '<span style="color:#9ca3af;">-</span>',
                    'status_badge' => '<span style="color:#9ca3af;">-</span>',
                    'office_order_badge' => '',
                ]);

                continue;
            }

            // A Field Work-style in_only/out_only date only ever has one real
            // slot to miss - the rest (including am_in/pm_out on the side
            // that's never expected) show a plain dash, same as no_break's
            // am_out/pm_in already do.
            $placeholderHasBreakSlots = ! $dateSchedule->noBreak && $dateSchedule->punchRequirement === 'both';
            $placeholderAmInMissing = $dateSchedule->punchRequirement !== 'out_only' ? 'Missing' : '-';
            $placeholderPmOutMissing = $dateSchedule->punchRequirement !== 'in_only' ? 'Missing' : '-';

            $data->push([
                'date' => $cursor->format('M d, Y (D)'),
                'time_in_am' => $placeholderAmInMissing,
                'time_out_am' => $placeholderHasBreakSlots ? 'Missing' : '-',
                'time_in_pm' => $placeholderHasBreakSlots ? 'Missing' : '-',
                'time_out_pm' => $placeholderPmOutMissing,
                'time_in_ot' => '-',
                'time_out_ot' => '-',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'hours_worked' => '-',
                'overtime_minutes' => 0,
                'is_late' => false,
                'is_undertime' => false,
                'is_overtime' => false,
                'is_am_in_late' => false,
                'is_pm_in_late' => false,
                'is_pm_out_undertime' => false,
                'source_badge' => '<span style="color:#9ca3af;">-</span>',
                'status_badge' => '<span class="hris-badge badge-rejected">Absent</span>',
                'office_order_badge' => '',
            ]);
        }

        $data = $data->sortBy('date')->values();
        $total = $data->count();

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $total,
            'recordsFiltered' => $total,
            'data' => $data,
        ]);
    }

    // ── FORM 48 DOWNLOAD ──────────────────────────────────────────────────────

    public function downloadForm48(Request $request, Form48ExportService $exportService): StreamedResponse
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptIds' => $officerDeptIds] = $this->resolveContext($user);

        $periodRules = [
            'dtr_type' => ['required', 'in:monthly,semi-monthly'],
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'period' => ['nullable', 'in:1,2'],
        ];

        if ($isAdmin || $isOfficer) {
            $request->validate(array_merge($periodRules, [
                'employee_id' => ['required', 'integer', 'exists:users,id'],
            ]));
            $employee = User::findOrFail($request->integer('employee_id'));

            if ($isOfficer && ! in_array((int) $employee->Dept_id, $officerDeptIds)) {
                abort(403, 'You may only export DTR records for employees in your own department.');
            }
        } else {
            $request->validate($periodRules);
            $employee = $user;
        }

        abort_if($employee->dtr_exempt, 422, 'This employee is exempt from biometric/DTR.');

        $dtrType = $request->input('dtr_type');
        $month = $request->input('month');
        $period = $request->integer('period', 1);

        [$from, $to] = $this->resolvePeriod($month, $dtrType, $period);
        $monthYear = $this->resolveMonthYearLabel($from, $to, $dtrType);

        $templatePath = storage_path('app/templates/form48.xls');
        abort_unless(file_exists($templatePath), 500, 'Form 48 template not found.');

        $records = $exportService->buildRecords($employee->id, $from, $to);
        $leaveMap = $exportService->buildLeaveMap($employee->id, $from, $to);
        $etaMap = $exportService->buildEtaMap($employee->id, $from, $to);
        $locatorMap = $exportService->buildLocatorMap($employee->id, $from, $to);
        $restDayMap = $exportService->buildRestDayMap($employee->id, $from, $to);
        $fieldWorkMap = $exportService->buildFieldWorkMap($employee->id, $from, $to);
        $excuseMap = $exportService->buildExcuseMap($employee->id, $from, $to);
        $officeOrderMap = $exportService->buildOfficeOrderMap($employee->id, $from, $to);
        $wfhMap = $exportService->buildWfhMap($employee->id, $from, $to);
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $exportService->fill($sheet, $records, $employee, $monthYear, $from, $leaveMap, $etaMap, $locatorMap, $restDayMap, $fieldWorkMap, $excuseMap, $officeOrderMap, $wfhMap);

        $safe = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $exportService->formatName($employee))) ?: 'DTR';
        $downloadName = "CSC_Form_48_({$safe}).xlsx";

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $downloadName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    // ── DEPARTMENT BULK - ZIP (one xlsx per employee) ─────────────────────────

    public function downloadDepartmentZip(Request $request, Form48ExportService $exportService): BinaryFileResponse|RedirectResponse
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptIds' => $officerDeptIds] = $this->resolveContext($user);
        abort_unless($isAdmin || $isOfficer, 403);

        $request->validate([
            'dtr_type' => ['required', 'in:monthly,semi-monthly'],
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'period' => ['nullable', 'in:1,2'],
            'employee_type' => ['nullable', 'in:permanent,co-terminus,casual,job orders,contractual'],
            'dept_id' => ['required', 'integer', 'exists:departments,Dept_id'],
        ]);

        $dtrType = $request->input('dtr_type');
        $month = $request->input('month');
        $period = $request->integer('period', 1);
        $employeeType = $request->input('employee_type') ?: null;
        $deptId = $request->integer('dept_id');

        // Officers can only export departments they manage.
        if ($isOfficer) {
            abort_unless(in_array($deptId, $officerDeptIds), 403, 'You may only export DTR records for your own department(s).');
        }

        [$from, $to] = $this->resolvePeriod($month, $dtrType, $period);
        $monthYear = $this->resolveMonthYearLabel($from, $to, $dtrType);

        $employees = User::active()->where('Dept_id', $deptId)
            ->where('dtr_exempt', false)
            ->when($employeeType, fn ($q, $type) => $q->where('employee_type', $type))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
        $dept = Department::find($deptId);
        $deptSafe = preg_replace('/[^A-Za-z0-9_]/', '_', $dept?->Dept_name ?? (string) $deptId);

        $templatePath = storage_path('app/templates/form48.xls');

        if ($employees->isEmpty()) {
            return redirect()->back()->with('dtr_error', 'No employees found in the selected department.');
        }
        abort_unless(file_exists($templatePath), 500, 'Form 48 template not found.');

        $generated = [];    // zip entry name → tmp file path

        foreach ($employees as $employee) {
            $records = $exportService->buildRecords($employee->id, $from, $to);
            $leaveMap = $exportService->buildLeaveMap($employee->id, $from, $to);
            $etaMap = $exportService->buildEtaMap($employee->id, $from, $to);
            $locatorMap = $exportService->buildLocatorMap($employee->id, $from, $to);
            $restDayMap = $exportService->buildRestDayMap($employee->id, $from, $to);
            $fieldWorkMap = $exportService->buildFieldWorkMap($employee->id, $from, $to);
            $excuseMap = $exportService->buildExcuseMap($employee->id, $from, $to);
            $officeOrderMap = $exportService->buildOfficeOrderMap($employee->id, $from, $to);
            $wfhMap = $exportService->buildWfhMap($employee->id, $from, $to);
            if (empty($records) && empty($leaveMap) && empty($etaMap) && empty($locatorMap)) {
                continue;
            }

            $spreadsheet = IOFactory::load($templatePath);
            $sheet = $spreadsheet->getActiveSheet();
            $exportService->fill($sheet, $records, $employee, $monthYear, $from, $leaveMap, $etaMap, $locatorMap, $restDayMap, $fieldWorkMap, $excuseMap, $officeOrderMap, $wfhMap);

            $safe = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $exportService->formatName($employee))) ?: 'Employee_'.$employee->id;
            $tmpPath = tempnam(sys_get_temp_dir(), 'dtr_');

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tmpPath);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            $generated[$safe.'.xlsx'] = $tmpPath;
        }

        if (empty($generated)) {
            return redirect()->back()->with('dtr_error', 'No time records found for any employee in the selected department and period.');
        }
        $typeLabel = $employeeType ? ucwords(str_replace('-', ' ', $employeeType)) : 'All';
        $typeSafe = preg_replace('/[^A-Za-z0-9]+/', '_', $typeLabel) ?: 'All';
        $monthLabel = Carbon::parse($month.'-01')->format('FY');
        $zipPath = tempnam(sys_get_temp_dir(), 'dtr_zip_');

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            foreach ($generated as $p) {
                @unlink($p);
            }
            abort(500, 'Failed to create ZIP archive.');
        }

        foreach ($generated as $entryName => $path) {
            $zip->addFile($path, $entryName);
        }
        $zip->close();

        foreach ($generated as $p) {
            @unlink($p);
        }

        return response()->download($zipPath, "CSC_Form_48_{$deptSafe}_{$typeSafe}_{$monthLabel}.zip", [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    // ── DEPARTMENT BULK - MULTI-SHEET WORKBOOK ────────────────────────────────

    public function downloadDepartmentForm48(Request $request, Form48ExportService $exportService): StreamedResponse|JsonResponse
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptIds' => $officerDeptIds] = $this->resolveContext($user);
        abort_unless($isAdmin || $isOfficer, 403);

        $request->validate([
            'dtr_type' => ['required', 'in:monthly,semi-monthly'],
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'period' => ['nullable', 'in:1,2'],
            'employee_type' => ['nullable', 'in:permanent,co-terminus,casual,job orders,contractual'],
            'dept_id' => ['required', 'integer', 'exists:departments,Dept_id'],
        ]);

        $dtrType = $request->input('dtr_type');
        $month = $request->input('month');
        $period = $request->integer('period', 1);
        $employeeType = $request->input('employee_type') ?: null;
        $deptId = $request->integer('dept_id');

        // Officers can only export departments they manage.
        if ($isOfficer) {
            abort_unless(in_array($deptId, $officerDeptIds), 403, 'You may only export DTR records for your own department(s).');
        }

        [$from, $to] = $this->resolvePeriod($month, $dtrType, $period);
        $monthYear = $this->resolveMonthYearLabel($from, $to, $dtrType);

        $employees = User::active()->where('Dept_id', $deptId)
            ->where('dtr_exempt', false)
            ->when($employeeType, fn ($q, $type) => $q->where('employee_type', $type))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
        $dept = Department::find($deptId);
        $deptSafe = preg_replace('/[^A-Za-z0-9_]/', '_', $dept?->Dept_name ?? (string) $deptId);

        $templatePath = storage_path('app/templates/form48.xls');

        if ($employees->isEmpty()) {
            return response()->json(['error' => 'No employees found in the selected department.'], 422);
        }
        abort_unless(file_exists($templatePath), 500, 'Form 48 template not found.');

        $workbook = IOFactory::load($templatePath);
        $template = $workbook->getActiveSheet();
        $filled = 0;

        foreach ($employees as $employee) {
            $records = $exportService->buildRecords($employee->id, $from, $to);
            $leaveMap = $exportService->buildLeaveMap($employee->id, $from, $to);
            $etaMap = $exportService->buildEtaMap($employee->id, $from, $to);
            $locatorMap = $exportService->buildLocatorMap($employee->id, $from, $to);
            $restDayMap = $exportService->buildRestDayMap($employee->id, $from, $to);
            $fieldWorkMap = $exportService->buildFieldWorkMap($employee->id, $from, $to);
            $excuseMap = $exportService->buildExcuseMap($employee->id, $from, $to);
            $officeOrderMap = $exportService->buildOfficeOrderMap($employee->id, $from, $to);
            $wfhMap = $exportService->buildWfhMap($employee->id, $from, $to);
            if (empty($records) && empty($leaveMap) && empty($etaMap) && empty($locatorMap)) {
                continue;
            }

            $clone = clone $template;
            $sheetName = mb_substr(
                preg_replace('/[^\w ]/', '', $exportService->formatName($employee)),
                0, 31
            ) ?: "Employee_{$employee->id}";
            $clone->setTitle($sheetName);

            // addSheet before fill so merged-cell operations resolve against the workbook.
            $workbook->addSheet($clone);
            $exportService->fill($clone, $records, $employee, $monthYear, $from, $leaveMap, $etaMap, $locatorMap, $restDayMap, $fieldWorkMap, $excuseMap, $officeOrderMap, $wfhMap);
            $filled++;
        }

        if ($filled === 0) {
            return response()->json(['error' => 'No time records found for any employee in the selected department and period.'], 422);
        }

        // Drop the blank template sheet (index 0) and activate the first filled sheet.
        $workbook->removeSheetByIndex(0);
        $workbook->setActiveSheetIndex(0);

        $typeLabel = $employeeType ? ucwords(str_replace('-', ' ', $employeeType)) : 'All';
        $typeSafe = preg_replace('/[^A-Za-z0-9]+/', '_', $typeLabel) ?: 'All';
        $monthLabel = Carbon::parse($month.'-01')->format('FY');
        $downloadName = "CSC_Form_48_{$deptSafe}_{$typeSafe}_{$monthLabel}.xlsx";

        return response()->streamDownload(
            function () use ($workbook): void {
                $writer = IOFactory::createWriter($workbook, 'Xlsx');
                $writer->save('php://output');
                $workbook->disconnectWorksheets();
            },
            $downloadName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
