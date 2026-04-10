<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Drop foreign keys if they exist, then drop columns
            if (Schema::hasColumn('leave_requests', 'approver_id')) {
                // remove foreign key if present
                try { $table->dropForeign(['approver_id']); } catch (\Throwable $e) {}
                $table->dropColumn('approver_id');
            }

            $cols = [
                'remarks', 'action_remarks',
                'recommended_by', 'recommended_by_name', 'recommended_by_position', 'recommended_at',
                'approved_by', 'approved_by_name', 'approved_by_position', 'approved_at',
                'finalized_by', 'finalized_by_name', 'finalized_by_position', 'finalized_at'
            ];

            foreach ($cols as $c) {
                if (Schema::hasColumn('leave_requests', $c)) {
                    // drop foreign keys for *_by columns
                    if (in_array($c, ['recommended_by','approved_by','finalized_by'])) {
                        try { $table->dropForeign([$c]); } catch (\Throwable $e) {}
                    }
                    $table->dropColumn($c);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Recreate columns without foreign key constraints (safe fallback)
            if (!Schema::hasColumn('leave_requests', 'approver_id')) {
                $table->unsignedBigInteger('approver_id')->nullable()->after('status');
            }

            if (!Schema::hasColumn('leave_requests', 'remarks')) {
                $table->text('remarks')->nullable()->after('approver_id');
            }
            if (!Schema::hasColumn('leave_requests', 'action_remarks')) {
                $table->text('action_remarks')->nullable()->after('remarks');
            }

            if (!Schema::hasColumn('leave_requests', 'recommended_by')) {
                $table->unsignedBigInteger('recommended_by')->nullable()->after('action_remarks');
                $table->string('recommended_by_name')->nullable()->after('recommended_by');
                $table->string('recommended_by_position')->nullable()->after('recommended_by_name');
                $table->dateTime('recommended_at')->nullable()->after('recommended_by_position');
            }

            if (!Schema::hasColumn('leave_requests', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('recommended_at');
                $table->string('approved_by_name')->nullable()->after('approved_by');
                $table->string('approved_by_position')->nullable()->after('approved_by_name');
                $table->dateTime('approved_at')->nullable()->after('approved_by_position');
            }

            if (!Schema::hasColumn('leave_requests', 'finalized_by')) {
                $table->unsignedBigInteger('finalized_by')->nullable()->after('approved_at');
                $table->string('finalized_by_name')->nullable()->after('finalized_by');
                $table->string('finalized_by_position')->nullable()->after('finalized_by_name');
                $table->dateTime('finalized_at')->nullable()->after('finalized_by_position');
            }
        });
    }
};
