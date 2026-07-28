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
            // What kind of formula this row uses: flat/percentage/bracket -
            // independent of mandatory_key, so any of the 4 system rows can
            // be switched to a different computation method entirely (e.g.
            // Pag-IBIG moving from a flat contribution to a percentage-based
            // one) without a code change. See PayrollComputationService::
            // computeMandatoryAmount().
            $table->string('computation_type')->nullable()->after('mandatory_key');
        });

        $defaults = [
            'gsis' => 'percentage',
            'philhealth' => 'percentage',
            'pagibig' => 'flat',
            'bir' => 'bracket',
        ];

        foreach ($defaults as $key => $type) {
            DB::table('deductions')
                ->where('mandatory_key', $key)
                ->whereNull('computation_type')
                ->update(['computation_type' => $type]);
        }

        // PayrollComputationService no longer hardcodes PhilHealth's 50/50
        // employer/employee split - the stored rate must now BE the
        // employee's own share directly. Correct the 0.05 "combined rate"
        // default seeded by the previous migration down to 0.025 so computed
        // payslips are unchanged by this refactor. Guarded on the rate still
        // being exactly the original seeded value, so a rate already edited
        // via the UI (extremely unlikely this soon, but checked anyway) is
        // left untouched.
        $philhealth = DB::table('deductions')->where('mandatory_key', 'philhealth')->first();
        if ($philhealth && $philhealth->mandatory_config) {
            $config = json_decode($philhealth->mandatory_config, true);
            if (($config['rate'] ?? null) === 0.05) {
                $config['rate'] = 0.025;
                DB::table('deductions')->where('id', $philhealth->id)->update(['mandatory_config' => json_encode($config)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('deductions', function (Blueprint $table) {
            $table->dropColumn('computation_type');
        });
    }
};
