<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_adjustment_submission_items', function (Blueprint $table) {
            $table->string('processed_status', 20)->default('pending')->after('remarks');
            $table->foreignId('processed_by')->nullable()->after('processed_status')->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable()->after('processed_by');
            $table->decimal('deducted_days', 8, 3)->nullable()->after('processed_at');
            $table->text('action_remarks')->nullable()->after('deducted_days');

            $table->index('processed_status');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_adjustment_submission_items', function (Blueprint $table) {
            $table->dropForeign(['processed_by']);
            $table->dropIndex(['processed_status']);
            $table->dropColumn(['processed_status', 'processed_by', 'processed_at', 'deducted_days', 'action_remarks']);
        });
    }
};
