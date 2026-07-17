<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_dates', function (Blueprint $table) {
            // Per-date leave type + day amount (fixes the reschedule insert bug and
            // enables per-date refund attribution for partial cancel/reschedule).
            // Guarded: some dev environments already carry these two columns from an
            // earlier ad-hoc fix that was never captured in a migration.
            if (! Schema::hasColumn('leave_dates', 'leave_type')) {
                $table->string('leave_type', 100)->nullable()->after('leave_date');
            }
            if (! Schema::hasColumn('leave_dates', 'days')) {
                $table->decimal('days', 4, 2)->default(1.00)->after('leave_type');
            }

            // Per-date cancellation chain, mirroring leave_requests' cancellation_* columns.
            $table->string('cancellation_status', 32)->nullable()->after('is_lwop');
            $table->text('cancellation_reason')->nullable()->after('cancellation_status');
            $table->text('cancellation_remarks')->nullable()->after('cancellation_reason');
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancellation_remarks');
            $table->unsignedBigInteger('cancellation_requested_by')->nullable()->after('cancellation_requested_at');
            $table->timestamp('cancellation_reviewed_at')->nullable()->after('cancellation_requested_by');
            $table->unsignedBigInteger('cancellation_reviewed_by')->nullable()->after('cancellation_reviewed_at');
            $table->string('cancellation_dh_action', 16)->nullable()->after('cancellation_reviewed_by');
            $table->timestamp('cancellation_dh_at')->nullable()->after('cancellation_dh_action');
            $table->unsignedBigInteger('cancellation_dh_by')->nullable()->after('cancellation_dh_at');
            $table->text('cancellation_dh_remarks')->nullable()->after('cancellation_dh_by');
            $table->string('cancellation_ao_action', 16)->nullable()->after('cancellation_dh_remarks');
            $table->timestamp('cancellation_ao_at')->nullable()->after('cancellation_ao_action');
            $table->unsignedBigInteger('cancellation_ao_by')->nullable()->after('cancellation_ao_at');
            $table->text('cancellation_ao_remarks')->nullable()->after('cancellation_ao_by');

            // Per-date reschedule link — the per-date analog of leave_requests.rescheduled_from_id.
            $table->foreignId('rescheduled_to_leave_request_id')->nullable()
                ->after('cancellation_ao_remarks')
                ->constrained('leave_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_dates', function (Blueprint $table) {
            $table->dropForeign(['rescheduled_to_leave_request_id']);
            $table->dropColumn([
                'leave_type',
                'days',
                'cancellation_status',
                'cancellation_reason',
                'cancellation_remarks',
                'cancellation_requested_at',
                'cancellation_requested_by',
                'cancellation_reviewed_at',
                'cancellation_reviewed_by',
                'cancellation_dh_action',
                'cancellation_dh_at',
                'cancellation_dh_by',
                'cancellation_dh_remarks',
                'cancellation_ao_action',
                'cancellation_ao_at',
                'cancellation_ao_by',
                'cancellation_ao_remarks',
                'rescheduled_to_leave_request_id',
            ]);
        });
    }
};
