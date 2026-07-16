<?php

namespace App\Services\Attendance;

/**
 * Minutes worked, as the sum of each half-day's matched in -> out span:
 *
 *   worked = (am_out - am_in) + (pm_out - pm_in)
 *
 * A half-day only counts when BOTH of its boundary punches matched - a lone
 * punch proves presence at an instant, not a span. No-break shifts have a
 * single span (pm_out - am_in, per the two-punch slot convention). Raw actual
 * times, deliberately unclamped to the schedule: lateness and undertime are
 * scored separately, and early/late presence is still presence. The matcher's
 * order preservation guarantees every span is non-negative.
 */
class HoursWorkedCalculator
{
    public function workedMinutes(MatchResult $result, bool $noBreak): int
    {
        if ($noBreak) {
            $in = $result->slot('am_in');
            $out = $result->slot('pm_out');

            return ($in !== null && $out !== null) ? (int) $in->diffInMinutes($out) : 0;
        }

        $worked = 0;

        $amIn = $result->slot('am_in');
        $amOut = $result->slot('am_out');
        if ($amIn !== null && $amOut !== null) {
            $worked += (int) $amIn->diffInMinutes($amOut);
        }

        $pmIn = $result->slot('pm_in');
        $pmOut = $result->slot('pm_out');
        if ($pmIn !== null && $pmOut !== null) {
            $worked += (int) $pmIn->diffInMinutes($pmOut);
        }

        return $worked;
    }
}
