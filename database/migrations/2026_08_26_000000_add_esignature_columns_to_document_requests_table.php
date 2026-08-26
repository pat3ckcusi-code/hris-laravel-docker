<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->string('signature_status', 20)->nullable()->after('hr_notes');
            $table->foreignId('signature_reviewed_by')->nullable()->after('signature_status')->constrained('users')->nullOnDelete();
            $table->timestamp('signature_reviewed_at')->nullable()->after('signature_reviewed_by');
            $table->text('signature_review_remarks')->nullable()->after('signature_reviewed_at');
            $table->foreignId('signed_by')->nullable()->after('signature_review_remarks')->constrained('users')->nullOnDelete();
            $table->timestamp('signed_at')->nullable()->after('signed_by');
            $table->index('signature_status');
        });
    }

    public function down(): void
    {
        Schema::table('document_requests', function (Blueprint $table) {
            $table->dropIndex(['signature_status']);
            $table->dropConstrainedForeignId('signed_by');
            $table->dropConstrainedForeignId('signature_reviewed_by');
            $table->dropColumn([
                'signature_status',
                'signature_reviewed_at',
                'signature_review_remarks',
                'signed_at',
            ]);
        });
    }
};
