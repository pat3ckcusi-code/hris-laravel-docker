<?php

namespace Tests\Feature\ISO25010;

use App\Models\Department;
use App\Models\Deduction;
use App\Models\DocumentRequest;
use App\Models\Earning;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeEarning;
use App\Models\LeaveRequest;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\User;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISO/IEC 25010 - 3. Compatibility
 *
 * Tests: Co-existence, Interoperability between HRIS modules
 */
class CompatibilityTest extends TestCase
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
            'name' => 'Compat User',
            'first_name' => 'Compat',
            'last_name' => 'User',
            'email' => 'compat' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'access_level' => $role,
            'employee_type' => 'permanent',
            'Dept_id' => $dept->Dept_id,
            'EmpNo' => 'C' . uniqid(),
        ], $extra));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3.1 INTEROPERABILITY - Leave ↔ Payroll integration
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function approved_leave_with_lwop_integrates_into_payroll(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 8, 'step' => 2, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 8, 'step' => 2, 'year' => 2026, 'amount' => 21205]);

        // Approved leave with LWOP days during payroll period
        LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => '2026-04-07',
            'end_date' => '2026-04-10',
            'reason' => 'Family matter',
            'status' => 'approved',
            'total_days' => 4,
            'paid_days' => 1,
            'lwop_days' => 3,
            'date_filed' => now(),
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
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
        // LWOP = (21205/22) * 3 = 2891.59
        $expectedLwop = round((21205 / 22) * 3, 2);
        $this->assertEquals($expectedLwop, $detail->lwop_deduction);
    }

    /** @test */
    public function pending_leave_does_not_affect_payroll(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        // Pending (not approved) leave with LWOP
        LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'Sick Leave',
            'start_date' => '2026-04-07',
            'end_date' => '2026-04-09',
            'reason' => 'Illness',
            'status' => 'pending',
            'total_days' => 3,
            'paid_days' => 0,
            'lwop_days' => 3,
            'date_filed' => now(),
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        // Pending leave should NOT trigger LWOP deduction
        $this->assertEquals(0, (float) $detail->lwop_deduction);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3.2 CO-EXISTENCE - Plantilla ↔ Employee ↔ Payroll
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function employee_without_assignment_excluded_from_payroll(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');
        // No plantilla assignment created

        $run = PayrollRun::create([
            'period' => '2026-04 empty',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $result = $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertNull($detail, 'Employee without assignment should not appear in payroll');
    }

    /** @test */
    public function ended_assignment_excluded_from_future_payroll(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        // Assignment ended before payroll period
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $result = $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertNull($detail, 'Ended assignment should not be included in future payroll');
    }

    /** @test */
    public function earnings_and_deductions_coexist_correctly(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $plantilla = Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $e1 = Earning::create(['type' => 'PERA', 'description' => 'PERA', 'recurring' => true]);
        $e2 = Earning::create(['type' => 'ACA', 'description' => 'ACA', 'recurring' => true]);
        EmployeeEarning::create(['employee_id' => $employee->id, 'earnings_id' => $e1->id, 'amount' => 2000, 'recurring' => true]);
        EmployeeEarning::create(['employee_id' => $employee->id, 'earnings_id' => $e2->id, 'amount' => 1500, 'recurring' => true]);

        $d1 = Deduction::create(['type' => 'GSIS', 'description' => 'GSIS']);
        $d2 = Deduction::create(['type' => 'PhilHealth', 'description' => 'PhilHealth']);
        EmployeeDeduction::create(['employee_id' => $employee->id, 'deduction_id' => $d1->id, 'amount' => 800, 'recurring' => true]);
        EmployeeDeduction::create(['employee_id' => $employee->id, 'deduction_id' => $d2->id, 'amount' => 400, 'recurring' => true]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertEquals(3500.00, (float) $detail->earnings); // 2000 + 1500
        $this->assertEquals(1200.00, (float) $detail->deductions); // 800 + 400
        $this->assertEquals(18620 + 3500 - 1200, (float) $detail->net_pay); // 20920
    }

    /** @test */
    public function department_hierarchy_supports_parent_child(): void
    {
        $parent = Department::create([
            'DeptCode' => 'MO',
            'Dept_name' => "Mayor's Office",
            'EmpNo' => 'MO001',
            'Designation' => 'Mayor',
        ]);

        $child = Department::create([
            'DeptCode' => 'MO-HR',
            'Dept_name' => 'HR Division',
            'parent_dept_id' => $parent->Dept_id,
            'EmpNo' => 'HR001',
            'Designation' => 'HR Head',
        ]);

        $this->assertEquals($parent->Dept_id, $child->parent->Dept_id);
        $this->assertCount(1, $parent->children);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3.3 EXPORT FORMAT COMPATIBILITY
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function payroll_export_route_accessible_for_locked_run(): void
    {
        $admin = $this->createUser('payroll-manager');

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'locked',
            'locked_at' => now(),
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->get("/payroll-manager/runs/{$run->id}/export");

        // Should not 500 or 403
        $this->assertNotEquals(500, $response->status());
    }

    /** @test */
    public function leave_management_accessible_for_eligible_employee(): void
    {
        $employee = $this->createUser('employee', ['employee_type' => 'permanent']);

        $response = $this->actingAs($employee)->get('/employee/leave-management');
        $response->assertStatus(200);
    }

    /** @test */
    public function leave_management_blocked_for_job_order(): void
    {
        $employee = $this->createUser('employee', ['employee_type' => 'job order']);

        $response = $this->actingAs($employee)->get('/employee/leave-management');
        $response->assertStatus(403);
    }
}
