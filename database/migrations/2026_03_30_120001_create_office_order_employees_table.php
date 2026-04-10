<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('office_order_employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('office_order_id');
            $table->string('emp_no');
            $table->timestamps();

            $table->index('office_order_id');
            $table->index('emp_no');

            $table->foreign('office_order_id')
                ->references('id')->on('office_orders')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('office_order_employees');
    }
};
