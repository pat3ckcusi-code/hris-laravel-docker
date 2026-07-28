<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deductions', function (Blueprint $table) {
            // Stable identity for the 4 government-mandated deduction types
            // (gsis/philhealth/pagibig/bir). Only ever set on the seeded rows
            // below - never user-editable via the catalog form - so it's what
            // lets a Payroll Manager freely rename/re-describe a mandatory row
            // without breaking PayrollComputationService's ability to find it.
            $table->string('mandatory_key')->nullable()->unique()->after('provider');

            // Structured rate config, shape depends on mandatory_key:
            //   gsis:       {"rate": 0.09}
            //   philhealth: {"rate": 0.05, "floor": 400, "ceiling": 3750}
            //   pagibig:    {"amount": 100}
            //   bir:        {"brackets": [{"min","max","base","rate"}, ...]}
            $table->json('mandatory_config')->nullable()->after('mandatory_key');
        });

        // Seed the 4 system rows with the exact values PayrollComputationService
        // hardcoded before this migration, so payroll output is unchanged until
        // someone edits a rate via the new Deductions page UI. firstOrCreate-style
        // (via a manual existence check) keyed on mandatory_key keeps this
        // idempotent and safe to re-run.
        $now = now();
        $birBrackets = [
            ['min' => 0,      'max' => 10417,  'base' => 0.00,      'rate' => 0.00],
            ['min' => 10417,  'max' => 16667,  'base' => 0.00,      'rate' => 0.15],
            ['min' => 16667,  'max' => 33333,  'base' => 937.50,    'rate' => 0.20],
            ['min' => 33333,  'max' => 83333,  'base' => 4270.83,   'rate' => 0.25],
            ['min' => 83333,  'max' => 333333, 'base' => 16770.83,  'rate' => 0.30],
            ['min' => 333333, 'max' => null,   'base' => 91770.83,  'rate' => 0.35],
        ];

        $rows = [
            ['mandatory_key' => 'gsis', 'type' => 'Life & Retirement', 'provider' => 'GSIS', 'mandatory_config' => ['rate' => 0.09]],
            ['mandatory_key' => 'philhealth', 'type' => 'Medicare', 'provider' => 'PhilHealth', 'mandatory_config' => ['rate' => 0.05, 'floor' => 400.00, 'ceiling' => 3750.00]],
            ['mandatory_key' => 'pagibig', 'type' => 'HDMF (Pag-ibig)', 'provider' => 'Pag-IBIG', 'mandatory_config' => ['amount' => 100.00]],
            ['mandatory_key' => 'bir', 'type' => 'Withholding Tax', 'provider' => 'BIR', 'mandatory_config' => ['brackets' => $birBrackets]],
        ];

        foreach ($rows as $row) {
            $exists = DB::table('deductions')->where('mandatory_key', $row['mandatory_key'])->exists();
            if (! $exists) {
                DB::table('deductions')->insert([
                    'type' => $row['type'],
                    'deduction_category' => 'mandatory',
                    'provider' => $row['provider'],
                    'mandatory_key' => $row['mandatory_key'],
                    'mandatory_config' => json_encode($row['mandatory_config']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('deductions')->whereNotNull('mandatory_key')->delete();

        Schema::table('deductions', function (Blueprint $table) {
            $table->dropColumn(['mandatory_key', 'mandatory_config']);
        });
    }
};
