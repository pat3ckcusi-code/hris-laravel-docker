<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_suspensions', function (Blueprint $table) {
            $table->id();
            $table->date('suspension_date')->unique();
            $table->time('suspension_time')->nullable(); // null = full-day suspension
            $table->string('reason');
            $table->string('type')->default('weather'); // weather, event, other
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('suspension_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_suspensions');
    }
};
