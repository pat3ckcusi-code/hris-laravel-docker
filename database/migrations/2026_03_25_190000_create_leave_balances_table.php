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
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->string('EmpNo')->unique();
            $table->integer('VL')->default(0);
            $table->integer('SL')->default(0);
            $table->integer('WLNS')->default(0);
            $table->integer('SPL')->default(0);
            $table->integer('CTO')->default(0);
            $table->integer('SP')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
