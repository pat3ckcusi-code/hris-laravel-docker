<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->decimal('total_days', 5, 2)->nullable()->after('end_date');
            $table->decimal('paid_days', 5, 2)->default(0)->after('total_days');
            $table->decimal('lwop_days', 5, 2)->default(0)->after('paid_days');
            $table->date('date_filed')->nullable()->after('lwop_days');

            // more granular status column (keeps existing `status` for backwards-compatibility)
            $table->enum('detailed_status', ['For Recommendation','Recommended','Approved','Final / Archived','Disapproved','Cancelled'])->nullable()->after('date_filed');

            $table->text('rejection_notes')->nullable()->after('remarks');
            $table->text('action_remarks')->nullable()->after('rejection_notes');

            // Recommendation / approval workflow fields
            $table->foreignId('recommended_by')->nullable()->constrained('users')->after('action_remarks');
            $table->string('recommended_by_name')->nullable()->after('recommended_by');
            $table->string('recommended_by_position')->nullable()->after('recommended_by_name');
            $table->dateTime('recommended_at')->nullable()->after('recommended_by_position');

            $table->foreignId('approved_by')->nullable()->constrained('users')->after('recommended_at');
            $table->string('approved_by_name')->nullable()->after('approved_by');
            $table->string('approved_by_position')->nullable()->after('approved_by_name');
            $table->dateTime('approved_at')->nullable()->after('approved_by_position');

            $table->foreignId('finalized_by')->nullable()->constrained('users')->after('approved_at');
            $table->string('finalized_by_name')->nullable()->after('finalized_by');
            $table->string('finalized_by_position')->nullable()->after('finalized_by_name');
            $table->dateTime('finalized_at')->nullable()->after('finalized_by_position');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // drop columns (also drop foreign keys)
            if (Schema::hasColumn('leave_requests', 'finalized_at')) {
                $table->dropForeign(['finalized_by']);
                $table->dropColumn([
                    'finalized_by', 'finalized_by_name', 'finalized_by_position', 'finalized_at'
                ]);
            }
            if (Schema::hasColumn('leave_requests', 'approved_at')) {
                $table->dropForeign(['approved_by']);
                $table->dropColumn([
                    'approved_by', 'approved_by_name', 'approved_by_position', 'approved_at'
                ]);
            }
            if (Schema::hasColumn('leave_requests', 'recommended_at')) {
                $table->dropForeign(['recommended_by']);
                $table->dropColumn([
                    'recommended_by', 'recommended_by_name', 'recommended_by_position', 'recommended_at'
                ]);
            }

            $table->dropColumn([
                'action_remarks', 'rejection_notes', 'detailed_status', 'date_filed',
                'lwop_days', 'paid_days', 'total_days'
            ]);
        });
    }
};
