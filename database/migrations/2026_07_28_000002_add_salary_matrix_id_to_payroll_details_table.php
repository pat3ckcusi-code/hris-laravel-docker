<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            // Which SalaryMatrix row PayrollComputationService::getBasicSalary()
            // resolved for this employee - null-on-delete so a later matrix row
            // deletion never wipes historical computed payroll data. Nullable
            // since older rows (computed before this column existed) won't have
            // one until the run is recomputed.
            $table->foreignId('salary_matrix_id')->nullable()->after('basic_salary')
                ->constrained('salary_matrices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salary_matrix_id');
        });
    }
};
