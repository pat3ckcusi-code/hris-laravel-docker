<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uniform_inspection_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uniform_inspection_id')->constrained('uniform_inspections')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('violation_type', 60);
            $table->unsignedSmallInteger('offense_number')->default(1);
            $table->string('status', 20)->default('Pending');
            $table->string('photo_path')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uniform_inspection_details');
    }
};
