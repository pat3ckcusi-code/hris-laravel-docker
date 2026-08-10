<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptPayrollMonetaryData extends Command
{
    protected $signature = 'payroll:encrypt-monetary-data {--dry-run : Preview counts without writing} {--table= : Limit to one table}';

    protected $description = 'One-time, re-runnable backfill that encrypts every payroll monetary column at rest, ahead of App\Casts\EncryptedDecimal/EncryptedArray being wired onto the models';

    /**
     * table => [decimal columns] / [json columns holding nested monetary amounts].
     * Bypasses Eloquent entirely (raw DB::table reads/writes) so this never
     * fights the casts it's installing.
     */
    private const TABLES = [
        'payroll_details' => [
            'decimal' => ['basic_salary', 'gross_pay', 'earnings', 'deductions', 'gsis_deduction', 'philhealth_deduction', 'pagibig_deduction', 'bir_deduction', 'lwop_deduction', 'loan_deduction', 'other_deductions', 'net_pay'],
            'json' => ['basic_salary_breakdown', 'deduction_breakdown'],
        ],
        'payslips' => [
            'decimal' => ['basic_salary', 'gross_pay', 'mandatory_deductions', 'loan_deduction', 'other_deductions', 'lwop_deduction', 'total_deductions', 'net_pay'],
            'json' => ['deduction_breakdown'],
        ],
        'loans' => [
            'decimal' => ['balance', 'monthly_payment'],
            'json' => [],
        ],
        'loan_billing_history' => [
            'decimal' => ['balance', 'monthly_payment'],
            'json' => [],
        ],
        'payroll_loan_deductions' => [
            'decimal' => ['amount', 'balance_before', 'balance_after'],
            'json' => [],
        ],
        'withholding_taxes' => [
            'decimal' => ['amount'],
            'json' => [],
        ],
        'employee_earnings' => [
            'decimal' => ['amount'],
            'json' => [],
        ],
        'employee_deductions' => [
            'decimal' => ['amount'],
            'json' => [],
        ],
        'salary_matrices' => [
            'decimal' => ['amount'],
            'json' => [],
        ],
        'deductions' => [
            'decimal' => [],
            'json' => ['mandatory_config'],
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyTable = $this->option('table');

        if ($onlyTable !== null && ! array_key_exists($onlyTable, self::TABLES)) {
            $this->error("Unknown table '{$onlyTable}'. Known tables: ".implode(', ', array_keys(self::TABLES)));

            return self::FAILURE;
        }

        $tables = $onlyTable !== null ? [$onlyTable => self::TABLES[$onlyTable]] : self::TABLES;

        $grandTotals = ['encrypted' => 0, 'already_encrypted' => 0, 'skipped_null_or_invalid' => 0];

        foreach ($tables as $table => $columns) {
            $totals = ['encrypted' => 0, 'already_encrypted' => 0, 'skipped_null_or_invalid' => 0];

            $run = function () use ($table, $columns, $dryRun, &$totals) {
                DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table, $columns, $dryRun, &$totals) {
                    foreach ($rows as $row) {
                        $updates = [];

                        foreach ($columns['decimal'] as $column) {
                            $this->processDecimalColumn($row->{$column}, $updates, $column, $totals);
                        }

                        foreach ($columns['json'] as $column) {
                            $this->processJsonColumn($row->{$column}, $updates, $column, $totals);
                        }

                        if (! $dryRun && ! empty($updates)) {
                            DB::table($table)->where('id', $row->id)->update($updates);
                        }
                    }
                });
            };

            if ($dryRun) {
                $run();
            } else {
                DB::transaction($run);
            }

            $mode = $dryRun ? '[DRY RUN] Would encrypt' : 'Encrypted';
            $this->line(sprintf(
                '%s — %s %d row-column(s); already encrypted: %d; skipped (null/invalid): %d',
                $table,
                $mode,
                $totals['encrypted'],
                $totals['already_encrypted'],
                $totals['skipped_null_or_invalid']
            ));

            foreach ($totals as $key => $value) {
                $grandTotals[$key] += $value;
            }
        }

        $this->newLine();
        $mode = $dryRun ? '[DRY RUN] Would encrypt' : 'Encrypted';
        $this->info(sprintf(
            '%s %d row-column(s) total. Already encrypted: %d. Skipped: %d.',
            $mode,
            $grandTotals['encrypted'],
            $grandTotals['already_encrypted'],
            $grandTotals['skipped_null_or_invalid']
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  array{encrypted: int, already_encrypted: int, skipped_null_or_invalid: int}  $totals
     */
    private function processDecimalColumn(mixed $raw, array &$updates, string $column, array &$totals): void
    {
        if ($raw === null || $raw === '') {
            return;
        }

        try {
            // Decryptable already => this row was already encrypted by a prior run.
            Crypt::decryptString($raw);
            $totals['already_encrypted']++;

            return;
        } catch (DecryptException) {
            // Falls through — treat as legacy plaintext.
        }

        if (! is_numeric($raw)) {
            $totals['skipped_null_or_invalid']++;

            return;
        }

        $updates[$column] = Crypt::encryptString(number_format((float) $raw, 2, '.', ''));
        $totals['encrypted']++;
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  array{encrypted: int, already_encrypted: int, skipped_null_or_invalid: int}  $totals
     */
    private function processJsonColumn(mixed $raw, array &$updates, string $column, array &$totals): void
    {
        if ($raw === null || $raw === '') {
            return;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $totals['skipped_null_or_invalid']++;

            return;
        }

        if (is_array($decoded) && array_key_exists('encrypted', $decoded) && is_string($decoded['encrypted'])) {
            // Already wrapped by a prior run.
            $totals['already_encrypted']++;

            return;
        }

        $updates[$column] = json_encode(['encrypted' => Crypt::encryptString($raw)]);
        $totals['encrypted']++;
    }
}
