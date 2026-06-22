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
            'gsis_premium_rate'  => '0.09',
            'philhealth_rate'    => '0.05',
            'philhealth_floor'   => '400.00',
            'philhealth_ceiling' => '3750.00',
            'pagibig_amount'     => '100.00',
            'bir_tax_brackets'   => json_encode([
                ['min' => 0,      'max' => 10417,  'base' => 0.00,      'rate' => 0.00],
                ['min' => 10417,  'max' => 16667,  'base' => 0.00,      'rate' => 0.15],
                ['min' => 16667,  'max' => 33333,  'base' => 937.50,    'rate' => 0.20],
                ['min' => 33333,  'max' => 83333,  'base' => 4270.83,   'rate' => 0.25],
                ['min' => 83333,  'max' => 333333, 'base' => 16770.83,  'rate' => 0.30],
                ['min' => 333333, 'max' => null,   'base' => 91770.83,  'rate' => 0.35],
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
