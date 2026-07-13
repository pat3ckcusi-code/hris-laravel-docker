<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moves Work Days and No Break from the Shift template to the per-employee
     * shift_assignments row, so the same template can be scheduled differently
     * (different work days, with or without a break) per employee/period.
     * Backfills every existing assignment from its current shift's template
     * values first, so behavior is unchanged at cutover; only then drops the
     * now-redundant template columns.
     */
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->json('work_days')->nullable()->after('days_of_week');
            $table->boolean('no_break')->default(false)->after('work_days');
        });

        DB::table('shift_assignments')
            ->join('shifts', 'shift_assignments.shift_id', '=', 'shifts.id')
            ->update([
                'shift_assignments.work_days' => DB::raw('shifts.work_days'),
                'shift_assignments.no_break' => DB::raw('shifts.no_break'),
            ]);

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['work_days', 'no_break']);
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->json('work_days')->nullable()->after('is_global');
            $table->boolean('no_break')->default(false)->after('crosses_midnight');
        });

        // Best-effort: each shift picks up the values from its most recently
        // created assignment row, since multiple assignments may have diverged
        // per-employee after the move - a lossy but reasonable rollback.
        DB::statement('
            UPDATE shifts s
            INNER JOIN (
                SELECT sa1.shift_id, sa1.work_days, sa1.no_break
                FROM shift_assignments sa1
                INNER JOIN (
                    SELECT shift_id, MAX(id) AS max_id
                    FROM shift_assignments
                    WHERE shift_id IS NOT NULL
                    GROUP BY shift_id
                ) sa2 ON sa1.id = sa2.max_id
            ) latest ON latest.shift_id = s.id
            SET s.work_days = latest.work_days, s.no_break = latest.no_break
        ');

        DB::table('shifts')->whereNull('work_days')->update(['work_days' => json_encode([1, 2, 3, 4, 5])]);

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropColumn(['work_days', 'no_break']);
        });
    }
};
