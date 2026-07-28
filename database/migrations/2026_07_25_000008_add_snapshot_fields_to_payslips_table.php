<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('basic_salary', 12, 2)->default(0)->after('payroll_run_id');
            $table->decimal('gross_pay', 12, 2)->default(0)->after('basic_salary');
            $table->decimal('mandatory_deductions', 12, 2)->default(0)->after('gross_pay');
            $table->decimal('loan_deduction', 12, 2)->default(0)->after('mandatory_deductions');
            $table->decimal('other_deductions', 12, 2)->default(0)->after('loan_deduction');
            $table->decimal('lwop_deduction', 12, 2)->default(0)->after('other_deductions');
            $table->decimal('total_deductions', 12, 2)->default(0)->after('lwop_deduction');
            $table->decimal('net_pay', 12, 2)->default(0)->after('total_deductions');
            $table->json('deduction_breakdown')->nullable()->after('net_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn([
                'basic_salary',
                'gross_pay',
                'mandatory_deductions',
                'loan_deduction',
                'other_deductions',
                'lwop_deduction',
                'total_deductions',
                'net_pay',
                'deduction_breakdown',
            ]);
        });
    }
};
