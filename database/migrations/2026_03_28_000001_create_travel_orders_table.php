<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_orders', function (Blueprint $table) {
            $table->id();
            $table->string('travel_order_num', 50);
            $table->text('purpose');
            $table->string('destination', 255);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('Remarks')->nullable();
            $table->unsignedBigInteger('recommender');
            $table->unsignedBigInteger('created_by');
            $table->string('status', 30)->default('Draft');
            $table->timestamps();
            $table->string('rejection_note', 255)->nullable();
            $table->foreign('recommender')->references('id')->on('users');
            $table->foreign('created_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_orders');
    }
};
