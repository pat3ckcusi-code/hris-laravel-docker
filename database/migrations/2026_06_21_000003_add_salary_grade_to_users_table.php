<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->tinyInteger('salary_grade')->nullable()->after('designation');
            $table->tinyInteger('salary_step')->nullable()->default(1)->after('salary_grade');
        });

        // Populate from existing active plantilla assignments
        DB::statement("
            UPDATE users u
            JOIN employee_assignments ea ON ea.employee_id = u.id AND ea.end_date IS NULL
            JOIN plantillas p ON p.id = ea.plantilla_id
            SET u.salary_grade = p.salary_grade, u.salary_step = p.step
        ");
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['salary_grade', 'salary_step']);
        });
    }
};
