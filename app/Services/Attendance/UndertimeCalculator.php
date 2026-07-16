<?php

namespace App\Services\Attendance;

use App\Support\WorkSchedule;

/**
 * Undertime in minutes, computed ONLY from the matched OUT events:
 *
 *   undertime = max(morningEnd - am_out, 0) + max(workEnd - pm_out, 0)
 *
 * A missing OUT punch contributes nothing here - the display layer's
 * imputedUndertimeMinutes() covers that case without touching the stored
 * total. The am_out term only applies when the schedule has a genuine break
 * (morningEnd earlier than lunchReturn): on a degenerate template whose break
 * references collapse onto workEnd there is no "left early for lunch" to
 * charge. No-break shifts score the departure against workEnd only.
 */
class UndertimeCalculator
{
    public function minutes(MatchResult $result, string $shiftDate, WorkSchedule $schedule): int
    {
        $undertime = 0;
        $endRef = $schedule->referenceDateTime($shiftDate, $schedule->workEnd);

        $pmOut = $result->slot('pm_out');
        if ($pmOut !== null && $pmOut->lt($endRef)) {
            $undertime += (int) $pmOut->diffInMinutes($endRef);
        }

        $amOut = $result->slot('am_out');
        if ($amOut !== null && ! $schedule->noBreak) {
            $breakOutRef = $schedule->referenceDateTime($shiftDate, $schedule->morningEnd);
            $lunchReturnRef = $schedule->referenceDateTime($shiftDate, $schedule->lunchReturn);

            if ($breakOutRef->lt($lunchReturnRef) && $amOut->lt($breakOutRef)) {
                $undertime += (int) $amOut->diffInMinutes($breakOutRef);
            }
        }

        return $undertime;
    }
}
