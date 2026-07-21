<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eta', function (Blueprint $table) {
            $table->string('cancellation_status', 24)->nullable()->after('approved_at'); // Pending Cancellation, Cancelled, Rejected
            $table->text('cancellation_reason')->nullable()->after('cancellation_status');
            $table->text('cancellation_remarks')->nullable()->after('cancellation_reason');
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancellation_remarks');
            $table->timestamp('cancellation_reviewed_at')->nullable()->after('cancellation_requested_at');
            $table->unsignedBigInteger('cancellation_requested_by')->nullable()->after('cancellation_reviewed_at');
            $table->unsignedBigInteger('cancellation_reviewed_by')->nullable()->after('cancellation_requested_by');

            $table->foreign('cancellation_requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancellation_reviewed_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('eta', function (Blueprint $table) {
            $table->dropForeign(['cancellation_requested_by']);
            $table->dropForeign(['cancellation_reviewed_by']);
            $table->dropColumn([
                'cancellation_status',
                'cancellation_reason',
                'cancellation_remarks',
                'cancellation_requested_at',
                'cancellation_reviewed_at',
                'cancellation_requested_by',
                'cancellation_reviewed_by',
            ]);
        });
    }
};
