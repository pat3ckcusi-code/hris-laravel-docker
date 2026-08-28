<?php

namespace App\Services;

use App\Services\Attendance\AttendanceMatcher;
use App\Services\Attendance\AttendanceStatusResolver;
use App\Services\Attendance\HoursWorkedCalculator;
use App\Services\Attendance\LateCalculator;
use App\Services\Attendance\OvertimeCalculator;
use App\Services\Attendance\UndertimeCalculator;
use App\Support\WorkSchedule;
use Carbon\Carbon;

/**
 * Resolves a single shift's raw biometric punches into the four CSC Form 48
 * slots (arrival, break-out, break-in, departure) plus tardiness, undertime,
 * hours worked, and an attendance status.
 *
 * Shared by PersonnelLogImportService (writes the dtrs table) and
 * Form48ExportService (export fallback) so the two can never disagree.
 *
 * Punches arrive as full datetimes already grouped onto one logical shift date
 * (see ShiftPunchGrouper), so a night shift's pre-midnight arrival and
 * post-midnight departure sort and score correctly across the day boundary.
 *
 * This class is a thin orchestrator over app/Services/Attendance:
 *
 *   1. AttendanceMatcher aligns the punches to the schedule's expected events
 *      (nearest-scheduled-time within eligibility windows, order-preserving -
 *      NEVER positional, so a missing punch stays null instead of pulling a
 *      later log upward). Punches no event can claim come back unmatched, for
 *      review. Matching completes fully before anything is computed.
 *   2. LateCalculator / UndertimeCalculator / HoursWorkedCalculator score the
 *      match result (late from IN events only, undertime from OUT events only,
 *      hours from complete in->out spans only).
 *   3. AttendanceStatusResolver names the day (present / late / undertime /
 *      half_day_am / half_day_pm / missing_in / missing_out / incomplete).
 *      Absent is never produced here - a punchless day gets no dtrs row at
 *      all, and absence stays a read-time classification.
 *
 * Matching tunables (windows, dedupe, late bias) live in config/attendance.php.
 */
class DtrPunchResolver
{
    public function __construct(
        private readonly AttendanceMatcher $matcher = new AttendanceMatcher,
        private readonly LateCalculator $lateCalculator = new LateCalculator,
        private readonly UndertimeCalculator $undertimeCalculator = new UndertimeCalculator,
        private readonly OvertimeCalculator $overtimeCalculator = new OvertimeCalculator,
        private readonly HoursWorkedCalculator $hoursWorkedCalculator = new HoursWorkedCalculator,
        private readonly AttendanceStatusResolver $statusResolver = new AttendanceStatusResolver,
    ) {}

    /**
     * @param  iterable<int, Carbon|string>  $punches  full punch datetimes for ONE shift
     * @param  array<string, array{0:string,1:string}|null>  $excludedSlots  slot key ('am_in'|'am_out'|'pm_in'|'pm_out')
     *                                                                       => exclusion window ['H:i', 'H:i'], or null for an unconditional
     *                                                                       exclusion (e.g. a DtrExcuse, where no punch is physically possible
     *                                                                       regardless of time). A windowed slot only has "no real punch
     *                                                                       expected" while a punch falls inside the window (e.g. a locator's
     *                                                                       [departure, arrival] span) - a punch outside it (before departure,
     *                                                                       or after arrival) is real and must land in its natural slot.
     * @return array{am_in:?string, am_out:?string, pm_in:?string, pm_out:?string,
     *               time_in_ot:?string, time_out_ot:?string,
     *               late_minutes:int, undertime_minutes:int, overtime_minutes:int,
     *               worked_minutes:int, hours_worked:float, status:string, unmatched:list<string>}
     */
    public function resolve(iterable $punches, string $shiftDate, WorkSchedule $schedule, array $excludedSlots = []): array
    {
        $result = $this->matcher->match($punches, $shiftDate, $schedule, $excludedSlots);

        $late = $this->lateCalculator->minutes($result, $shiftDate, $schedule);
        $undertime = $this->undertimeCalculator->minutes($result, $shiftDate, $schedule);
        $overtime = $this->overtimeCalculator->minutes($result);
        $workedMinutes = $this->hoursWorkedCalculator->workedMinutes($result, $schedule->noBreak);
        $status = $this->statusResolver->resolve($result, $late, $undertime, $schedule->noBreak, $schedule->punchRequirement);

        return [
            'am_in' => $this->fmt($result->slot('am_in')),
            'am_out' => $this->fmt($result->slot('am_out')),
            'pm_in' => $this->fmt($result->slot('pm_in')),
            'pm_out' => $this->fmt($result->slot('pm_out')),
            'time_in_ot' => $this->fmt($result->slot('ot_in')),
            'time_out_ot' => $this->fmt($result->slot('ot_out')),
            'late_minutes' => $late,
            'undertime_minutes' => $undertime,
            'overtime_minutes' => $overtime,
            'worked_minutes' => $workedMinutes,
            'hours_worked' => round($workedMinutes / 60, 2),
            'status' => $status->value,
            'unmatched' => array_map(fn (Carbon $c) => $c->format('H:i:s'), $result->unmatched),
        ];
    }

    /**
     * Display/reporting-only estimate for a missing arrival punch, covering
     * both schedule shapes. An uncertain START of a segment (an IN punch
     * missing while its OUT sibling proves the segment happened) is always
     * charged here, as late - never as undertime, which is reserved for an
     * uncertain END of a segment (see imputedUndertimeMinutes()).
     *
     *  - With-break (4-slot): sums two independent components, mirroring
     *    LateCalculator's own additive am_in/pm_in formula -
     *      AM In missing, AM Out present -> impute workStart→morningEnd.
     *      PM In missing, PM Out present -> impute lunchReturn→workEnd.
     *    Either, both, or neither may apply on a given day.
     *  - No-break (2-slot, punchRequirement 'both'): there is no AM Out/PM In
     *    at all, so the only possible sibling proof is PM Out - when it
     *    exists but AM In never got recorded, charge the full
     *    workStart→workEnd span (a 2-punch schedule has no half-day midpoint
     *    to anchor to).
     *
     * Never applies to an in_only/out_only (Field Work Shift) schedule, even
     * if noBreak also happens to be true on that row: that shape expects
     * exactly ONE punch for the date, so there is no sibling-proves-presence
     * concept - its own absence/confirmation logic belongs entirely to
     * WeeklyPunchPairReconciliationService. Guarded explicitly rather than
     * relying on those schedules' columns happening to stay null, since an
     * out_only day genuinely populates time_out_pm.
     *
     * Unlike resolve(), this never runs at import time and is never
     * persisted - each sibling punch already existing is itself the "this
     * segment is over" signal, so no separate now() gate is needed the way
     * imputedUndertimeMinutes() needs one.
     *
     * $coveredSlots lists slot keys ('am_in'|'pm_in') already known to be
     * explained by something else (Locator/DtrExcuse/WorkSuspension) -
     * callers must gate per-component this way rather than accepting or
     * rejecting the whole return value based on a single slot's coverage,
     * since the two components are independent and a source can explain
     * only one of them (e.g. a Locator window covering only 'pm_in' must not
     * suppress a genuine, unrelated 'am_in' lateness, and vice versa).
     *
     * $firedComponents is an out-param: filled with whichever slot key(s)
     * ('am_in'|'pm_in') actually contributed a non-zero component on this
     * call, so a caller needing per-cell attribution (the DTR page) can
     * highlight exactly the slot(s) responsible instead of collapsing the
     * whole summed total into a single "something was imputed" flag - a
     * caller that did that previously mislabeled a pm_in-only gap as an
     * am_in problem. The no-break single-span case reports 'am_in' (its own
     * semantic anchor, matching the return-0 guard's own coveredSlots check
     * above). Always reset to [] at the top of this call.
     */
    public function imputedLateMinutes(?string $timeInAm, ?string $timeOutAm, ?string $timeInPm, ?string $timeOutPm, string $shiftDate, WorkSchedule $schedule, array $coveredSlots = [], ?array &$firedComponents = null): int
    {
        $firedComponents = [];

        if ($schedule->punchRequirement !== 'both') {
            return 0;
        }

        if ($schedule->noBreak) {
            if ($timeInAm || ! $timeOutPm || in_array('am_in', $coveredSlots, true)) {
                return 0;
            }

            $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
            $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);
            $firedComponents[] = 'am_in';

            return (int) $startRef->diffInMinutes($endRef);
        }

        $minutes = 0;

        if (! $timeInAm && $timeOutAm && ! in_array('am_in', $coveredSlots, true)) {
            $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
            $breakOutRef = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);
            $minutes += (int) $startRef->diffInMinutes($breakOutRef);
            $firedComponents[] = 'am_in';
        }

        if (! $timeInPm && $timeOutPm && ! in_array('pm_in', $coveredSlots, true)) {
            $lunchReturnRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);
            $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);
            $minutes += (int) $lunchReturnRef->diffInMinutes($endRef);
            $firedComponents[] = 'pm_in';
        }

        return $minutes;
    }

    /**
     * Display/reporting-only estimate for a missing departure punch,
     * covering both schedule shapes. An uncertain END of a segment (an OUT
     * punch missing while its IN sibling proves the segment started) is
     * always charged here, as undertime - the mirror of
     * imputedLateMinutes().
     *
     *  - With-break (4-slot): sums two independent components, mirroring
     *    UndertimeCalculator's own additive am_out/pm_out formula -
     *      AM Out missing, AM In present -> impute workStart→morningEnd,
     *      once the AM half's own window (morningEnd) has passed - can't
     *      assume "missing" before lunch would even have started.
     *      PM Out missing, PM In present -> impute lunchReturn→workEnd,
     *      once the shift's own end has passed.
     *    Either, both, or neither may apply on a given day.
     *  - No-break (2-slot, punchRequirement 'both'): there is no PM In/AM Out
     *    at all, so the only possible sibling proof is AM In - when it
     *    exists but PM Out never got recorded, charge the full
     *    workStart→workEnd span.
     *
     * Never applies to an in_only/out_only schedule (see
     * imputedLateMinutes()).
     *
     * $coveredSlots lists slot keys ('am_out'|'pm_out') already known to be
     * explained by something else - see imputedLateMinutes()'s docblock for
     * why this must gate each component independently rather than accepting
     * or rejecting the whole return value on a single slot's coverage. This
     * is what a Locator/DtrExcuse/WorkSuspension covering only 'am_out'
     * (e.g. a morning personal errand) must pass so a genuine, unrelated
     * 'pm_out' undertime figure is never suppressed by it, and vice versa -
     * previously a Locator covering only 'am_out' let this function's
     * 'am_out' component through mislabeled as undertime on a day the
     * employee's actual PM Out was on time.
     *
     * $firedComponents is an out-param, mirror of imputedLateMinutes()'s -
     * filled with whichever slot key(s) ('am_out'|'pm_out') actually
     * contributed a non-zero component on this call. The no-break single-span
     * case reports 'pm_out' (its own semantic anchor). Always reset to []
     * at the top of this call.
     */
    public function imputedUndertimeMinutes(?string $timeInAm, ?string $timeOutAm, ?string $timeInPm, ?string $timeOutPm, string $shiftDate, WorkSchedule $schedule, array $coveredSlots = [], ?array &$firedComponents = null): int
    {
        $firedComponents = [];

        if ($schedule->punchRequirement !== 'both') {
            return 0;
        }

        if ($schedule->noBreak) {
            if (! $timeInAm || $timeOutPm || in_array('pm_out', $coveredSlots, true)) {
                return 0;
            }

            $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

            if (Carbon::now()->lt($endRef)) {
                return 0;
            }

            $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
            $firedComponents[] = 'pm_out';

            return (int) $startRef->diffInMinutes($endRef);
        }

        $minutes = 0;

        if ($timeInAm && ! $timeOutAm && ! in_array('am_out', $coveredSlots, true)) {
            $breakOutRef = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);

            if (Carbon::now()->gte($breakOutRef)) {
                $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
                $minutes += (int) $startRef->diffInMinutes($breakOutRef);
                $firedComponents[] = 'am_out';
            }
        }

        if ($timeInPm && ! $timeOutPm && ! in_array('pm_out', $coveredSlots, true)) {
            $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

            if (Carbon::now()->gte($endRef)) {
                $breakInRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);
                $minutes += (int) $breakInRef->diffInMinutes($endRef);
                $firedComponents[] = 'pm_out';
            }
        }

        return $minutes;
    }

    private function fmt(?Carbon $time): ?string
    {
        return $time?->format('H:i:s');
    }
}
