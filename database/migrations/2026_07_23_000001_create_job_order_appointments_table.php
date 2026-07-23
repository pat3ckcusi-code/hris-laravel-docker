<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('designation', 255)->nullable();
            $table->string('office', 255)->nullable();
            $table->string('funding_source', 255)->nullable();

            $table->decimal('rate_per_day', 8, 2);
            $table->string('rate_note', 50)->nullable();

            $table->date('period_from');
            $table->date('period_until');

            $table->string('remarks', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'period_from']);
            $table->index(['user_id', 'period_until']);
            $table->index('office');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_appointments');
    }
};
