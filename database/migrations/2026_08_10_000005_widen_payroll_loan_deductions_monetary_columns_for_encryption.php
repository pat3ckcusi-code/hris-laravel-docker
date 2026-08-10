<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_loan_deductions', function (Blueprint $table) {
            $table->text('amount')->nullable()->change();
            $table->text('balance_before')->nullable()->change();
            $table->text('balance_after')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_loan_deductions', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->default(0)->change();
            $table->decimal('balance_before', 12, 2)->default(0)->change();
            $table->decimal('balance_after', 12, 2)->default(0)->change();
        });
    }
};
