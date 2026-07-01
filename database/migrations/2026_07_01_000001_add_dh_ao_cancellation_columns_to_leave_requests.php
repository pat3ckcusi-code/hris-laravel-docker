<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Widen to accommodate 'DH Recommended' (14 chars) and 'AO Endorsed' (11 chars)
            $table->string('cancellation_status', 32)->nullable()->change();

            $table->string('cancellation_dh_action', 16)->nullable()->after('cancellation_reviewed_by'); // 'recommended' | 'rejected'
            $table->timestamp('cancellation_dh_at')->nullable()->after('cancellation_dh_action');
            $table->unsignedBigInteger('cancellation_dh_by')->nullable()->after('cancellation_dh_at');
            $table->text('cancellation_dh_remarks')->nullable()->after('cancellation_dh_by');

            $table->string('cancellation_ao_action', 16)->nullable()->after('cancellation_dh_remarks'); // 'endorsed' | 'rejected'
            $table->timestamp('cancellation_ao_at')->nullable()->after('cancellation_ao_action');
            $table->unsignedBigInteger('cancellation_ao_by')->nullable()->after('cancellation_ao_at');
            $table->text('cancellation_ao_remarks')->nullable()->after('cancellation_ao_by');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn([
                'cancellation_dh_action',
                'cancellation_dh_at',
                'cancellation_dh_by',
                'cancellation_dh_remarks',
                'cancellation_ao_action',
                'cancellation_ao_at',
                'cancellation_ao_by',
                'cancellation_ao_remarks',
            ]);
            $table->string('cancellation_status', 24)->nullable()->change();
        });
    }
};
