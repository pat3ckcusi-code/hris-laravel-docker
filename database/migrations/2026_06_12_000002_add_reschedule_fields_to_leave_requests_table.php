<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('reschedule_status', 24)->nullable()->after('cancellation_reviewed_by');
            $table->foreignId('rescheduled_from_id')->nullable()->after('reschedule_status')
                ->constrained('leave_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['rescheduled_from_id']);
            $table->dropColumn(['reschedule_status', 'rescheduled_from_id']);
        });
    }
};
