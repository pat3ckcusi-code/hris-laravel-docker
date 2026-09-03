<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flags a template as a Single Punch Shift (see LateCalculator/
     * UndertimeCalculator/AttendanceStatusResolver's 'am_in_only_graded'
     * handling), distinct from and orthogonal to shifts.punch_requirement (a
     * plain flat pre-fill convenience, unused when this flag is on). When
     * true, EmployeeScheduleController assigns this shift with
     * punch_requirement forced to 'am_in_only_graded' regardless of what the
     * assignment form actually submitted, so picking this shift for an
     * employee needs no further configuration. Unlike is_field_work_pair,
     * this does not touch days_of_week/work_days - it's a daily, not weekly,
     * requirement.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('is_single_punch')->default(false)->after('is_field_work_pair');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('is_single_punch');
        });
    }
};
