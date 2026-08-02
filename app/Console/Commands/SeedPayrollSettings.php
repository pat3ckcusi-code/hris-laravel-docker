<?php

namespace App\Console\Commands;

use App\Models\PayrollSetting;
use Illuminate\Console\Command;

class SeedPayrollSettings extends Command
{
    protected $signature = 'payroll:seed-settings {--force : Overwrite existing values}';

    protected $description = 'Seed default signatory names/designations into payroll_settings';

    public function handle(): int
    {
        // Read by PayrollFormExportService::applySignatoryOverrides() - overrides the
        // name/designation printed on the General Payroll export. Defaults below match
        // whatever is currently baked into the template file itself, so seeding this
        // doesn't change anything until a Payroll Manager edits it here.
        $defaults = [
            'payroll_signatory_mayor_name' => 'ATTY. DOY C. LEACHON',
            'payroll_signatory_mayor_designation' => 'City Mayor',
            'payroll_signatory_accountant_name' => 'EDGARDO C. BASILAN',
            'payroll_signatory_accountant_designation' => 'City Accountant',
            'payroll_signatory_treasurer_name' => 'NICASIO D. CATAPANG',
            'payroll_signatory_treasurer_designation' => 'City Treasurer',
            'payroll_signatory_cash_clerk_names' => 'MARIAN  M. ALBO/GLENDA T. GO',
            'payroll_signatory_cash_clerk_designation' => 'Cash Clerk II / Disbursing Officer II',
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
