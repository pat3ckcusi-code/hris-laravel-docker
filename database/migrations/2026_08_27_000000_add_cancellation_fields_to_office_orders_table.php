<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_orders', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('status');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reason');
            $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
            $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('office_orders', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancellation_reason', 'cancelled_at', 'cancelled_by']);
        });
    }
};
