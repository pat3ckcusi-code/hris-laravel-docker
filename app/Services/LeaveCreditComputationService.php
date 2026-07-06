<?php

namespace App\Services;

use App\Models\ComputationTableLwp;
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
        $remarks = null;

        // Both VL and SL earn identically off Table II (computation_table_lwp), keyed by
        // days_present — which the caller already nets down by non-illness LWOP days.
        // abs_wop_days only distinguishes the transaction label below, it doesn't select a
        // different formula (SL is not zeroed during a month with LWOP).
        $daysPresent = (int) round($attendance->days_present);
        $daysPresent = max(0, min(30, $daysPresent));

        $row = ComputationTableLwp::find($daysPresent);
        $credit = $row ? (float) $row->credit_earned : 0.0;

        $vlEarned = $credit;
        $slEarned = $credit;
        $transactionType = (float) $attendance->abs_wop_days > 0 ? 'CREDIT_EARNED_WOP' : 'CREDIT_EARNED';

        // Part-time ratio override.
        if ($user->employee_type === 'part_time' && $user->hours_per_day !== null) {
            $ratio = (float) $user->hours_per_day / 8.0;
            $vlEarned = round($vlEarned * $ratio, 3);
            $slEarned = round($slEarned * $ratio, 3);
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
