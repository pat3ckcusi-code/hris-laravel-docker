<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eta', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // single departure date
            $table->date('departure_date');
            // intended times
            $table->time('intended_departure_time')->nullable();
            $table->time('intended_arrival_time')->nullable();
            // actual
            $table->time('actual_arrival_time')->nullable();

            $table->string('destination');
            $table->text('purpose');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eta');
    }
};
