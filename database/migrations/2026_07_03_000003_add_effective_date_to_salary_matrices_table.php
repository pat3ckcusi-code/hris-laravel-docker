<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guards keep this migration safe to run after a backup restore.
        if (! Schema::hasColumn('salary_matrices', 'effective_date')) {
            Schema::table('salary_matrices', function (Blueprint $table) {
                $table->date('effective_date')->nullable()->after('year');
                $table->string('ordinance_reference')->nullable()->after('effective_date');
            });

            // Existing rows were keyed by calendar year only; treat them as
            // effective from January 1 of that year.
            DB::statement('UPDATE salary_matrices SET effective_date = MAKEDATE(year, 1) WHERE effective_date IS NULL');

            // (sg, step, year) no longer guarantees uniqueness once an
            // ordinance can take effect mid-year - a second version can now
            // share a calendar year with the first. (sg, step, effective_date)
            // is the real versioning key.
            Schema::table('salary_matrices', function (Blueprint $table) {
                $table->dropUnique(['sg', 'step', 'year']);
                $table->unique(['sg', 'step', 'effective_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('salary_matrices', function (Blueprint $table) {
            $table->dropUnique(['sg', 'step', 'effective_date']);
            $table->unique(['sg', 'step', 'year']);
            $table->dropColumn(['effective_date', 'ordinance_reference']);
        });
    }
};
