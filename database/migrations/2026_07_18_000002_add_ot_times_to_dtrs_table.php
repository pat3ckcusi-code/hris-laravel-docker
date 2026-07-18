<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dtrs', 'time_in_ot')) {
            Schema::table('dtrs', function (Blueprint $table) {
                // The dedicated OT In / OT Out punch pair (see OvertimeCalculator);
                // only ever populated for a Standard Day schedule.
                $table->time('time_in_ot')->nullable()->after('overtime_minutes');
                $table->time('time_out_ot')->nullable()->after('time_in_ot');
            });
        }
    }

    public function down(): void
    {
        Schema::table('dtrs', function (Blueprint $table) {
            $table->dropColumn(['time_in_ot', 'time_out_ot']);
        });
    }
};
