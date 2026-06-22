<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named, reusable work-shift templates (e.g. "Standard Day", "Night", "Mid").
     * Employees are assigned one via users.shift_id; a null assignment means the
     * global standard-day shift from the settings table.
     *
     * The four times follow the four CSC Form 48 slots:
     *   time_in   — shift start (Form 48 AM In)
     *   break_out — leave for meal break (AM Out)
     *   break_in  — return from meal break (PM In)
     *   time_out  — shift end (PM Out)
     *
     * crosses_midnight is true when the shift ends on the following day
     * (time_out <= time_in), e.g. a 22:00–06:00 night shift.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('time_in');
            $table->time('break_out');
            $table->time('break_in');
            $table->time('time_out');
            $table->boolean('crosses_midnight')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
