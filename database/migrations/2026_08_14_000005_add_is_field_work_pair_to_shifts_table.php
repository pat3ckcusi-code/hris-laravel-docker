<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flags a template as *the* Monday-check-in / Friday-check-out weekly
     * pattern (see WeeklyPunchPairReconciliationService), distinct from and
     * orthogonal to shifts.punch_requirement (a plain flat pre-fill
     * convenience, unused when this flag is on). When true,
     * EmployeeScheduleController assigns this shift with a fixed,
     * server-enforced days_of_week=[1,5] + punch_requirement in_only/out_only
     * split regardless of what the assignment form actually submitted, so
     * picking this shift for an employee needs no further configuration.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('is_field_work_pair')->default(false)->after('punch_requirement');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('is_field_work_pair');
        });
    }
};
