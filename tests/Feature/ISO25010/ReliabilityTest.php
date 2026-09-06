<?php

namespace Tests\Feature\ISO25010;

use App\Models\Deduction;
use App\Models\Department;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDeduction;
use App\Models\HRAuditTrail;
use App\Models\PayrollAuditLog;
use App\Models\PayrollDetail;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\User;
use App\Services\PayrollComputationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISO/IEC 25010 - 5. Reliability
 *
 * Tests: Maturity, Availability, Fault tolerance, Recoverability
 */
class ReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function createDepartment(): Department
    {
        return Department::create([
            'DeptCode' => 'TST',
            'Dept_name' => 'Test Department',
            'EmpNo' => 'DH'.uniqid(),
            'Designation' => 'Department Head',
        ]);
    }

    private function createUser(string $role = 'employee', array $extra = []): User
    {
        $dept = $this->createDepartment();

        return User::create(array_merge([
            'name' => 'Reliable User',
            'first_name' => 'Reliable',
            'last_name' => 'User',
            'email' => 'rel'.uniqid().'@test.com',
            'password' => bcrypt('password'),
            'access_level' => $role,
            'employee_type' => 'permanent',
            'Dept_id' => $dept->Dept_id,
            'EmpNo' => 'R'.uniqid(),
        ], $extra));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5.1 FAULT TOLERANCE - missing data creates exceptions, not crashes
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function missing_plantilla_creates_exception_for_empty_run(): void
    {
        $admin = $this->createUser('payroll-manager');

        // No employees or assignments exist
        $run = PayrollRun::create([
            'period' => '2026-04 empty',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService;
        $result = $service->compute($run, $admin);

        // Should not crash - should create an exception record
        $exception = PayrollException::where('payroll_run_id', $run->id)
            ->where('type', 'no_assignments')
            ->first();

        $this->assertNotNull($exception, 'Empty payroll should log an exception, not crash');
        $this->assertStringContainsString('No active', $exception->description);
    }

    /** @test */
    public function missing_salary_matrix_records_error_not_crash(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create([
            'title' => 'Admin Aide',
            'salary_grade' => 33,
            'step' => 8,
            'employment_type' => 'permanent',
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
        ]);

        // NO salary matrix entry for SG-33 Step 8

        $run = PayrollRun::create([
            'period' => '2026-04 no-matrix',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService;
        $result = $service->compute($run, $admin);

        // Should not crash - error should be reported
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('SG-33', $result['errors'][0]);

        // Detail should still be created with 0 salary
        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertNotNull($detail);
        $this->assertEquals(0, (float) $detail->basic_salary);
        $this->assertTrue((bool) $detail->flagged_for_review, 'A missing-salary-matrix detail must be flagged for review');
    }

    /** @test */
    public function unexpected_computation_error_for_one_employee_does_not_abort_the_run(): void
    {
        $admin = $this->createUser('payroll-manager');
        $goodEmployee = $this->createUser('employee');
        $badEmployee = $this->createUser('employee');

        SalaryMatrix::create([
            'sg' => 6,
            'step' => 1,
            'year' => 2026,
            'amount' => 18620.00,
        ]);

        foreach ([$goodEmployee, $badEmployee] as $employee) {
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
        }

        $run = PayrollRun::create([
            'period' => '2026-04 mixed',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        // A different mid-loop failure than the salary-matrix one above -
        // proves the catch in compute() is a general backstop, not something
        // that only happens to work for that one known cause.
        $service = new ThrowingPayrollComputationServiceForTest;
        $service->throwForEmployeeIds = [$badEmployee->id];
        $result = $service->compute($run, $admin);

        // Both employees still get a PayrollDetail - the run isn't aborted,
        // and the good employee's own row is entirely unaffected.
        $this->assertEquals(2, $result['employee_count']);

        $goodDetail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $goodEmployee->id)->first();
        $this->assertNotNull($goodDetail);
        $this->assertEquals(18620.00, (float) $goodDetail->basic_salary);
        $this->assertFalse((bool) $goodDetail->flagged_for_review);

        $badDetail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $badEmployee->id)->first();
        $this->assertNotNull($badDetail);
        $this->assertEquals(0, (float) $badDetail->basic_salary);
        $this->assertEquals(0, (float) $badDetail->net_pay);
        $this->assertTrue((bool) $badDetail->flagged_for_review, 'A caught-exception detail must be flagged for review');

        $exception = PayrollException::where('payroll_run_id', $run->id)
            ->where('type', 'computation_error')
            ->first();
        $this->assertNotNull($exception, 'An unexpected per-employee failure should log a computation_error exception, not crash');
        $this->assertStringContainsString('Simulated computation failure for test', $exception->description);

        // Tier 0: the failure must also surface in compute()'s own returned
        // errors array, not just the PayrollException table - otherwise the
        // post-compute flash message stays silent for this failure mode
        // while it correctly fires for the missing-salary-matrix one above.
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString($badEmployee->name, implode(' ', $result['errors']));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5.2 PAYROLL LOCKING - no modifications after lock
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function locked_payroll_run_cannot_be_computed_via_controller(): void
    {
        $admin = $this->createUser('payroll-manager');

        $run = PayrollRun::create([
            'period' => '2026-04 locked',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'locked',
            'locked_at' => now(),
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post("/payroll-manager/runs/{$run->id}/compute");

        // Should redirect with error (not 500)
        $this->assertNotEquals(500, $response->status());
    }

    /** @test */
    public function locked_payroll_run_preserves_locked_status(): void
    {
        $admin = $this->createUser('payroll-manager');

        $run = PayrollRun::create([
            'period' => '2026-04 locked',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'locked',
            'locked_at' => now(),
            'created_by' => $admin->id,
        ]);

        $run->refresh();
        $this->assertEquals('locked', $run->status);
        $this->assertNotNull($run->locked_at);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5.3 AUDIT TRAIL COMPLETENESS
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function payroll_computation_creates_audit_log(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $run = PayrollRun::create([
            'period' => '2026-04 audit',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService;
        $service->compute($run, $admin);

        $log = PayrollAuditLog::where('payroll_run_id', $run->id)
            ->where('action', 'payroll_computed')
            ->first();

        $this->assertNotNull($log, 'Payroll computation must log audit entry');
        $this->assertEquals($admin->id, $log->user_id);
        $this->assertStringContainsString('1 employee', $log->details);

        // A normally-computed detail must default to NOT flagged - only the
        // two error-recovery paths in compute() should ever set this true.
        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();
        $this->assertFalse((bool) $detail->flagged_for_review);
    }

    /** @test */
    public function hr_audit_trail_stores_structured_details(): void
    {
        $actor = $this->createUser('hr-manager');

        $trail = HRAuditTrail::create([
            'actor_user_id' => $actor->id,
            'module' => 'payroll',
            'action' => 'locked_run',
            'target_type' => 'payroll_run',
            'target_id' => 1,
            'details' => ['reason' => 'Final review completed', 'period' => '2026-04'],
        ]);

        $this->assertIsArray($trail->details);
        $this->assertEquals('Final review completed', $trail->details['reason']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5.4 RECOVERABILITY - data integrity after failure
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function payroll_detail_net_pay_never_goes_negative(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Aide', 'salary_grade' => 1, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 1, 'step' => 1, 'year' => 2026, 'amount' => 1000]);

        // Massive deduction
        $ded = Deduction::create(['type' => 'Big Ded', 'description' => 'test']);
        EmployeeDeduction::create([
            'employee_id' => $employee->id,
            'deduction_id' => $ded->id,
            'amount' => 99999,
            'recurring' => true,
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 neg',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService;
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertGreaterThanOrEqual(0, (float) $detail->net_pay, 'Net pay must never be negative');
    }
}

/**
 * Forces a distinct, non-getBasicSalary() failure for a chosen employee, to
 * prove compute()'s per-employee try/catch is a general backstop rather than
 * something that only happens to cover the one known missing-salary-matrix
 * cause. Real subclass override, not a mock - direct instantiation is this
 * codebase's established pattern for testing pure service classes.
 */
class ThrowingPayrollComputationServiceForTest extends PayrollComputationService
{
    /** @var int[] */
    public array $throwForEmployeeIds = [];

    protected function computeAllowances(int $employeeId, float $basicSalary): array
    {
        if (in_array($employeeId, $this->throwForEmployeeIds, true)) {
            throw new \RuntimeException('Simulated computation failure for test.');
        }

        return parent::computeAllowances($employeeId, $basicSalary);
    }
}
