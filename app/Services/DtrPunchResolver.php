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
        $status = $this->statusResolver->resolve($result, $late, $undertime, $schedule->noBreak);

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
     * Display/reporting-only estimate for a missing arrival punch: when the
     * AM Out punch exists (proving the employee was there that morning) but
     * AM In never got recorded, charge the full workStart→morningEnd block as
     * late rather than leaving it at 0. Unlike resolve(), this never runs at
     * import time and is never persisted - the AM Out punch already existing
     * is itself the "this half-day is over" signal, so no separate now()
     * gate is needed the way imputedUndertimeMinutes() needs one.
     */
    public function imputedLateMinutes(?string $timeInAm, ?string $timeOutAm, string $shiftDate, WorkSchedule $schedule): int
    {
        if ($timeInAm || ! $timeOutAm) {
            return 0;
        }

        $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);
        $breakOutRef = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);

        return (int) $startRef->diffInMinutes($breakOutRef);
    }

    /**
     * Display/reporting-only estimate for a missing departure punch: when PM
     * In exists but PM Out never got recorded, charge the full
     * lunchReturn→workEnd block as undertime rather than leaving it at 0.
     * Gated on the shift having already ended, since (unlike resolve(), which
     * only ever runs once punches exist) this may be evaluated mid-shift and
     * can't assume a punch is "missing" before its window has passed.
     */
    public function imputedUndertimeMinutes(?string $timeInPm, ?string $timeOutPm, string $shiftDate, WorkSchedule $schedule): int
    {
        if (! $timeInPm || $timeOutPm) {
            return 0;
        }

        $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

        if (Carbon::now()->lt($endRef)) {
            return 0;
        }

        $breakInRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);

        return (int) $breakInRef->diffInMinutes($endRef);
    }

    private function fmt(?Carbon $time): ?string
    {
        return $time?->format('H:i:s');
    }
}
