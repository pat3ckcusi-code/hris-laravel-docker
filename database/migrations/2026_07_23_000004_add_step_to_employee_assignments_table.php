<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->tinyInteger('step')->nullable()->default(1)->after('plantilla_id');
        });

        // Backfill existing rows from their plantilla's current step, so
        // already-recorded history keeps displaying the same value it does
        // today. Going forward, step is tracked per-assignment instead of
        // being read live from the (shared, position-level) plantilla row.
        DB::statement('
            UPDATE employee_assignments ea
            JOIN plantillas p ON p.id = ea.plantilla_id
            SET ea.step = p.step
        ');
    }

    public function down(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->dropColumn('step');
        });
    }
};
