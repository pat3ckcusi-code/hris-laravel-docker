<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('payroll_preparer_name')->nullable()->after('budget_officer_designation');
            $table->string('payroll_preparer_designation')->nullable()->after('payroll_preparer_name');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['payroll_preparer_name', 'payroll_preparer_designation']);
        });
    }
};
