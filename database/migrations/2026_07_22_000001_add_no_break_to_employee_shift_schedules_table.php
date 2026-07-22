<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_shift_schedules', function (Blueprint $table) {
            $table->boolean('no_break')->default(false)->after('is_rotation_generated');
        });
    }

    public function down(): void
    {
        Schema::table('employee_shift_schedules', function (Blueprint $table) {
            $table->dropColumn('no_break');
        });
    }
};
