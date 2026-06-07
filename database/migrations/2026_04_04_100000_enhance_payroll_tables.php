<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── DTR: replace single time_in/time_out with AM/PM pair ──
        // Guards are required so this migration is safe to run after a backup
        // restore, where these columns already exist in their final form.
        if (! Schema::hasColumn('dtrs', 'time_in_am')) {
            Schema::table('dtrs', function (Blueprint $table) {
                $table->time('time_in_am')->nullable()->after('date');
                $table->time('time_out_am')->nullable()->after('time_in_am');
                $table->time('time_in_pm')->nullable()->after('time_out_am');
                $table->time('time_out_pm')->nullable()->after('time_in_pm');
                $table->integer('late_minutes')->default(0)->after('time_out_pm');
                $table->integer('undertime_minutes')->default(0)->after('late_minutes');
                $table->boolean('is_absent')->default(false)->after('undertime_minutes');
            });

            // Migrate existing data using Eloquent for portability
            \App\Models\Dtr::whereNotNull('time_in')->orWhereNotNull('time_out')->each(function ($dtr) {
                $dtr->update([
                    'time_in_am' => $dtr->getRawOriginal('time_in'),
                    'time_out_pm' => $dtr->getRawOriginal('time_out'),
                ]);
            });

            Schema::table('dtrs', function (Blueprint $table) {
                $table->dropColumn(['time_in', 'time_out']);
            });
        }

        // ── PayrollRun: add period date range for computation ──
        if (! Schema::hasColumn('payroll_runs', 'period_start')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->date('period_start')->nullable()->after('period');
                $table->date('period_end')->nullable()->after('period_start');
            });
        }

        // ── PayrollDetail: add breakdown columns ──
        if (! Schema::hasColumn('payroll_details', 'days_worked')) {
            Schema::table('payroll_details', function (Blueprint $table) {
                $table->integer('days_worked')->default(0)->after('employee_id');
                $table->integer('late_minutes')->default(0)->after('days_worked');
                $table->integer('undertime_minutes')->default(0)->after('late_minutes');
                $table->integer('absent_days')->default(0)->after('undertime_minutes');
                $table->decimal('lwop_deduction', 12, 2)->default(0)->after('deductions');
                $table->decimal('loan_deduction', 12, 2)->default(0)->after('lwop_deduction');
            });
        }

        // ── EmployeeEarning: add recurring_flag (alias-safe) ──
        // Column 'amount' and 'recurring' already exist from original migration.
        // No changes needed — schema already has amount + recurring.

        // ── EmployeeDeduction: same — already has amount + recurring ──

        // ── EmployeeAssignment: add pds_id for linkage to user_pds ──
        if (! Schema::hasColumn('employee_assignments', 'pds_id')) {
            Schema::table('employee_assignments', function (Blueprint $table) {
                $table->unsignedBigInteger('pds_id')->nullable()->after('employee_id');
                $table->foreign('pds_id')->references('id')->on('user_pds')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('employee_assignments', function (Blueprint $table) {
            $table->dropForeign(['pds_id']);
            $table->dropColumn('pds_id');
        });

        Schema::table('payroll_details', function (Blueprint $table) {
            $table->dropColumn(['days_worked', 'late_minutes', 'undertime_minutes', 'absent_days', 'lwop_deduction', 'loan_deduction']);
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['period_start', 'period_end']);
        });

        Schema::table('dtrs', function (Blueprint $table) {
            $table->time('time_in')->nullable()->after('date');
            $table->time('time_out')->nullable()->after('time_in');
            $table->dropColumn(['time_in_am', 'time_out_am', 'time_in_pm', 'time_out_pm', 'late_minutes', 'undertime_minutes', 'is_absent']);
        });
    }
};
