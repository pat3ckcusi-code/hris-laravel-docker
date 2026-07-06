<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_department', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('department_id');

            $table->foreign('department_id')->references('Dept_id')->on('departments')->cascadeOnDelete();

            $table->unique(['shift_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_department');
    }
};
