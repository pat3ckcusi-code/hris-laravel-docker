<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dtrs', 'overtime_minutes')) {
            Schema::table('dtrs', function (Blueprint $table) {
                // Minutes worked past the one-hour grace period beyond the
                // scheduled shift end (see OvertimeCalculator); always from a
                // real matched PM Out punch, never imputed.
                $table->integer('overtime_minutes')->default(0)->after('hours_worked');
            });
        }
    }

    public function down(): void
    {
        Schema::table('dtrs', function (Blueprint $table) {
            $table->dropColumn(['overtime_minutes']);
        });
    }
};
