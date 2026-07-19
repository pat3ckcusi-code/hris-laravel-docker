<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mark an individual employee as frontline/essential regardless of their
     * department's own designation - exempt from work-suspension leniency
     * and expected to continue reporting normally. See also
     * departments.is_frontline (add_is_frontline_to_departments_table).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_frontline')->default(false)->after('dtr_exempt');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_frontline');
        });
    }
};
