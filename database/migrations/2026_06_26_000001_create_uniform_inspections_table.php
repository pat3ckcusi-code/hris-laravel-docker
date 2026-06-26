<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uniform_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspector_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->foreign('department_id')->references('Dept_id')->on('departments')->nullOnDelete();
            $table->date('inspection_date');
            $table->time('inspection_time');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uniform_inspections');
    }
};
