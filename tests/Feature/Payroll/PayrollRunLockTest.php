<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\EmployeeAssignment;
use App\Models\Loan;
use App\Models\PayrollAuditLog;
use App\Models\PayrollLoanDeduction;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Locking a payroll run finalizes each active loan's charged amount into
 * Loan.balance (and flips status to 'paid' at zero), so a loan naturally
 * drops out of future runs' deduction_breakdown once paid off instead of
 * being deducted forever regardless of how many runs pass.
 */
class PayrollRunLockTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function seedPayrollScaffold(array $runOverrides = [], int $salaryGrade = 6): array
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();

        $plantilla = Plantilla::create([
            'title' => 'Clerk III',
            'salary_grade' => $salaryGrade,
            'step' => 1,
            'employment_type' => 'permanent',
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
        ]);

        SalaryMatrix::firstOrCreate([
            'sg' => $salaryGrade,
            'step' => 1,
            'year' => 2026,
        ], [
            'amount' => 18620.00,
        ]);

        $run = PayrollRun::create(array_merge([
            'period' => 'July 2026',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => 'draft',
            'created_by' => $admin->id,
        ], $runOverrides));

        return compact('admin', 'employee', 'run');
    }

    private function makeLoan(int $employeeId, float $balance, float $monthlyPayment = 750.50): Loan
    {
        $deduction = Deduction::create([
            'type' => 'UCPB',
            'deduction_category' => 'loan',
            'provider' => 'UCPB',
        ]);

        return Loan::create([
            'employee_id' => $employeeId,
            'deduction_id' => $deduction->id,
            'balance' => $balance,
            'monthly_payment' => $monthlyPayment,
            'status' => 'active',
        ]);
    }

    public function test_lock_sets_status_and_locked_at(): void
    {
        ['admin' => $admin, 'run' => $run] = $this->seedPayrollScaffold();

        $response = $this->actingAs($admin)->post(route('payroll.runs.lock', $run->id));

        $response->assertSessionHas('status', 'Payroll run has been locked.');
        $run->refresh();
        $this->assertEquals('locked', $run->status);
        $this->assertNotNull($run->locked_at);
    }

    public function test_lock_is_rejected_for_an_already_locked_run(): void
    {
        ['admin' => $admin, 'run' => $run] = $this->seedPayrollScaffold(['status' => 'locked', 'locked_at' => now()]);
        $lockedAt = $run->locked_at;

        $auditCountBefore = PayrollAuditLog::where('payroll_run_id', $run->id)->where('action', 'payroll_locked')->count();

        $response = $this->actingAs($admin)->post(route('payroll.runs.lock', $run->id));

        $response->assertSessionHas('error', 'This payroll run is already locked.');
        $run->refresh();
        $this->assertEquals('locked', $run->status);
        $this->assertTrue($lockedAt->equalTo($run->locked_at));
        $this->assertEquals(
            $auditCountBefore,
            PayrollAuditLog::where('payroll_run_id', $run->id)->where('action', 'payroll_locked')->count()
        );
    }

    public function test_lock_decrements_loan_balance_by_the_charged_amount_and_records_a_snapshot_row(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $loan = $this->makeLoan($employee->id, balance: 5000, monthlyPayment: 750.50);

        (new PayrollComputationService)->compute($run, $admin);
        $this->actingAs($admin)->post(route('payroll.runs.lock', $run->id));

        $loan->refresh();
        $this->assertEquals(4249.50, $loan->balance);
        $this->assertEquals('active', $loan->status);

        $snapshot = PayrollLoanDeduction::where('payroll_run_id', $run->id)->where('loan_id', $loan->id)->first();
        $this->assertNotNull($snapshot);
        $this->assertEquals(750.50, $snapshot->amount);
        $this->assertEquals(5000, $snapshot->balance_before);
        $this->assertEquals(4249.50, $snapshot->balance_after);
    }

    public function test_lock_flips_loan_status_to_paid_when_balance_reaches_zero(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $loan = $this->makeLoan($employee->id, balance: 500, monthlyPayment: 750.50);

        (new PayrollComputationService)->compute($run, $admin);
        $this->actingAs($admin)->post(route('payroll.runs.lock', $run->id));

        $loan->refresh();
        $this->assertEquals(0, $loan->balance);
        $this->assertEquals('paid', $loan->status);
    }

    public function test_lock_does_not_decrement_a_loan_twice_on_a_double_lock_attempt(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $loan = $this->makeLoan($employee->id, balance: 5000, monthlyPayment: 750.50);

        (new PayrollComputationService)->compute($run, $admin);
        $this->actingAs($admin)->post(route('payroll.runs.lock', $run->id));
        $this->actingAs($admin)->post(route('payroll.runs.lock', $run->id));

        $loan->refresh();
        $this->assertEquals(4249.50, $loan->balance);
        $this->assertEquals(1, PayrollLoanDeduction::where('payroll_run_id', $run->id)->where('loan_id', $loan->id)->count());
    }

    public function test_lock_writes_a_loan_deductions_applied_audit_log_when_a_loan_was_decremented(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $this->makeLoan($employee->id, balance: 5000, monthlyPayment: 750.50);

        (new PayrollComputationService)->compute($run, $admin);
        $this->actingAs($admin)->post(route('payroll.runs.lock', $run->id));

        $this->assertEquals(
            1,
            PayrollAuditLog::where('payroll_run_id', $run->id)->where('action', 'loan_deductions_applied')->count()
        );
    }

    public function test_lock_does_not_write_a_loan_deductions_applied_audit_log_when_no_loan_was_decremented(): void
    {
        ['admin' => $admin, 'run' => $run] = $this->seedPayrollScaffold();

        (new PayrollComputationService)->compute($run, $admin);
        $this->actingAs($admin)->post(route('payroll.runs.lock', $run->id));

        $this->assertEquals(
            0,
            PayrollAuditLog::where('payroll_run_id', $run->id)->where('action', 'loan_deductions_applied')->count()
        );
    }

    public function test_two_sequential_monthly_runs_each_decrement_the_loan_once(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $julyRun] = $this->seedPayrollScaffold([
            'period' => 'July 2026',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);
        $loan = $this->makeLoan($employee->id, balance: 5000, monthlyPayment: 750.50);

        // July: compute + lock.
        (new PayrollComputationService)->compute($julyRun, $admin);
        $this->actingAs($admin)->post(route('payroll.runs.lock', $julyRun->id));
        $loan->refresh();
        $this->assertEquals(4249.50, $loan->balance);

        // August: a separate run for the same loan.
        $augustRun = PayrollRun::create([
            'period' => 'August 2026',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        (new PayrollComputationService)->compute($augustRun, $admin);
        $this->actingAs($admin)->post(route('payroll.runs.lock', $augustRun->id));
        $loan->refresh();
        $this->assertEquals(3499.00, $loan->balance);

        $this->assertEquals(2, PayrollLoanDeduction::where('loan_id', $loan->id)->count());
    }

    public function test_recompute_before_lock_does_not_touch_loan_balance(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $loan = $this->makeLoan($employee->id, balance: 5000, monthlyPayment: 750.50);

        $service = new PayrollComputationService;
        $service->compute($run, $admin);
        $loan->refresh();
        $this->assertEquals(5000, $loan->balance);

        // Recomputing a still-unlocked run is a safe, repeatable wipe-and-rebuild.
        $service->compute($run, $admin);
        $loan->refresh();
        $this->assertEquals(5000, $loan->balance);
        $this->assertEquals(0, PayrollLoanDeduction::where('loan_id', $loan->id)->count());
    }
}
