<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Replace enum status column with portable string column that supports
        // 'pending', 'approved', 'declined', and 'cancelled' values.
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }
};
