<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * both = the normal 4-slot (or no_break's 2-slot) day. in_only/out_only
     * collapse an assignment's expected punches down to a single slot (AM In
     * only, or PM Out only) - used for a "Field Work" style shift where only
     * one specific day of the week has any punch expected at all. See
     * App\Support\WorkSchedule and App\Services\Attendance\AttendanceMatcher.
     */
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->string('punch_requirement')->default('both')->after('no_break');
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropColumn('punch_requirement');
        });
    }
};
