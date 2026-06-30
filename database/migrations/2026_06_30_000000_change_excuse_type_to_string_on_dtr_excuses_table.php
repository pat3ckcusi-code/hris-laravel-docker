<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtr_excuses', function (Blueprint $table) {
            // Convert enum to portable string column so new excuse types
            // (e.g. 'emergency') can be added without a migration.
            $table->string('excuse_type', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('dtr_excuses', function (Blueprint $table) {
            $table->enum('excuse_type', ['power_interruption', 'system_failure', 'weather_disturbance', 'other'])->change();
        });
    }
};
