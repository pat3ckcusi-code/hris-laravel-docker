<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oic_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('dept_id');
            $table->string('role');
            $table->unsignedBigInteger('appointed_by');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('dept_id')->references('Dept_id')->on('departments')->onDelete('cascade');
            $table->foreign('appointed_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oic_assignments');
    }
};
