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
        Schema::create('office_orders', function (Blueprint $table) {
            $table->id();
            $table->string('office_order_num')->unique();
            $table->string('subject');
            $table->date('issued_date');
            $table->date('effective_date')->nullable();
            $table->text('details')->nullable();
            $table->text('Remarks')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('status', 30)->default('Pending Recommendation');
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('office_orders');
    }
};
