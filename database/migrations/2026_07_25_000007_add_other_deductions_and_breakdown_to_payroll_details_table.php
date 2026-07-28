<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->decimal('other_deductions', 12, 2)->default(0)->after('loan_deduction');
            $table->json('deduction_breakdown')->nullable()->after('other_deductions');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropColumn(['other_deductions', 'deduction_breakdown']);
        });
    }
};
