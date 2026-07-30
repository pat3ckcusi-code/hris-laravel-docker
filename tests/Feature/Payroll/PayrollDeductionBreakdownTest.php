<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDeduction;
use App\Models\Loan;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * PayrollComputationService's named per-line deduction breakdown, feeding
 * the real Payslip PDF (mandatory 4 lines + per-loan lines + other-deduction
 * lines), and other_deductions folding correctly into net_pay.
 */
class PayrollDeductionBreakdownTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function seedPayrollScaffold(): array
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();

        $plantilla = Plantilla::create([
            'title' => 'Clerk III',
            'salary_grade' => 6,
            'step' => 1,
            'employment_type' => 'permanent',
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
        ]);

        SalaryMatrix::create([
            'sg' => 6,
            'step' => 1,
            'year' => 2026,
            'amount' => 18620.00,
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        return compact('admin', 'employee', 'run');
    }

    public function test_mandatory_deductions_appear_with_government_labels_in_breakdown(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $labels = collect($detail->deduction_breakdown)->pluck('label')->all();

        $this->assertContains('Life & Retirement', $labels);
        $this->assertContains('Medicare', $labels);
        $this->assertContains('HDMF (Pag-ibig)', $labels);
        $this->assertContains('Withholding Tax', $labels);
    }

    public function test_active_loan_appears_as_its_own_breakdown_line_with_correct_amount(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();

        $deduction = Deduction::create([
            'type' => 'CGCEMCO',
            'deduction_category' => 'loan',
            'provider' => 'CGCEMCO',
        ]);
        $loan = Loan::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'balance' => 5000,
            'monthly_payment' => 750.50,
            'status' => 'active',
        ]);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $line = collect($detail->deduction_breakdown)->firstWhere('label', 'CGCEMCO');

        $this->assertNotNull($line);
        $this->assertEquals('loan', $line['category']);
        $this->assertEquals('CGCEMCO', $line['provider']);
        $this->assertEquals(750.50, $line['amount']);
        $this->assertEquals($loan->id, $line['loan_id']);
        $this->assertEquals(750.50, $detail->loan_deduction);
    }

    public function test_inactive_or_zero_balance_loan_is_excluded_from_breakdown(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();

        $deduction = Deduction::create(['type' => 'RB Baco', 'deduction_category' => 'loan']);
        Loan::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'balance' => 0,
            'monthly_payment' => 500,
            'status' => 'paid',
        ]);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $labels = collect($detail->deduction_breakdown)->pluck('label')->all();

        $this->assertNotContains('RB Baco', $labels);
        $this->assertEquals(0, $detail->loan_deduction);
    }

    public function test_recurring_other_deduction_appears_as_its_own_breakdown_line(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();

        $deduction = Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other']);
        EmployeeDeduction::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'amount' => 100,
            'recurring' => true,
        ]);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $line = collect($detail->deduction_breakdown)->firstWhere('label', 'Cellphone');

        $this->assertNotNull($line);
        $this->assertEquals('other', $line['category']);
        $this->assertEquals(100.0, $detail->other_deductions);
    }

    public function test_non_recurring_other_deduction_is_excluded(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();

        $deduction = Deduction::create(['type' => 'One-time cash advance', 'deduction_category' => 'other']);
        EmployeeDeduction::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'amount' => 2000,
            'recurring' => false,
        ]);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();

        $this->assertEquals(0, $detail->other_deductions);
    }

    public function test_net_pay_subtracts_other_deductions(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();

        $deduction = Deduction::create(['type' => 'LIFE', 'deduction_category' => 'other']);
        EmployeeDeduction::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'amount' => 250,
            'recurring' => true,
        ]);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();

        $expectedNet = $detail->gross_pay - $detail->deductions - $detail->loan_deduction - $detail->other_deductions - $detail->lwop_deduction;
        $this->assertEquals(round(max(0, $expectedNet), 2), round($detail->net_pay, 2));
    }
}
