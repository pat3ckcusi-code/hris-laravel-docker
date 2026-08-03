<?php

namespace App\Services;

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
        $remarks = null;

        // Both VL and SL earn identically off computation_table_wop, keyed by abs_wop_days
        // (rounded to the table's half-day granularity) — SL is not zeroed during a month
        // with LWOP, it just tracks the same schedule as VL. Deliberately keyed on
        // abs_wop_days alone, not days_present: a mid-month hire's shortened baseline
        // (LwopAggregationService::computeForMonth() prorates days_present for a partial
        // month) does not reduce credit here — only actual unauthorized absence
        // (non-illness LWOP + AWOL) does. The table's rows stop at 29.50 (no 30.00 row) so
        // a full month of WOP deliberately misses the lookup and falls through to 0 credit
        // below, rather than being clamped into matching 29.50's rate.
        $absWop = round(((float) $attendance->abs_wop_days) * 2) / 2;
        $absWop = max(0.0, min(30.0, $absWop));

        $row = ComputationTableWop::find($absWop);
        $credit = $row ? (float) $row->vl_earned : 0.0;

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
