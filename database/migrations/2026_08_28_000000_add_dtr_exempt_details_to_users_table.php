<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Why the employee is exempt and since when, captured on the existing
     * `dtr_exempt` toggle so the printable exemption list (EmployeeScheduleController::
     * printExempt/exportExempt) has real content instead of just a name. Who/when the
     * toggle itself happened is already recorded via the `dtr_exemption_toggled`
     * hr_audit_trails row, so no actor/timestamp column is duplicated here.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('dtr_exempt_reason')->nullable()->after('dtr_exempt');
            $table->date('dtr_exempt_effective_date')->nullable()->after('dtr_exempt_reason');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['dtr_exempt_reason', 'dtr_exempt_effective_date']);
        });
    }
};
