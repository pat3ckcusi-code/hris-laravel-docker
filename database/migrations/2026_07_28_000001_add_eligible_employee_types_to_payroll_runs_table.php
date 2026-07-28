<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            // Which HrisConstants::EMPLOYEE_TYPES this run's compute() should
            // process. Null = no restriction, every assigned employee (today's
            // behavior, unchanged) - see PayrollComputationService::compute().
            $table->json('eligible_employee_types')->nullable()->after('period_end');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('eligible_employee_types');
        });
    }
};
