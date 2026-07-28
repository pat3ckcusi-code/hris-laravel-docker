<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deductions', function (Blueprint $table) {
            // Lets a Loan/Other deduction type be retired from new assignment
            // without deleting it (destroy() already blocks deletion once any
            // employee/loan is attached) - existing Loan/EmployeeDeduction
            // rows are untouched and keep computing regardless of this flag.
            // The 4 system mandatory rows are never toggled off - always true.
            $table->boolean('is_active')->default(true)->after('mandatory_config');
        });
    }

    public function down(): void
    {
        Schema::table('deductions', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
