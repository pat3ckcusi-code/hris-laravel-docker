<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            // Day-weighted itemization of every SalaryMatrix tranche that
            // contributed to basic_salary this period, for a run whose
            // period spans a tranche's effective_date - see getBasicSalary().
            // salary_matrix_id keeps pointing to the LAST (period-end)
            // segment's tranche; this column is the full breakdown.
            $table->json('basic_salary_breakdown')->nullable()->after('salary_matrix_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropColumn('basic_salary_breakdown');
        });
    }
};
