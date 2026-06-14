<?php

namespace App\Services;

use App\Models\LeaveBalance;
use App\Models\LeaveLedger;
use Illuminate\Support\Collection;

class LeaveLedgerService
{
    /**
     * Write an immutable ledger entry for a leave balance change.
     *
     * Required params: user_id, transaction_date, transaction_type, leave_type,
     *   vl_balance_after and sl_balance_after are computed internally.
     * Optional: debit_vl, debit_sl, credit_vl, credit_sl, days_present,
     *   abs_wop_days, reference_id, reference_type, remarks, created_by, is_system.
     */
    public function writeLedgerEntry(array $params): LeaveLedger
    {
        $userId = $params['user_id'];

        [$currentVl, $currentSl] = $this->resolveCurrentBalance($userId);

        $debitVl = (float) ($params['debit_vl'] ?? 0);
        $debitSl = (float) ($params['debit_sl'] ?? 0);
        $creditVl = (float) ($params['credit_vl'] ?? 0);
        $creditSl = (float) ($params['credit_sl'] ?? 0);

        $vlAfter = round($currentVl + $creditVl - $debitVl, 3);
        $slAfter = round($currentSl + $creditSl - $debitSl, 3);

        return LeaveLedger::create([
            'user_id' => $userId,
            'transaction_date' => $params['transaction_date'],
            'transaction_type' => $params['transaction_type'],
            'leave_type' => $params['leave_type'],
            'days_present' => $params['days_present'] ?? null,
            'abs_wop_days' => $params['abs_wop_days'] ?? null,
            'debit_vl' => $debitVl,
            'debit_sl' => $debitSl,
            'credit_vl' => $creditVl,
            'credit_sl' => $creditSl,
            'vl_balance_after' => $vlAfter,
            'sl_balance_after' => $slAfter,
            'reference_id' => $params['reference_id'] ?? null,
            'reference_type' => $params['reference_type'] ?? null,
            'remarks' => $params['remarks'] ?? null,
            'created_by' => $params['created_by'] ?? null,
            'is_system' => $params['is_system'] ?? false,
        ]);
    }

    /**
     * Return the current VL and SL balance for an employee.
     * Falls back to leave_balances table if no ledger history exists yet.
     *
     * @return array{float, float} [vl, sl]
     */
    public function getCurrentBalance(int $userId): array
    {
        return $this->resolveCurrentBalance($userId);
    }

    /**
     * Return ledger rows for an employee, newest first.
     * Filters: year (int), transaction_type (string), leave_type (string).
     */
    public function getLedgerHistory(int $userId, array $filters = []): Collection
    {
        $query = LeaveLedger::where('user_id', $userId);

        if (isset($filters['year'])) {
            $query->whereYear('transaction_date', $filters['year']);
        }
        if (isset($filters['transaction_type'])) {
            $query->where('transaction_type', $filters['transaction_type']);
        }
        if (isset($filters['leave_type'])) {
            $query->where('leave_type', $filters['leave_type']);
        }

        return $query->orderByDesc('created_at')->get();
    }

    /**
     * Return total VL and SL credits earned (CREDIT_EARNED / CREDIT_EARNED_WOP) for a given year.
     *
     * @return array{vl: float, sl: float}
     */
    public function getYearlyEarned(int $userId, int $year): array
    {
        $row = LeaveLedger::where('user_id', $userId)
            ->whereIn('transaction_type', ['CREDIT_EARNED', 'CREDIT_EARNED_WOP'])
            ->whereYear('transaction_date', $year)
            ->selectRaw('SUM(credit_vl) as vl, SUM(credit_sl) as sl')
            ->first();

        return [
            'vl' => (float) ($row->vl ?? 0),
            'sl' => (float) ($row->sl ?? 0),
        ];
    }

    /**
     * Return total VL days used (LEAVE_USED, leave_type=VL) for a given year.
     * Useful for forced-leave compliance checks.
     */
    public function getVLUsedThisYear(int $userId, int $year): float
    {
        return (float) LeaveLedger::where('user_id', $userId)
            ->where('transaction_type', 'LEAVE_USED')
            ->where('leave_type', 'VL')
            ->whereYear('transaction_date', $year)
            ->sum('debit_vl');
    }

    /**
     * Resolve the most recent running balance from the ledger, or fall back
     * to the leave_balances table for employees with no ledger history yet.
     *
     * @return array{float, float} [vl, sl]
     */
    private function resolveCurrentBalance(int $userId): array
    {
        $last = LeaveLedger::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->select(['vl_balance_after', 'sl_balance_after'])
            ->first();

        if ($last !== null) {
            return [(float) $last->vl_balance_after, (float) $last->sl_balance_after];
        }

        // No ledger history — seed from the existing leave_balances table.
        $balance = LeaveBalance::where('user_id', $userId)->first();

        return [
            (float) ($balance?->VL ?? 0),
            (float) ($balance?->SL ?? 0),
        ];
    }
}
