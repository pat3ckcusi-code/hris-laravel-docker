<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets one employee have two (or more) concurrently-active shift
     * assignments split by weekday - e.g. one shift's hours for Mon/Wed/Fri
     * and a different shift's hours for Tue/Thu. Null (the default, and every
     * pre-existing row) means "applies every day," matching today's behavior
     * exactly.
     *
     * Drops the (user_id, effective_from) unique constraint: two rows
     * legitimately share the same start date once they're scoped to disjoint
     * days_of_week (e.g. assigning the MWF shift and the TTH shift both
     * starting today). ShiftAssignmentService::overlaps() - now day-of-week
     * aware - is what actually prevents true conflicts.
     */
    public function up(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->json('days_of_week')->nullable()->after('shift_id');
        });

        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropUnique('shift_assignments_user_id_effective_from_unique');
            $table->index(['user_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::table('shift_assignments', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'effective_from']);
            $table->unique(['user_id', 'effective_from']);
            $table->dropColumn('days_of_week');
        });
    }
};
