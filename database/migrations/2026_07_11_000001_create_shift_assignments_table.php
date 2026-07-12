<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Effective-dated history of an employee's shift template. users.shift_id
     * stays as a denormalized "today" cache (kept in sync by ShiftAssignmentService
     * and the shift:sync-cache command); this table is the source of truth for
     * "which shift applied on date D," including future-scheduled and past,
     * superseded assignments.
     */
    public function up(): void
    {
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'effective_from']);
            $table->index(['user_id', 'effective_until']);
        });

        // Backfill: every currently-assigned employee gets one open-ended row
        // starting at a fixed sentinel that predates all real attendance data,
        // so forUserOnDate() always finds a covering row for historical dates.
        $now = now();

        DB::table('users')
            ->whereNotNull('shift_id')
            ->orderBy('id')
            ->select('id', 'shift_id')
            ->chunk(500, function ($users) use ($now) {
                DB::table('shift_assignments')->insert($users->map(fn ($u) => [
                    'user_id' => $u->id,
                    'shift_id' => $u->shift_id,
                    'effective_from' => '2000-01-01',
                    'effective_until' => null,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
    }
};
