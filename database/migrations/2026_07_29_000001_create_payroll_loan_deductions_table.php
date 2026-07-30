<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_loan_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('payroll_detail_id')->constrained('payroll_details')->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('balance_before', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2)->default(0);
            $table->timestamps();

            // A loan can be decremented at most once per run, even if
            // applyLoanDeductions() is somehow invoked twice for the same run.
            $table->unique(['payroll_run_id', 'loan_id']);
            // The composite unique index above doesn't cover loan_id-only
            // lookups (leftmost-prefix rule) - Loan::payrollDeductions() needs its own.
            $table->index('loan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_loan_deductions');
    }
};
