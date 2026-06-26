<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->unsignedTinyInteger('print_count')->default(0)->after('printing_deduction_details');
            $table->timestamp('last_printed_at')->nullable()->after('print_count');
            $table->foreignId('last_printed_by')->nullable()->after('last_printed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\User::class, 'last_printed_by');
            $table->dropColumn(['print_count', 'last_printed_at', 'last_printed_by']);
        });
    }
};
