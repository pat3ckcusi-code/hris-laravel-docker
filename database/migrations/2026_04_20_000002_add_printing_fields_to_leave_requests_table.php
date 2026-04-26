<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leave_requests')) return;
        Schema::table('leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_requests', 'printing_allowed')) {
                $table->boolean('printing_allowed')->default(false)->after('balance_special_leave_privilege');
            }
            if (!Schema::hasColumn('leave_requests', 'printing_allowed_by')) {
                $table->unsignedBigInteger('printing_allowed_by')->nullable()->after('printing_allowed');
            }
            if (!Schema::hasColumn('leave_requests', 'printing_allowed_at')) {
                $table->timestamp('printing_allowed_at')->nullable()->after('printing_allowed_by');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('leave_requests')) return;
        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'printing_allowed_at')) {
                $table->dropColumn('printing_allowed_at');
            }
            if (Schema::hasColumn('leave_requests', 'printing_allowed_by')) {
                $table->dropColumn('printing_allowed_by');
            }
            if (Schema::hasColumn('leave_requests', 'printing_allowed')) {
                $table->dropColumn('printing_allowed');
            }
        });
    }
};
