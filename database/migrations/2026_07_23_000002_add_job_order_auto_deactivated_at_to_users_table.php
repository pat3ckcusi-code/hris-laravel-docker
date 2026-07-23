<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks that `Status` was flipped to Inactive by job-order:deactivate-expired
     * (as opposed to a manual HR/Records Manager status change), so
     * JobOrderAppointmentService can safely auto-reactivate on renewal without
     * ever overriding a manually-set Inactive/Separated status. Cleared on any
     * manual status change (RecordsManagerController::update,
     * HRManagerController::recordsUpdate) and on auto-reactivation itself.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('job_order_auto_deactivated_at')->nullable()->after('is_frontline');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_order_auto_deactivated_at');
        });
    }
};
