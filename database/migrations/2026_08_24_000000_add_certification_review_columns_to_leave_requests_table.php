<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('certification_review_status', 20)->nullable()->after('esignature_requested_at');
            $table->foreignId('certification_reviewed_by')->nullable()->after('certification_review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('certification_reviewed_at')->nullable()->after('certification_reviewed_by');
            $table->text('certification_review_remarks')->nullable()->after('certification_reviewed_at');
            $table->index('certification_review_status');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['certification_reviewed_by']);
            $table->dropIndex(['certification_review_status']);
            $table->dropColumn([
                'certification_review_status',
                'certification_reviewed_by',
                'certification_reviewed_at',
                'certification_review_remarks',
            ]);
        });
    }
};
