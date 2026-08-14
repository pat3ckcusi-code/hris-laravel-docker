<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a row written by WeeklyPunchPairReconciliationService (either a
     * 'field_work_unconfirmed' absence marker, or a mid-week 'in_only'
     * check-in override) as opposed to an independently-set manual override -
     * mirrors is_rotation_generated's existing purpose for the rotation
     * generator. Lets the reconciliation service tell its own prior writes
     * apart from a genuine manual override (which it must never overwrite or
     * delete) and safely clean up/self-heal its own rows on a later run once
     * a week's outcome changes (e.g. a backfilled punch import).
     */
    public function up(): void
    {
        Schema::table('employee_shift_schedules', function (Blueprint $table) {
            $table->boolean('is_reconciliation_generated')->default(false)->after('is_rotation_generated');
        });
    }

    public function down(): void
    {
        Schema::table('employee_shift_schedules', function (Blueprint $table) {
            $table->dropColumn('is_reconciliation_generated');
        });
    }
};
