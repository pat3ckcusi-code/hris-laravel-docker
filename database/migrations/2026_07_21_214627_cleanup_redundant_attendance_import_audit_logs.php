<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Redundant no-op attendance_import audit rows written every minute
        // before the ImportAttendanceLogsJob fix in this same deploy.
        DB::table('hr_audit_trails')
            ->where('module', 'attendance')
            ->where('action', 'attendance_import')
            ->whereRaw("JSON_EXTRACT(details, '$.imported') = 0")
            ->whereRaw("JSON_EXTRACT(details, '$.status') = 'success'")
            ->delete();

        // Already-expired database cache rows; nothing pruned these before now.
        DB::table('cache')->where('expiration', '<', now()->timestamp)->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Deleted rows were pure duplicate/expired noise - not restorable, nothing to reverse.
    }
};
