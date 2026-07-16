<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Duplicate-submission prevention is enforced in
        // AttendanceAdjustmentSummaryService (query non-voided submissions
        // for a matching user_id/month/year before insert), not by a DB
        // constraint alone - a plain unique on (user_id, month, year) would
        // permanently block resubmission after a submission is voided.
        Schema::create('attendance_adjustment_submission_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('attendance_adjustment_submissions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('month');
            $table->unsignedSmallInteger('year');
            $table->string('emp_no', 50)->nullable();
            $table->string('name', 255);
            $table->string('department', 255)->nullable();
            $table->string('position', 255)->nullable();
            $table->string('employee_type', 50)->nullable();
            $table->unsignedInteger('unfiled_count')->default(0);
            $table->unsignedInteger('tardiness_count')->default(0);
            $table->unsignedInteger('tardiness_minutes')->default(0);
            $table->unsignedInteger('undertime_count')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'month', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_adjustment_submission_items');
    }
};
