<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add index on users.EmpNo if not already indexed (needed for FK reference)
        if (!collect(Schema::getIndexes('users'))->contains(fn($idx) => in_array('EmpNo', $idx['columns']))) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('EmpNo');
            });
        }

        Schema::create('travel_order_employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('travel_order_id');
            $table->string('emp_no', 50);
            $table->foreign('travel_order_id')->references('id')->on('travel_orders')->onDelete('cascade');
            $table->foreign('emp_no')->references('EmpNo')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_order_employees');
    }
};
