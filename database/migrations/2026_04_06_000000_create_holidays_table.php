<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('holiday_date');
            $table->string('type')->default('regular'); // regular, special, suspension
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('holiday_date');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
