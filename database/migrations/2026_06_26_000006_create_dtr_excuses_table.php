<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtr_excuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->enum('excuse_type', ['power_interruption', 'system_failure', 'weather_disturbance', 'other']);
            $table->boolean('is_full_day')->default(false);
            $table->boolean('excuse_am_in')->default(false);
            $table->boolean('excuse_am_out')->default(false);
            $table->boolean('excuse_pm_in')->default(false);
            $table->boolean('excuse_pm_out')->default(false);
            $table->text('reason')->nullable();
            $table->foreignId('filed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtr_excuses');
    }
};
