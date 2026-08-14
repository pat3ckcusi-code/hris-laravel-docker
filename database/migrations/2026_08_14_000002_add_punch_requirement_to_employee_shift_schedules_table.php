<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors shift_assignments.punch_requirement for the per-date override
     * table - only meaningful on a row that also carries a shift_id, exactly
     * like this table's existing no_break column.
     */
    public function up(): void
    {
        Schema::table('employee_shift_schedules', function (Blueprint $table) {
            $table->string('punch_requirement')->default('both')->after('no_break');
        });
    }

    public function down(): void
    {
        Schema::table('employee_shift_schedules', function (Blueprint $table) {
            $table->dropColumn('punch_requirement');
        });
    }
};
