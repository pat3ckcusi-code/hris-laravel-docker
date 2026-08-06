<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RA 8972 (Solo Parents' Welfare Act) standing designation, toggled by the Leave
     * Manager on the Manage Balance page. "Active" gates eligibility to file Solo
     * Parent Leave (see LeaveRequestController::checkSoloParentDesignation()) - a
     * separate, admin-controlled flag from the employee's self-reported PDS answer.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_solo_parent')->default(false)->after('is_frontline');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_solo_parent');
        });
    }
};
