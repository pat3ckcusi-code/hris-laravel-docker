<?php

namespace App\Services;

use App\Models\ComputationTableLwp;
use App\Models\ComputationTableWop;
use App\Models\MonthlyAttendance;
use App\Models\User;

class LeaveCreditComputationService
{
    /**
     * Compute monthly leave credits for one employee based on their attendance record.
     *
     * Returns:
     *   vl_earned        float
     *   sl_earned        float
     *   transaction_type 'CREDIT_EARNED' | 'CREDIT_EARNED_WOP'
     *   remarks          string|null
     */
    public function computeMonthlyCredit(User $user, MonthlyAttendance $attendance): array
    {
        $absDays = (float) $attendance->abs_wop_days;
        $remarks = null;

        if ($absDays == 0) {
            $daysPresent = (int) round($attendance->days_present);
            $daysPresent = max(1, min(30, $daysPresent));

            $row = ComputationTableLwp::find($daysPresent);
            $credit = $row ? (float) $row->credit_earned : 0.0;

            $vlEarned = $credit;
            $slEarned = $credit;
            $transactionType = 'CREDIT_EARNED';
        } else {
            // Round abs_wop_days to nearest 0.5 to match table keys.
            $roundedAbs = round($absDays * 2) / 2;
            $roundedAbs = max(0.00, min(29.50, $roundedAbs));

            $row = ComputationTableWop::find($roundedAbs);
            $vlEarned = $row ? (float) $row->vl_earned : 0.0;
            $slEarned = 0.0;
            $transactionType = 'CREDIT_EARNED_WOP';
        }

        // Part-time ratio override.
        if ($user->employee_type === 'part_time' && $user->hours_per_day !== null) {
            $ratio = (float) $user->hours_per_day / 8.0;
            $vlEarned = round($vlEarned * $ratio, 3);
            $slEarned = round($slEarned * $ratio, 3);
        }

        // Sanggunian member override.
        if ($user->is_sanggunian_member) {
            // Full credit when all sessions attended; zero otherwise.
            // session_attendance_complete is not yet a dedicated column; default false.
            $sessionComplete = false;
            $vlEarned = $sessionComplete ? 1.250 : 0.0;
            $slEarned = $sessionComplete ? 1.250 : 0.0;
        }

        // Extended service: same rate but mark as non-commutative.
        if ($user->on_extended_service) {
            $remarks = 'non_commutative';
        }

        return [
            'vl_earned' => round($vlEarned, 3),
            'sl_earned' => round($slEarned, 3),
            'transaction_type' => $transactionType,
            'remarks' => $remarks,
        ];
    }
}
