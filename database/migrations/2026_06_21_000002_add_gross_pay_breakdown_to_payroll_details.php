<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->decimal('gross_pay', 12, 2)->default(0)->after('basic_salary');
            $table->decimal('gsis_deduction', 12, 2)->default(0)->after('deductions');
            $table->decimal('philhealth_deduction', 12, 2)->default(0)->after('gsis_deduction');
            $table->decimal('pagibig_deduction', 12, 2)->default(0)->after('philhealth_deduction');
            $table->decimal('bir_deduction', 12, 2)->default(0)->after('pagibig_deduction');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropColumn(['gross_pay', 'gsis_deduction', 'philhealth_deduction', 'pagibig_deduction', 'bir_deduction']);
        });
    }
};
