<?php

namespace Tests\Feature\ISO25010;

use App\Models\Deduction;
use App\Models\Department;
use App\Models\Earning;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeEarning;
use App\Models\Loan;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\User;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISO/IEC 25010 - 7. Maintainability
 *
 * Tests: Modularity, Reusability, Analysability, Modifiability, Testability
 */
class MaintainabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Database\Eloquent\Model::unguard();
    }

    protected function tearDown(): void
    {
        \Illuminate\Database\Eloquent\Model::reguard();
        parent::tearDown();
    }

    private function createDepartment(): Department
    {
        return Department::create([
            'DeptCode' => 'TST',
            'Dept_name' => 'Test Department',
            'EmpNo' => 'DH' . uniqid(),
            'Designation' => 'Department Head',
        ]);
    }

    private function createUser(string $role = 'employee', array $extra = []): User
    {
        $dept = $this->createDepartment();
        return User::create(array_merge([
            'name' => 'Maint User',
            'first_name' => 'Maint',
            'last_name' => 'User',
            'email' => 'maint' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'access_level' => $role,
            'employee_type' => 'permanent',
            'Dept_id' => $dept->Dept_id,
            'EmpNo' => 'M' . uniqid(),
        ], $extra));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 7.1 MODIFIABILITY - add new earning type (Hazard Pay)
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function new_earning_type_hazard_pay_included_in_payroll(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Nurse', 'salary_grade' => 11, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 11, 'step' => 1, 'year' => 2026, 'amount' => 27000]);

        // Add Hazard Pay as a new earning type
        $hazardPay = Earning::create([
            'type' => 'Hazard Pay',
            'description' => 'Hazard duty pay for health workers',
            'recurring' => true,
        ]);

        EmployeeEarning::create([
            'employee_id' => $employee->id,
            'earnings_id' => $hazardPay->id,
            'amount' => 3500,
            'recurring' => true,
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 hazard',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertNotNull($detail);
        $this->assertEquals(3500.00, (float) $detail->earnings);
        $this->assertEquals(27000 + 3500, (float) $detail->net_pay);
    }

    /** @test */
    public function multiple_earning_types_coexist_with_hazard_pay(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Nurse', 'salary_grade' => 11, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 11, 'step' => 1, 'year' => 2026, 'amount' => 27000]);

        // PERA + Hazard Pay
        $pera = Earning::create(['type' => 'PERA', 'description' => 'PERA', 'recurring' => true]);
        $hazard = Earning::create(['type' => 'Hazard Pay', 'description' => 'Hazard', 'recurring' => true]);
        EmployeeEarning::create(['employee_id' => $employee->id, 'earnings_id' => $pera->id, 'amount' => 2000, 'recurring' => true]);
        EmployeeEarning::create(['employee_id' => $employee->id, 'earnings_id' => $hazard->id, 'amount' => 3500, 'recurring' => true]);

        $run = PayrollRun::create([
            'period' => '2026-04 multi-earn',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertEquals(5500.00, (float) $detail->earnings); // 2000 + 3500
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 7.2 MODIFIABILITY - add new deduction type (Loan)
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function new_loan_deduction_type_applies_monthly_payment(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        // Housing Loan
        $housingLoanType = Deduction::create(['type' => 'Pag-IBIG Housing Loan', 'description' => 'Housing loan']);
        Loan::create([
            'employee_id' => $employee->id,
            'deduction_id' => $housingLoanType->id,
            'balance' => 200000,
            'monthly_payment' => 3000,
            'status' => 'active',
        ]);

        // Salary Loan
        $salaryLoanType = Deduction::create(['type' => 'GSIS Salary Loan', 'description' => 'Salary loan']);
        Loan::create([
            'employee_id' => $employee->id,
            'deduction_id' => $salaryLoanType->id,
            'balance' => 50000,
            'monthly_payment' => 2000,
            'status' => 'active',
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 loans',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertEquals(5000.00, (float) $detail->loan_deduction); // 3000 + 2000
    }

    /** @test */
    public function paid_off_loan_excluded_from_deductions(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $ded = Deduction::create(['type' => 'Old Loan', 'description' => 'Paid off']);

        // Loan with 0 balance (paid off)
        Loan::create([
            'employee_id' => $employee->id,
            'deduction_id' => $ded->id,
            'balance' => 0,
            'monthly_payment' => 2000,
            'status' => 'active',
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 paid-loan',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertEquals(0, (float) $detail->loan_deduction, 'Paid-off loans should not deduct');
    }

    /** @test */
    public function non_recurring_earning_excluded_from_payroll(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $earning = Earning::create(['type' => 'Bonus', 'description' => 'One-time bonus', 'recurring' => false]);
        EmployeeEarning::create([
            'employee_id' => $employee->id,
            'earnings_id' => $earning->id,
            'amount' => 10000,
            'recurring' => false,
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 non-recur',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        // Non-recurring earnings should NOT be included
        $this->assertEquals(0, (float) $detail->earnings);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 7.3 MODULARITY - controllers and models are modular
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function payroll_computation_service_is_injectable(): void
    {
        $service = app(PayrollComputationService::class);
        $this->assertInstanceOf(PayrollComputationService::class, $service);
    }

    /** @test */
    public function models_have_correct_relationships(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        $assignment = EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);

        $run = PayrollRun::create([
            'period' => '2026-04',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        // Test relationships
        $this->assertEquals($employee->id, $assignment->employee->id);
        $this->assertEquals($plantilla->id, $assignment->plantilla->id);
        $this->assertEquals($admin->id, $run->creator->id);
        $this->assertNotNull($plantilla->assignments);
    }

    /** @test */
    public function non_recurring_deduction_excluded_from_payroll(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $ded = Deduction::create(['type' => 'One-time', 'description' => 'One-time deduction']);
        EmployeeDeduction::create([
            'employee_id' => $employee->id,
            'deduction_id' => $ded->id,
            'amount' => 5000,
            'recurring' => false,
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 non-recur-ded',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertEquals(0, (float) $detail->deductions);
    }
}
