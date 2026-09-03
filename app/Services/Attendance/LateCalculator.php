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
 *
 * A Single Punch Shift (punchRequirement 'am_in_only_graded') is the one
 * exception to both rules above: only AM In is ever graded (the pm_in term is
 * skipped entirely, even on a schedule with a real break), and a missing AM In
 * is NOT scored as zero - the earliest of am_out/pm_in/pm_out (whichever was
 * actually punched) stands in for it, since a punch anywhere else that day is
 * proof of presence and the employee is graded as arriving at that time
 * instead. A day with truly zero punches never reaches this calculator at all
 * (no dtrs row is created for it), so this substitution never has to consider
 * that case.
 */
class LateCalculator
{
    public function minutes(MatchResult $result, string $shiftDate, WorkSchedule $schedule): int
    {
        $late = 0;
        $isSinglePunchGraded = $schedule->punchRequirement === 'am_in_only_graded';

        $amIn = $result->slot('am_in');
        if ($amIn === null && $isSinglePunchGraded) {
            $amIn = collect([$result->slot('am_out'), $result->slot('pm_in'), $result->slot('pm_out')])
                ->filter()
                ->sortBy(fn ($t) => $t->timestamp)
                ->first();
        }

        if ($amIn !== null) {
            $startRef = $schedule->referenceDateTime($shiftDate, $schedule->workStart, isShiftStart: true);

            if ($amIn->gt($startRef)) {
                $late += (int) $startRef->diffInMinutes($amIn);
            }
        }

        $pmIn = $result->slot('pm_in');
        if ($pmIn !== null && ! $schedule->noBreak && ! $isSinglePunchGraded) {
            $lunchReturnRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);

            if ($pmIn->gt($lunchReturnRef)) {
                $late += (int) $lunchReturnRef->diffInMinutes($pmIn);
            }
        }

        return $late;
    }
}
