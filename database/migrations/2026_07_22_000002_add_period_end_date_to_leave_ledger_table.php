<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_ledger', function (Blueprint $table) {
            $table->date('period_end_date')->nullable()->after('transaction_date');
        });
    }

    public function down(): void
    {
        Schema::table('leave_ledger', function (Blueprint $table) {
            $table->dropColumn('period_end_date');
        });
    }
};
