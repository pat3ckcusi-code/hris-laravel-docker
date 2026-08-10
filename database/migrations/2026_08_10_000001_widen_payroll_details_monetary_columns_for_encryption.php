<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widens payroll_details' monetary decimal columns to text so they can
     * hold ciphertext once App\Casts\EncryptedDecimal is wired up. This only
     * widens the columns — existing decimal values are preserved as their
     * string representation by MySQL's own type conversion; the app's casts
     * stay 'float' until a later migration flips them, so this step alone
     * changes nothing observable.
     */
    public function up(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->text('basic_salary')->nullable()->change();
            $table->text('gross_pay')->nullable()->change();
            $table->text('earnings')->nullable()->change();
            $table->text('deductions')->nullable()->change();
            $table->text('gsis_deduction')->nullable()->change();
            $table->text('philhealth_deduction')->nullable()->change();
            $table->text('pagibig_deduction')->nullable()->change();
            $table->text('bir_deduction')->nullable()->change();
            $table->text('lwop_deduction')->nullable()->change();
            $table->text('loan_deduction')->nullable()->change();
            $table->text('other_deductions')->nullable()->change();
            $table->text('net_pay')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->decimal('basic_salary', 12, 2)->default(0)->change();
            $table->decimal('gross_pay', 12, 2)->default(0)->change();
            $table->decimal('earnings', 12, 2)->default(0)->change();
            $table->decimal('deductions', 12, 2)->default(0)->change();
            $table->decimal('gsis_deduction', 12, 2)->default(0)->change();
            $table->decimal('philhealth_deduction', 12, 2)->default(0)->change();
            $table->decimal('pagibig_deduction', 12, 2)->default(0)->change();
            $table->decimal('bir_deduction', 12, 2)->default(0)->change();
            $table->decimal('lwop_deduction', 12, 2)->default(0)->change();
            $table->decimal('loan_deduction', 12, 2)->default(0)->change();
            $table->decimal('other_deductions', 12, 2)->default(0)->change();
            $table->decimal('net_pay', 12, 2)->default(0)->change();
        });
    }
};
