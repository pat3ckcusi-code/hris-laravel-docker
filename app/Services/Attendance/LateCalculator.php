<?php

namespace App\Services\Attendance;

use App\Support\WorkSchedule;

/**
 * Lateness in minutes, computed ONLY from the matched IN events:
 *
 *   late = max(am_in - workStart, 0) + max(pm_in - lunchReturn, 0)
 *
 * A missing IN punch contributes nothing here - charging a fixed block for a
 * proven-present-but-unpunched half-day is the display layer's job
 * (DtrPunchResolver::imputedLateMinutes()), never the stored total. Runs only
 * after AttendanceMatcher has fully assigned the punches, so every slot value
 * is already a plausible punch for its role.
 */
class LateCalculator
{
    public function minutes(MatchResult $result, string $shiftDate, WorkSchedule $schedule): int
    {
        $late = 0;

        $amIn = $result->slot('am_in');
        if ($amIn !== null) {
            $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);

            if ($amIn->gt($startRef)) {
                $late += (int) $startRef->diffInMinutes($amIn);
            }
        }

        $pmIn = $result->slot('pm_in');
        if ($pmIn !== null && ! $schedule->noBreak) {
            $lunchReturnRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);

            if ($pmIn->gt($lunchReturnRef)) {
                $late += (int) $lunchReturnRef->diffInMinutes($pmIn);
            }
        }

        return $late;
    }
}
