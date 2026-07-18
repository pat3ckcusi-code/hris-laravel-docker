<?php

namespace App\Services\Attendance;

/**
 * Overtime in minutes, as the span between a dedicated OT In / OT Out punch
 * pair:
 *
 *   overtime = ot_out - ot_in
 *
 * Requires BOTH punches to be matched - a lone OT In with no OT Out (or vice
 * versa) contributes 0, never an imputed/estimated value. ot_in/ot_out are
 * only ever populated by AttendanceMatcher on a Standard Day schedule (see
 * its buildExpectedEvents() docblock), so this calculator needs no schedule
 * awareness of its own - the gate already happened during matching.
 */
class OvertimeCalculator
{
    public function minutes(MatchResult $result): int
    {
        $otIn = $result->slot('ot_in');
        $otOut = $result->slot('ot_out');

        if ($otIn === null || $otOut === null) {
            return 0;
        }

        return (int) $otIn->diffInMinutes($otOut);
    }
}
