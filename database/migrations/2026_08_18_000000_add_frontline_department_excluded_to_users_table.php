<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-employee opt-out of an inherited department-level `is_frontline` flag. An
     * employee whose department is marked frontline is exempt from Work Suspension by
     * default (see User::isFrontlineExempt()); this lets HR/Time Keeper carve out
     * specific employees within that department who should still be subject to a
     * suspension. Does not affect the employee's own `is_frontline` flag, which still
     * wins outright if set.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('frontline_department_excluded')->default(false)->after('is_frontline');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('frontline_department_excluded');
        });
    }
};
