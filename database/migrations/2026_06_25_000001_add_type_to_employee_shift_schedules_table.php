<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_shift_schedules', function (Blueprint $table) {
            $table->string('type')->nullable()->after('shift_id');
        });

        // Backfill: existing rows with shift_id = null are rest days.
        DB::table('employee_shift_schedules')
            ->whereNull('shift_id')
            ->update(['type' => 'rest']);
    }

    public function down(): void
    {
        Schema::table('employee_shift_schedules', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
