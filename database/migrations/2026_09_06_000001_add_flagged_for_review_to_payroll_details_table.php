<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PayrollComputationService::compute() sets this true only when a
     * per-employee row was created by one of its two error-recovery paths
     * (a missing SalaryMatrix entry, or the catch-all for any other
     * unexpected computation failure) rather than a normal, successful
     * computation - see "§11 follow-up" in the audit notes. Lets the
     * Payroll Details table flag a ₱0 row that needs review without any
     * fragile string-matching against payroll_exceptions.description.
     */
    public function up(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->boolean('flagged_for_review')->default(false)->after('net_pay');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropColumn('flagged_for_review');
        });
    }
};
