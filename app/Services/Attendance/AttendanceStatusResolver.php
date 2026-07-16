<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;

/**
 * Derives the day's attendance status from the punch STRUCTURE the matcher
 * produced (which slots have punches), falling back to the penalty totals
 * only when the structure is complete. Structural problems outrank minutes:
 * a half day with a late arrival is still Half Day AM.
 *
 * Only ever returns the eight punch-path statuses. Absent stays derived at
 * read time from the absence of a dtrs row (this resolver is only reached
 * when punches exist), and Leave/Holiday/Official Business are display-layer
 * overlays from their own tables - see AttendanceStatus.
 */
class AttendanceStatusResolver
{
    public function resolve(MatchResult $result, int $lateMinutes, int $undertimeMinutes, bool $noBreak): AttendanceStatus
    {
        $amIn = $result->has('am_in');
        $amOut = $result->has('am_out');
        $pmIn = $result->has('pm_in');
        $pmOut = $result->has('pm_out');

        if ($noBreak) {
            // Two-punch convention: am_in = shift IN, pm_out = shift OUT.
            return match (true) {
                $amIn && $pmOut => $this->scored($lateMinutes, $undertimeMinutes),
                $amIn => AttendanceStatus::MissingOut,
                $pmOut => AttendanceStatus::MissingIn,
                default => AttendanceStatus::Incomplete,
            };
        }

        if ($amIn && $amOut && $pmIn && $pmOut) {
            return $this->scored($lateMinutes, $undertimeMinutes);
        }

        if ($amIn && $amOut && ! $pmIn && ! $pmOut) {
            return AttendanceStatus::HalfDayAm;
        }

        if (! $amIn && ! $amOut && $pmIn && $pmOut) {
            return AttendanceStatus::HalfDayPm;
        }

        $missingIn = ($amOut && ! $amIn) || ($pmOut && ! $pmIn);
        $missingOut = ($amIn && ! $amOut) || ($pmIn && ! $pmOut);

        return match (true) {
            $missingIn && $missingOut => AttendanceStatus::Incomplete,
            $missingIn => AttendanceStatus::MissingIn,
            $missingOut => AttendanceStatus::MissingOut,
            // No punches at all - defensive only; the import path never
            // persists this case (a punchless day gets no dtrs row).
            default => AttendanceStatus::Incomplete,
        };
    }

    private function scored(int $lateMinutes, int $undertimeMinutes): AttendanceStatus
    {
        return match (true) {
            $lateMinutes > 0 => AttendanceStatus::Late,
            $undertimeMinutes > 0 => AttendanceStatus::Undertime,
            default => AttendanceStatus::Present,
        };
    }
}
