<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->text('basic_salary')->nullable()->change();
            $table->text('gross_pay')->nullable()->change();
            $table->text('mandatory_deductions')->nullable()->change();
            $table->text('loan_deduction')->nullable()->change();
            $table->text('other_deductions')->nullable()->change();
            $table->text('lwop_deduction')->nullable()->change();
            $table->text('total_deductions')->nullable()->change();
            $table->text('net_pay')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('basic_salary', 12, 2)->default(0)->change();
            $table->decimal('gross_pay', 12, 2)->default(0)->change();
            $table->decimal('mandatory_deductions', 12, 2)->default(0)->change();
            $table->decimal('loan_deduction', 12, 2)->default(0)->change();
            $table->decimal('other_deductions', 12, 2)->default(0)->change();
            $table->decimal('lwop_deduction', 12, 2)->default(0)->change();
            $table->decimal('total_deductions', 12, 2)->default(0)->change();
            $table->decimal('net_pay', 12, 2)->default(0)->change();
        });
    }
};
