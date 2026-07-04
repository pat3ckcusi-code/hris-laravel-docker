<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guards keep this migration safe to run after a backup restore.
        if (! Schema::hasColumn('users', 'date_of_original_appointment')) {
            Schema::table('users', function (Blueprint $table) {
                $table->date('date_of_original_appointment')->nullable()->after('date_hired');
                $table->date('date_of_last_promotion')->nullable()->after('date_of_original_appointment');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['date_of_original_appointment', 'date_of_last_promotion']);
        });
    }
};
