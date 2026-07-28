<?php

namespace App\Console\Commands;

use App\Models\PayrollSetting;
use Illuminate\Console\Command;

class SeedPayrollSettings extends Command
{
    protected $signature = 'payroll:seed-settings {--force : Overwrite existing values}';

    protected $description = 'Seed default mandatory deduction rates into payroll_settings';

    public function handle(): int
    {
        $defaults = [
            'gsis_premium_rate' => '0.09',
            'philhealth_rate' => '0.05',
            'philhealth_floor' => '400.00',
            'philhealth_ceiling' => '3750.00',
            'pagibig_amount' => '100.00',
            'bir_tax_brackets' => json_encode([
                ['min' => 0,      'max' => 20833,  'base' => 0.00,      'rate' => 0.00],
                ['min' => 20833,  'max' => 33333,  'base' => 0.00,      'rate' => 0.15],
                ['min' => 33333,  'max' => 66667,  'base' => 1875.00,   'rate' => 0.20],
                ['min' => 66667,  'max' => 166667, 'base' => 8541.80,   'rate' => 0.25],
                ['min' => 166667, 'max' => 666667, 'base' => 33541.80,  'rate' => 0.30],
                ['min' => 666667, 'max' => null,   'base' => 183541.80, 'rate' => 0.35],
            ]),
        ];

        $force = $this->option('force');

        foreach ($defaults as $key => $value) {
            $exists = PayrollSetting::where('key', $key)->exists();

            if ($exists && ! $force) {
                $this->line("  <comment>skip</comment>  {$key} (already set; use --force to overwrite)");

                continue;
            }

            PayrollSetting::updateOrCreate(['key' => $key], ['value' => $value]);
            $this->line("  <info>set</info>   {$key}");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
