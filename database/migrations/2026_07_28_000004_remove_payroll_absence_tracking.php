<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropColumn(['days_worked', 'late_minutes', 'undertime_minutes', 'absent_days']);
        });

        // Payroll no longer checks attendance at all - these rows are now
        // permanently stale (nothing will ever recreate or purge them,
        // including on already-locked runs that will never be recomputed).
        DB::table('payroll_exceptions')->where('type', 'absences_detected')->delete();
    }

    public function down(): void
    {
        Schema::table('payroll_details', function (Blueprint $table) {
            $table->integer('days_worked')->default(0)->after('employee_id');
            $table->integer('late_minutes')->default(0)->after('days_worked');
            $table->integer('undertime_minutes')->default(0)->after('late_minutes');
            $table->integer('absent_days')->default(0)->after('undertime_minutes');
        });
    }
};
