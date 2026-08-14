<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('habitual_violation_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->string('violation_type', 30); // 'habitual_tardy' | 'frequent_undertime'
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('offense_number'); // 1-3, cycles per CSC RACCS schedule
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One notice per employee per violation type per year, forever.
            $table->unique(['employee_id', 'violation_type', 'year'], 'habitual_notices_employee_type_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('habitual_violation_notices');
    }
};
