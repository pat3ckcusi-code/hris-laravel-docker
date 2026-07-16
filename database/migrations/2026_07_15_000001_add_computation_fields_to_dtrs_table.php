<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dtrs', 'hours_worked')) {
            Schema::table('dtrs', function (Blueprint $table) {
                // Decimal hours between matched in/out pairs (see
                // HoursWorkedCalculator); null until the row is recomputed
                // by the new engine.
                $table->decimal('hours_worked', 5, 2)->nullable()->after('undertime_minutes');

                // Punches no expected event could claim (H:i:s strings on the
                // shift date), kept for review instead of being mis-slotted.
                $table->json('unmatched_logs')->nullable()->after('hours_worked');
            });
        }
    }

    public function down(): void
    {
        Schema::table('dtrs', function (Blueprint $table) {
            $table->dropColumn(['hours_worked', 'unmatched_logs']);
        });
    }
};
