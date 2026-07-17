<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uniform_inspection_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uniform_inspection_id')->constrained('uniform_inspections')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 30);
            $table->decimal('deducted_days', 8, 3)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['uniform_inspection_id', 'employee_id'], 'uid_inspection_employee_unique');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uniform_inspection_deductions');
    }
};
