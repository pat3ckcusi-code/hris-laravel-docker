<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locators', function (Blueprint $table) {
            if (!Schema::hasColumn('locators', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('status');
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
                $table->text('cancellation_remarks')->nullable()->after('cancelled_at');
                $table->foreign('cancelled_by')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('locators', function (Blueprint $table) {
            if (Schema::hasColumn('locators', 'cancelled_by')) {
                $table->dropForeign(['cancelled_by']);
                $table->dropColumn(['cancelled_by', 'cancelled_at', 'cancellation_remarks']);
            }
        });
    }
};
