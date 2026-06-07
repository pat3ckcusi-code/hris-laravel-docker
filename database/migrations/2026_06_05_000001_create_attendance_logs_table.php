<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emp_no');
            $table->date('logdate');
            $table->time('logtime');
            $table->string('logtype')->nullable();
            $table->string('text')->nullable();
            $table->string('device_name')->nullable();
            $table->enum('in_out', ['IN', 'OUT'])->nullable();
            $table->timestamps();

            $table->index(['user_id', 'logdate']);
            $table->unique(['user_id', 'logdate', 'logtime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
