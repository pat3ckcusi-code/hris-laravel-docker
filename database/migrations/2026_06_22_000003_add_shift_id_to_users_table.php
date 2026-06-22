<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Assign each employee a work-shift template via a single shift_id FK. A null
     * shift_id means the employee follows the global standard-day shift from the
     * settings table. Runs after the shifts table is created.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('hours_per_day')
                ->constrained('shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_id');
        });
    }
};
