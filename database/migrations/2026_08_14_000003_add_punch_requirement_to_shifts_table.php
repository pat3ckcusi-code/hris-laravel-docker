<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * UI-default only, same convention as shifts.no_break (see
     * 2026_08_03_000001_add_no_break_to_shifts_table.php) - never read by
     * App\Support\WorkSchedule. Exists purely so the Shift Assignment /
     * Shift Schedule screens can pre-fill the per-assignment Punch
     * Requirement dropdown when a template is picked; the value that
     * actually drives DTR resolution always comes from
     * shift_assignments.punch_requirement / employee_shift_schedules.punch_requirement.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('punch_requirement')->default('both')->after('no_break');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('punch_requirement');
        });
    }
};
