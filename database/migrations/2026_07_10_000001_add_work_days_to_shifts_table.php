<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->json('work_days')->nullable()->after('is_global');
        });

        // Backfill Mon-Fri for every existing template so current DTR/payroll
        // behavior (which already only ever counted weekdays) doesn't change
        // until HR explicitly edits a shift's Work Days.
        DB::table('shifts')->update(['work_days' => json_encode([1, 2, 3, 4, 5])]);
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('work_days');
        });
    }
};
