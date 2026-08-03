<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Re-adds no_break to shifts as a UI-default only (unlike the original
     * 2026_06_25_000001 column, which fed DTR resolution directly before
     * 2026_07_13_000001 moved that concern to shift_assignments/
     * employee_shift_schedules). This column is never read by
     * App\Support\WorkSchedule - it only pre-fills the per-employee no_break
     * checkbox client-side when a template is picked on the Shift
     * Assignment / Shift Schedule screens.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('no_break')->default(false)->after('is_global');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('no_break');
        });
    }
};
