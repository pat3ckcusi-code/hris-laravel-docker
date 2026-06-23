<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark an employee as exempt from biometric attendance / DTR. Exempt
     * employees are skipped during the biometric import, excluded from Form 48
     * and DTR exports, and hidden from the Time Keeper shift-assignment list.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('dtr_exempt')->default(false)->after('shift_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dtr_exempt');
        });
    }
};
