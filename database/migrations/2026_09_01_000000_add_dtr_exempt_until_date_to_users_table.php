<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional end date for a DTR/biometric exemption, alongside the existing
     * `dtr_exempt_reason`/`dtr_exempt_effective_date` (see
     * 2026_08_28_000000_add_dtr_exempt_details_to_users_table.php). Left null
     * for an indefinite exemption, exactly like today's behavior. When set,
     * the `dtr:restore-expired-exemptions` scheduled command auto-restores
     * the employee once this date has passed, mirroring
     * `job-order:deactivate-expired`'s auto-expiry pattern.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('dtr_exempt_until_date')->nullable()->after('dtr_exempt_effective_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dtr_exempt_until_date');
        });
    }
};
