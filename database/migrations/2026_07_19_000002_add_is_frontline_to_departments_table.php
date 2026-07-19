<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark a department as frontline/essential (health, disaster response,
     * security, etc.) - employees in a frontline department are exempt from
     * work-suspension leniency and must continue reporting normally.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->boolean('is_frontline')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('is_frontline');
        });
    }
};
