<?php

namespace App\Services\Attendance;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\EmployeeShiftSchedule;
use App\Models\Eta;
use App\Models\Holiday;
use App\Models\HRAuditTrail;
use App\Models\LeaveDate;
use App\Models\Locator;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Services\PersonnelLogImportService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retroactively resolves a "Field Work" style Monday in_only / Friday
 * out_only punch pairing once a week has fully closed (see the class-level
 * discussion in the "Field Work Shift" plan for the full business rule).
 * Can only run after the fact - during the week, attendance:auto-import
 * resolves each date against whatever WorkSchedule::forUserOnDate() returns
 * for that date alone, with no knowledge of the rest of the week.
 *
 * Two entry points: reconcile() is the scheduled, company-wide sweep
 * (attendance:reconcile-punch-pairs, dailyAt('01:15')). reconcileForUser()
 * is called eagerly, synchronously, right after an in_only/out_only
 * ShiftAssignment is created/corrected (EmployeeScheduleController::
 * reconcileEagerlyIfNeeded(), BulkShiftRecomputeJob) - without it, a
 * backdated effective_from's already-elapsed weeks would resolve their real
 * punches correctly right away (recomputeFullRange() already does that) but
 * sit showing the plain "No Punch Required" gap label instead of a real
 * Absence until the next scheduled sweep, which could be many hours away.
 *
 * The rule, in full:
 *   - Scan Monday -> Thursday for the first date with a real punch (the
 *     "check-in day"). Every date BEFORE it becomes a genuine, consequence-
 *     bearing absence (a 'field_work_unconfirmed' EmployeeShiftSchedule
 *     override - see that model's docblock for why this makes isWorkday()
 *     return true for an otherwise-excluded day, which is all every
 *     consumer - LwopAggregationService, AttendanceMonitoringExportService,
 *     DtrController, PayrollComputationService - needs to treat it as a real
 *     missed workday). The check-in day itself resolves like a Monday punch
 *     would (single am_in slot, scored against the shift's 8:00 AM anchor) -
 *     for a non-Monday check-in day this requires writing a matching
 *     in_only EmployeeShiftSchedule override and recomputing that date's DTR.
 *   - If no punch happened at all Monday-Thursday, every one of those four
 *     dates becomes a 'field_work_unconfirmed' absence.
 *   - Friday overrides everything above: if Friday's out-punch is missing,
 *     the ENTIRE week is voided, including a genuine check-in day elsewhere
 *     in the week (its dtrs row is deleted despite the real punch). If
 *     Friday IS punched, it always keeps its own real status.
 *
 * Every write this service makes is marked is_reconciliation_generated so a
 * later run can tell its own prior output apart from a genuine manual
 * override (never touched) and self-heal (add/remove/update its own rows) as
 * a week's underlying data changes - e.g. a backfilled punch import shifting
 * which date is the true check-in day.
 */
class WeeklyPunchPairReconciliationService
{
    /** EmployeeShiftSchedule.type value for a retroactively-voided absence day. */
    public const VOID_TYPE = 'field_work_unconfirmed';

    public function __construct(
        private readonly PersonnelLogImportService $importService,
    ) {}

    /**
     * Sweeps every employee with an active Monday in_only / Friday out_only
     * pairing and reconciles every week whose Friday has already fully
     * elapsed. Safe to re-run. This is the scheduled, company-wide sweep
     * (attendance:reconcile-punch-pairs, dailyAt('01:15')) - see
     * reconcileForUser() for the eager, single-employee counterpart run
     * synchronously right after a Field Work Pair assignment is created.
     *
     * @return array{weeks_checked: int, weeks_reconciled: int}
     */
    public function reconcile(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $lookbackDays = (int) config('attendance.punch_pair_reconciliation.lookback_days', 45);
        $earliestMonday = $asOf->copy()->subDays($lookbackDays)->startOfWeek(Carbon::MONDAY);

        $userIds = ShiftAssignment::whereIn('punch_requirement', ['in_only', 'out_only'])
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $earliestMonday->toDateString()))
            ->pluck('user_id')
            ->unique();

        $weeksChecked = 0;
        $weeksReconciled = 0;

        foreach ($userIds as $userId) {
            $user = User::find($userId);

            if ($user === null) {
                continue;
            }

            $result = $this->reconcileUserFromMonday($user, $earliestMonday, $asOf);
            $weeksChecked += $result['weeks_checked'];
            $weeksReconciled += $result['weeks_reconciled'];
        }

        return ['weeks_checked' => $weeksChecked, 'weeks_reconciled' => $weeksReconciled];
    }

    /**
     * Eager, single-employee counterpart to reconcile() - closes the gap
     * where creating/correcting an in_only/out_only ShiftAssignment with a
     * backdated effective_from already recomputes DTR immediately (so real
     * punches resolve against the right schedule right away) but, without
     * this, leaves any already-elapsed week under that new coverage showing
     * the plain "No Punch Required" gap label instead of a real Absence
     * until the next scheduled sweep. Called synchronously from
     * EmployeeScheduleController::update() (per-employee) and from
     * BulkShiftRecomputeJob (bulk), both only when the assignment actually
     * used punch_requirement in_only/out_only - an ordinary shift assignment
     * never needs this call at all, since reconcileWeek()'s own gate check
     * would just no-op for every week anyway.
     *
     * Unlike reconcile(), $since is the assignment's own effective_from
     * (clamped to that week's Monday) rather than a fixed lookback window -
     * the caller already knows exactly which range is newly covered, so
     * there's no reason to guess via config('...lookback_days').
     *
     * @return array{weeks_checked: int, weeks_reconciled: int}
     */
    public function reconcileForUser(User $user, Carbon $since, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();
        $earliestMonday = $since->copy()->startOfWeek(Carbon::MONDAY);

        return $this->reconcileUserFromMonday($user, $earliestMonday, $asOf);
    }

    /**
     * Shared week-by-week sweep loop for one employee, from $earliestMonday
     * through the last week whose Friday is strictly before $asOf. Extracted
     * so reconcile()'s company-wide sweep and reconcileForUser()'s eager,
     * single-employee call can't drift apart on this loop's own logic - the
     * dtr_exempt guard lives here rather than in each caller for the same
     * reason.
     *
     * @return array{weeks_checked: int, weeks_reconciled: int}
     */
    private function reconcileUserFromMonday(User $user, Carbon $earliestMonday, Carbon $asOf): array
    {
        if ($user->dtr_exempt) {
            return ['weeks_checked' => 0, 'weeks_reconciled' => 0];
        }

        $weeksChecked = 0;
        $weeksReconciled = 0;
        $monday = $earliestMonday->copy();

        while (true) {
            $friday = $monday->copy()->addDays(4);

            if (! $friday->lt($asOf)) {
                break; // this week (and every later one) hasn't fully closed yet
            }

            $weeksChecked++;

            if ($this->reconcileWeek($user, $monday->copy())) {
                $weeksReconciled++;
            }

            $monday->addWeek();
        }

        return ['weeks_checked' => $weeksChecked, 'weeks_reconciled' => $weeksReconciled];
    }

    /**
     * Per-date export-time guard: true when $date's real punch (if any) must
     * never be resurrected from attendance_logs, because the week it belongs
     * to resolved incomplete and $date isn't the surviving check-in day.
     * Only ever meaningful for a date whose resolved punchRequirement is
     * 'in_only' (Monday, or a reconciliation-written check-in override) -
     * Friday's own punch is never voided, so callers should gate on
     * punchRequirement === 'in_only' before calling this.
     */
    public function dateWasVoided(User $user, Carbon $date): bool
    {
        $monday = $date->copy()->startOfWeek(Carbon::MONDAY);
        $friday = $monday->copy()->addDays(4);

        if (WorkSchedule::forUserOnDate($user, $friday)->punchRequirement !== 'out_only') {
            return false; // not actually a recognized Field Work pair week
        }

        $checkInDay = $this->findCheckInDay($user, $this->weekdaysMonToThu($monday));
        $fridayPunched = $this->hasPunch($user, $friday);

        return ! ($fridayPunched && $checkInDay !== null && $checkInDay->isSameDay($date));
    }

    private function reconcileWeek(User $user, Carbon $monday): bool
    {
        $weekDays = $this->weekdaysMonToThu($monday);
        $friday = $monday->copy()->addDays(4);

        if (WorkSchedule::forUserOnDate($user, $monday)->punchRequirement !== 'in_only'
            || WorkSchedule::forUserOnDate($user, $friday)->punchRequirement !== 'out_only') {
            return false; // not a recognized Field Work pair week for this user
        }

        if ($user->date_hired !== null && $user->date_hired->betweenIncluded($monday, $friday)) {
            return false; // hire date falls inside this week - nothing to reconcile yet
        }

        $checkInDay = $this->findCheckInDay($user, $weekDays);
        $fridayPunched = $this->hasPunch($user, $friday);

        $desired = [];
        if (! $fridayPunched || $checkInDay === null) {
            foreach ($weekDays as $d) {
                $desired[$d->toDateString()] = 'unconfirmed';
            }
        } else {
            foreach ($weekDays as $d) {
                $desired[$d->toDateString()] = match (true) {
                    $d->lt($checkInDay) => 'unconfirmed',
                    $d->isSameDay($checkInDay) => 'check_in',
                    default => 'untouched',
                };
            }
        }

        $fieldWorkShiftId = $this->resolveGoverningShiftId($user, $monday);

        $changed = false;
        foreach ($weekDays as $d) {
            $changed = $this->reconcileDate($user, $d, $desired[$d->toDateString()], $fieldWorkShiftId) || $changed;
        }

        if ($changed) {
            $this->writeAuditRow($user, $monday, $friday, $checkInDay, $fridayPunched, $desired);
        }

        return $changed;
    }

    /**
     * Applies one date's desired state, self-healing from whatever this
     * service previously left behind and never touching an independently
     * covered date or a manual (non-owned) override.
     */
    private function reconcileDate(User $user, Carbon $date, string $desiredState, ?int $fieldWorkShiftId): bool
    {
        $dateStr = $date->toDateString();
        $existing = EmployeeShiftSchedule::where('user_id', $user->id)->where('date', $dateStr)->first();
        $ownedByUs = $existing !== null && $existing->is_reconciliation_generated;
        $manuallyOverridden = $existing !== null && ! $ownedByUs;

        $effectiveState = ($manuallyOverridden || $this->isIndependentlyCovered($user, $date))
            ? 'untouched'
            : $desiredState;

        $changed = false;

        if ($effectiveState === 'untouched') {
            if ($ownedByUs) {
                $wasCheckInOverride = $existing->type === null;
                $existing->delete();
                $changed = true;

                if ($wasCheckInOverride) {
                    $this->importService->recomputeDtr($user, $dateStr, $dateStr);
                }
            }

            return $changed;
        }

        if ($effectiveState === 'unconfirmed') {
            if (! $date->isMonday()) {
                if (! $ownedByUs) {
                    EmployeeShiftSchedule::create([
                        'user_id' => $user->id,
                        'date' => $dateStr,
                        'shift_id' => null,
                        'type' => self::VOID_TYPE,
                        'is_reconciliation_generated' => true,
                    ]);
                    $changed = true;
                } elseif ($existing->type !== self::VOID_TYPE || $existing->shift_id !== null) {
                    $existing->update(['shift_id' => null, 'type' => self::VOID_TYPE]);
                    $changed = true;
                }
            } elseif ($ownedByUs) {
                // Monday never needs an override of its own (its own
                // in_only ShiftAssignment already governs it) - defensive
                // cleanup only, in case an earlier version of this row exists.
                $existing->delete();
                $changed = true;
            }

            $voidedRows = Dtr::where('employee_id', $user->id)
                ->where('date', $dateStr)
                ->where('source', 'biometric')
                ->delete();

            return $changed || $voidedRows > 0;
        }

        // 'check_in'
        if (! $date->isMonday()) {
            $needsWrite = ! $ownedByUs
                || $existing->shift_id !== $fieldWorkShiftId
                || $existing->type !== null
                || $existing->punch_requirement !== 'in_only';

            if ($needsWrite) {
                EmployeeShiftSchedule::updateOrCreate(
                    ['user_id' => $user->id, 'date' => $dateStr],
                    [
                        'shift_id' => $fieldWorkShiftId,
                        'type' => null,
                        'punch_requirement' => 'in_only',
                        'is_reconciliation_generated' => true,
                    ]
                );
                $this->importService->recomputeDtr($user, $dateStr, $dateStr);
                $changed = true;
            }
        }
        // Monday's own dtrs row (governed by its real ShiftAssignment) is
        // never touched here - the live import already resolved it correctly.

        return $changed;
    }

    /**
     * True when $date already has an independent, legitimate explanation
     * (approved Leave/Locator/ETA/Office Order/Travel Order, a holiday, or a
     * full-day suspension not exempted by frontline status) that this
     * service must never override, mirroring the coverage sources
     * AttendanceMonitoringExportService already checks for the same
     * absence-classification purpose - re-implemented here as its own
     * self-contained check (per this codebase's established convention of
     * each service owning its own coverage query rather than sharing one).
     */
    private function isIndependentlyCovered(User $user, Carbon $date): bool
    {
        $dateStr = $date->toDateString();

        if (Holiday::whereDate('holiday_date', $dateStr)->exists()) {
            return true;
        }

        $suspension = WorkSuspension::whereDate('suspension_date', $dateStr)->first();
        if ($suspension !== null && $suspension->suspension_time === null && ! $user->isFrontlineExempt()) {
            return true;
        }

        $hasApprovedLeave = LeaveDate::where('is_cancelled', false)
            ->where('leave_date', $dateStr)
            ->whereHas('leaveRequest', fn ($q) => $q->where('user_id', $user->id)->where('status', 'approved'))
            ->exists();
        if ($hasApprovedLeave) {
            return true;
        }

        $hasApprovedLocator = Locator::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('travel_date', $dateStr)
            ->exists();
        if ($hasApprovedLocator) {
            return true;
        }

        $hasApprovedEta = Eta::where('user_id', $user->id)
            ->where('status', 'approved')
            ->where('departure_date', '<=', $dateStr)
            ->where('arrival_date', '>=', $dateStr)
            ->exists();
        if ($hasApprovedEta) {
            return true;
        }

        $hasFullDayExcuse = DtrExcuse::where('user_id', $user->id)
            ->where('date', $dateStr)
            ->where('is_full_day', true)
            ->exists();
        if ($hasFullDayExcuse) {
            return true;
        }

        if ($user->EmpNo) {
            $hasOfficeOrder = DB::table('office_orders')
                ->join('office_order_employees', 'office_orders.id', '=', 'office_order_employees.office_order_id')
                ->where('office_order_employees.emp_no', $user->EmpNo)
                ->where('office_orders.issued_date', '<=', $dateStr)
                ->where(function ($q) use ($dateStr): void {
                    $q->where('office_orders.effective_date', '>=', $dateStr)
                        ->orWhere(function ($q2) use ($dateStr): void {
                            $q2->whereNull('office_orders.effective_date')
                                ->where('office_orders.issued_date', '>=', $dateStr);
                        });
                })
                ->exists();
            if ($hasOfficeOrder) {
                return true;
            }

            $hasTravelOrder = DB::table('travel_orders')
                ->join('travel_order_employees', 'travel_orders.id', '=', 'travel_order_employees.travel_order_id')
                ->where('travel_order_employees.emp_no', $user->EmpNo)
                ->where('travel_orders.status', 'Approved')
                ->where('travel_orders.start_date', '<=', $dateStr)
                ->where('travel_orders.end_date', '>=', $dateStr)
                ->exists();
            if ($hasTravelOrder) {
                return true;
            }
        }

        return false;
    }

    /** The ShiftAssignment row governing $monday, i.e. the Field Work template itself. */
    private function resolveGoverningShiftId(User $user, Carbon $monday): ?int
    {
        $dateStr = $monday->toDateString();

        $row = ShiftAssignment::forUser($user->id)
            ->where('effective_from', '<=', $dateStr)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $dateStr))
            ->get()
            ->first(fn (ShiftAssignment $row) => $row->appliesOnDate($monday));

        return $row?->shift_id;
    }

    private function findCheckInDay(User $user, array $candidateDates): ?Carbon
    {
        foreach ($candidateDates as $date) {
            if ($this->hasPunch($user, $date)) {
                return $date;
            }
        }

        return null;
    }

    private function hasPunch(User $user, Carbon $date): bool
    {
        return AttendanceLog::where('user_id', $user->id)
            ->whereDate('logdate', $date->toDateString())
            ->exists();
    }

    /** @return list<Carbon> */
    private function weekdaysMonToThu(Carbon $monday): array
    {
        return [
            $monday->copy(),
            $monday->copy()->addDay(),
            $monday->copy()->addDays(2),
            $monday->copy()->addDays(3),
        ];
    }

    private function writeAuditRow(User $user, Carbon $monday, Carbon $friday, ?Carbon $checkInDay, bool $fridayPunched, array $dayStates): void
    {
        try {
            HRAuditTrail::create([
                'actor_user_id' => null,
                'module' => 'attendance',
                'action' => 'field_work_pair_week_incomplete',
                'target_type' => User::class,
                'target_id' => $user->id,
                'details' => [
                    'week_start' => $monday->toDateString(),
                    'week_end' => $friday->toDateString(),
                    'check_in_day' => $checkInDay?->toDateString(),
                    'friday_punched' => $fridayPunched,
                    'day_states' => $dayStates,
                ],
            ]);
        } catch (\Exception) {
            // audit failure must not block reconciliation
        }
    }
}
