<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            // General
            $table->string('system_name')->default('HRIS');
            $table->string('org_name')->default('City Government of Calapan');
            $table->string('support_email')->nullable();
            $table->string('timezone')->default('Asia/Manila');
            $table->string('date_format')->default('Y-m-d');

            // Additional module toggles
            $table->boolean('payroll_enabled')->default(true);
            $table->boolean('attendance_enabled')->default(true);
            $table->boolean('eta_enabled')->default(true);

            // Attendance — shift schedule
            $table->string('work_start')->default('08:00');
            $table->string('lunch_return')->default('13:00');
            $table->string('work_end')->default('17:00');
            $table->string('morning_end')->default('11:00');
            $table->string('noon_end')->default('14:00');

            // Payroll
            $table->unsignedTinyInteger('payroll_working_days_per_month')->default(22);

            // Leave
            $table->unsignedTinyInteger('leave_balance_decimals')->default(3);

            // Notification / email from
            $table->string('mail_from_address')->nullable();
            $table->string('mail_from_name')->nullable();

            // Export
            $table->string('excel_sheet_password')->nullable();
            $table->boolean('excel_protection_enabled')->default(true);
            $table->string('pdf_font_family')->default('Arial');
            $table->unsignedTinyInteger('pdf_font_size')->default(9);

            // Dashboard
            $table->unsignedSmallInteger('dashboard_cache_ttl')->default(10);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'system_name', 'org_name', 'support_email', 'timezone', 'date_format',
                'payroll_enabled', 'attendance_enabled', 'eta_enabled',
                'work_start', 'lunch_return', 'work_end', 'morning_end', 'noon_end',
                'payroll_working_days_per_month', 'leave_balance_decimals',
                'mail_from_address', 'mail_from_name',
                'excel_sheet_password', 'excel_protection_enabled',
                'pdf_font_family', 'pdf_font_size',
                'dashboard_cache_ttl',
            ]);
        });
    }
};
