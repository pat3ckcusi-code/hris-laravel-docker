<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each create is guarded with hasTable() so this migration is safe to
        // run on a database that was restored from a backup (all tables present)
        // or on a fresh schema where some tables were pre-created by other means.

        if (! Schema::hasTable('plantillas')) {
            Schema::create('plantillas', function (Blueprint $table) {
                $table->id();
                $table->string('title');
                $table->integer('salary_grade');
                $table->integer('step')->default(1);
                $table->string('employment_type')->default('permanent');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_assignments')) {
            Schema::create('employee_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('plantilla_id')->constrained('plantillas')->cascadeOnDelete();
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('salary_matrices')) {
            Schema::create('salary_matrices', function (Blueprint $table) {
                $table->id();
                $table->integer('sg');
                $table->integer('step');
                $table->year('year');
                $table->decimal('amount', 12, 2);
                $table->timestamps();
                $table->unique(['sg', 'step', 'year']);
            });
        }

        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $table) {
                $table->id();
                $table->string('period');
                $table->string('status')->default('draft');
                $table->timestamp('locked_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_details')) {
            Schema::create('payroll_details', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->decimal('basic_salary', 12, 2)->default(0);
                $table->decimal('earnings', 12, 2)->default(0);
                $table->decimal('deductions', 12, 2)->default(0);
                $table->decimal('net_pay', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('dtrs')) {
            Schema::create('dtrs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->date('date');
                $table->time('time_in')->nullable();
                $table->time('time_out')->nullable();
                $table->string('status')->default('present');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('earnings')) {
            Schema::create('earnings', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->string('description')->nullable();
                $table->boolean('recurring')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_earnings')) {
            Schema::create('employee_earnings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('earnings_id')->constrained('earnings')->cascadeOnDelete();
                $table->decimal('amount', 12, 2)->default(0);
                $table->boolean('recurring')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('deductions')) {
            Schema::create('deductions', function (Blueprint $table) {
                $table->id();
                $table->string('type');
                $table->string('description')->nullable();
                $table->string('formula')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('employee_deductions')) {
            Schema::create('employee_deductions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('deduction_id')->constrained('deductions')->cascadeOnDelete();
                $table->decimal('amount', 12, 2)->default(0);
                $table->boolean('recurring')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('loans')) {
            Schema::create('loans', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('deduction_id')->constrained('deductions')->cascadeOnDelete();
                $table->decimal('balance', 12, 2)->default(0);
                $table->decimal('monthly_payment', 12, 2)->default(0);
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leave_records')) {
            Schema::create('leave_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->date('date');
                $table->string('type');
                $table->boolean('lwop_flag')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_exceptions')) {
            Schema::create('payroll_exceptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
                $table->string('type');
                $table->text('description')->nullable();
                $table->boolean('resolved_flag')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('approval_logs')) {
            Schema::create('approval_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
                $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();
                $table->string('status');
                $table->timestamp('actioned_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payslips')) {
            Schema::create('payslips', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
                $table->string('pdf_path')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_audit_logs')) {
            Schema::create('payroll_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('action');
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
                $table->text('details')->nullable();
                $table->timestamp('actioned_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payroll_settings')) {
            Schema::create('payroll_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_settings');
        Schema::dropIfExists('payroll_audit_logs');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('approval_logs');
        Schema::dropIfExists('payroll_exceptions');
        Schema::dropIfExists('leave_records');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('employee_deductions');
        Schema::dropIfExists('deductions');
        Schema::dropIfExists('employee_earnings');
        Schema::dropIfExists('earnings');
        Schema::dropIfExists('dtrs');
        Schema::dropIfExists('payroll_details');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('salary_matrices');
        Schema::dropIfExists('employee_assignments');
        Schema::dropIfExists('plantillas');
    }
};
