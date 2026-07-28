<?php

namespace App\Services;

use App\Models\User;
use App\Models\WithholdingTax;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Bulk-imports a year's worth of already-computed monthly withholding tax
 * (EmpNo, Name, Jan..Dec) into WithholdingTax rows - see "Replace computed
 * BIR withholding tax with an Accounting-uploaded monthly table". Mirrors
 * LoanBillingImportService's two-layer EmpNo lookup and per-row,
 * non-aborting error-collection pattern.
 */
class WithholdingTaxImportService
{
    /**
     * @return array{created: int, updated: int, unmatched: array<int, string>, mismatchedNames: array<int, string>}
     */
    public function import(int $year, UploadedFile $file, User $actor): array
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
        // convention as LoanBillingImportService/PersonnelLogImportService.
        $users = User::whereNotNull('EmpNo')->where('EmpNo', '!=', '')->get(['id', 'EmpNo']);
        $exactMap = $users->keyBy('EmpNo');
        $strippedMap = $users->keyBy(fn (User $u) => ltrim((string) $u->EmpNo, '0') ?: '0');

        foreach ($rows->skip(2) as $row) {
            $empNo = trim((string) ($row[0] ?? ''));

            if ($empNo === '') {
                continue;
            }

            $name = trim((string) ($row[1] ?? ''));
            $user = $exactMap->get($empNo) ?? $strippedMap->get(ltrim($empNo, '0') ?: '0');

            if (! $user) {
                $unmatched[] = $empNo;

                continue;
            }

            if ($name !== '' && strcasecmp($name, (string) $user->name) !== 0) {
                $mismatchedNames[] = "{$empNo} (file: \"{$name}\", system: \"{$user->name}\")";
            }

            for ($month = 1; $month <= 12; $month++) {
                $raw = trim((string) ($row[$month + 1] ?? ''));

                if ($raw === '') {
                    continue;
                }

                $withholdingTax = WithholdingTax::firstOrNew([
                    'employee_id' => $user->id,
                    'year' => $year,
                    'month' => $month,
                ]);
                $wasNew = ! $withholdingTax->exists;

                $withholdingTax->amount = (float) $raw;
                $withholdingTax->uploaded_by = $actor->id;
                $withholdingTax->save();

                $wasNew ? $created++ : $updated++;
            }
        }

        return compact('created', 'updated', 'unmatched', 'mismatchedNames');
    }
}
