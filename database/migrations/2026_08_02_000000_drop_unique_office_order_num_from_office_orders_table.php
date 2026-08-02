<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('office_orders', function (Blueprint $table) {
            $table->dropUnique(['office_order_num']);
        });
    }

    public function down(): void
    {
        Schema::table('office_orders', function (Blueprint $table) {
            $table->unique('office_order_num');
        });
    }
};
