<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->float('VL', 8, 2)->change();
            $table->float('SL', 8, 2)->change();
            $table->float('WLNS', 8, 2)->change();
            $table->float('SPL', 8, 2)->change();
            $table->float('CTO', 8, 2)->change();
            $table->float('SP', 8, 2)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            $table->integer('VL')->change();
            $table->integer('SL')->change();
            $table->integer('WLNS')->change();
            $table->integer('SPL')->change();
            $table->integer('CTO')->change();
            $table->integer('SP')->change();
        });
    }
};
