<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_adjustment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->string('employee_type', 50)->nullable();
            $table->json('department_ids');
            $table->string('department_label', 255)->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->enum('status', ['submitted', 'voided'])->default('submitted');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_adjustment_submissions');
    }
};
