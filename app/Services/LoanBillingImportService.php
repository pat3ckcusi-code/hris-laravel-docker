<?php

namespace App\Services;

use App\Models\Deduction;
use App\Models\Loan;
use App\Models\LoanBillingHistory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bulk-imports a provider's monthly loan billing (EmpNo, Monthly Payment,
 * Balance) into Loan rows plus a real per-month LoanBillingHistory snapshot -
 * see "Monthly loan billing upload, with real per-month history". Mirrors
 * the per-row, non-aborting error-collection pattern already used by
 * RecordsManagerController::import() and PersonnelLogImportService.
 */
class LoanBillingImportService
{
    /**
     * @return array{created: int, updated: int, unmatched: array<int, string>, mismatchedNames: array<int, string>}
     */
    public function import(Deduction $deduction, UploadedFile $file, User $actor, string $billingMonth): array
    {
        $rows = Excel::toCollection(null, $file)->first();

        $created = 0;
        $updated = 0;
        $unmatched = [];
        $mismatchedNames = [];

        if ($rows === null || $rows->isEmpty()) {
            return compact('created', 'updated', 'unmatched', 'mismatchedNames');
        }

        // Two-layer EmpNo lookup (exact, then zero-stripped fallback) - same
        // convention as PersonnelLogImportService's biometric EmpNo matching,
        // so a billing file with '02009' or '2009' both resolve correctly.
        $users = User::whereNotNull('EmpNo')->where('EmpNo', '!=', '')->get(['id', 'EmpNo']);
        $exactMap = $users->keyBy('EmpNo');
        $strippedMap = $users->keyBy(fn (User $u) => ltrim((string) $u->EmpNo, '0') ?: '0');

        $monthDate = Carbon::createFromFormat('Y-m', $billingMonth)->startOfMonth()->toDateString();

        foreach ($rows->skip(2) as $row) {
            $empNo = trim((string) ($row[0] ?? ''));

            // The template no longer generates a SAMPLE row, but a legacy
            // downloaded copy might still have one - harmless to leave in an
            // uploaded file, since it's never treated as real data.
            if (strcasecmp($empNo, 'SAMPLE') === 0) {
                continue;
            }

            $name = trim((string) ($row[1] ?? ''));
            $monthlyPayment = trim((string) ($row[3] ?? ''));
            $balance = trim((string) ($row[4] ?? ''));

            if ($empNo === '' && $monthlyPayment === '' && $balance === '') {
                continue;
            }

            $user = $exactMap->get($empNo) ?? $strippedMap->get(ltrim($empNo, '0') ?: '0');

            if (! $user) {
                $unmatched[] = $empNo;

                continue;
            }

            if ($name !== '' && strcasecmp($name, (string) $user->name) !== 0) {
                $mismatchedNames[] = "{$empNo} (file: \"{$name}\", system: \"{$user->name}\")";
            }

            $balanceValue = (float) $balance;
            $monthlyPaymentValue = (float) $monthlyPayment;

            $loan = Loan::firstOrNew(['employee_id' => $user->id, 'deduction_id' => $deduction->id]);
            $wasNew = ! $loan->exists;

            $loan->balance = $balanceValue;
            $loan->monthly_payment = $monthlyPaymentValue;

            if ($balanceValue <= 0) {
                $loan->status = 'paid';
            } elseif ($wasNew) {
                $loan->status = 'active';
            }

            $loan->save();

            LoanBillingHistory::updateOrCreate(
                ['loan_id' => $loan->id, 'billing_month' => $monthDate],
                ['balance' => $balanceValue, 'monthly_payment' => $monthlyPaymentValue, 'uploaded_by' => $actor->id]
            );

            $wasNew ? $created++ : $updated++;
        }

        return compact('created', 'updated', 'unmatched', 'mismatchedNames');
    }
}
