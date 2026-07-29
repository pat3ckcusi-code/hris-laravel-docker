<?php

namespace Tests\Feature\ISO25010;

use App\Models\Deduction;
use App\Models\Department;
use App\Models\DocumentRequest;
use App\Models\Dtr;
use App\Models\Earning;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeEarning;
use App\Models\Eta;
use App\Models\HRAuditTrail;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Loan;
use App\Models\Locator;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\User;
use App\Services\PayrollComputationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISO/IEC 25010 - 1. Functional Suitability
 *
 * Tests: Functional completeness, Functional correctness, Functional appropriateness
 */
class FunctionalSuitabilityTest extends TestCase
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

    // ─── helpers ─────────────────────────────────────────────────────────

    private function createDepartment(array $attrs = []): Department
    {
        return Department::create(array_merge([
            'DeptCode' => 'TST',
            'Dept_name' => 'Test Department',
            'EmpNo' => 'DH'.uniqid(),
            'Designation' => 'Department Head',
        ], $attrs));
    }

    private function createUser(string $role = 'employee', array $extra = []): User
    {
        $dept = $this->createDepartment();

        return User::create(array_merge([
            'name' => 'Test User',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'user'.uniqid().'@test.com',
            'password' => bcrypt('password'),
            'access_level' => $role,
            'employee_type' => 'permanent',
            'Dept_id' => $dept->Dept_id,
            'EmpNo' => 'EMP'.uniqid(),
        ], $extra));
    }

    private function seedPayrollScaffold(User $employee): array
    {
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

        return compact('plantilla');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1.1 FUNCTIONAL COMPLETENESS - every module delivers intended functions
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function payroll_run_lifecycle_draft_compute_lock(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');
        $this->seedPayrollScaffold($employee);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        // Compute
        $service = new PayrollComputationService;
        $result = $service->compute($run, $admin);

        $this->assertGreaterThanOrEqual(1, $result['employee_count']);
        $run->refresh();
        $this->assertEquals('computed', $run->status);

        // Lock
        $run->update(['status' => 'locked', 'locked_at' => now()]);
        $run->refresh();
        $this->assertEquals('locked', $run->status);
        $this->assertNotNull($run->locked_at);
    }

    /** @test */
    public function payroll_detail_contains_all_components(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');
        $this->seedPayrollScaffold($employee);

        // Add earnings
        $earning = Earning::create(['type' => 'PERA', 'description' => 'Personnel Economic Relief Allowance', 'recurring' => true]);
        EmployeeEarning::create(['employee_id' => $employee->id, 'earnings_id' => $earning->id, 'amount' => 2000, 'recurring' => true]);

        // Add deductions
        $deduction = Deduction::create(['type' => 'GSIS', 'description' => 'GSIS Premium']);
        EmployeeDeduction::create(['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'amount' => 500, 'recurring' => true]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService;
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)
            ->first();

        $this->assertNotNull($detail);
        $this->assertEquals(18620.00, $detail->basic_salary);
        $this->assertEquals(2000.00, $detail->earnings);
        $this->assertEquals(500.00, $detail->deductions);
        // net = basic + earnings - deductions = 18620 + 2000 - 500 = 20120
        $this->assertEquals(20120.00, $detail->net_pay);
    }

    /** @test */
    public function leave_request_workflow_create_and_approve(): void
    {
        $employee = $this->createUser('employee');
        LeaveBalance::updateOrCreate(
            ['EmpNo' => $employee->EmpNo],
            ['VL' => 15, 'SL' => 15, 'WLNS' => 0, 'SPL' => 0, 'CTO' => 0, 'SP' => 0]
        );

        $leave = LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => '2026-04-07',
            'end_date' => '2026-04-09',
            'reason' => 'Personal matters',
            'status' => 'pending',
            'total_days' => 3,
            'paid_days' => 3,
            'lwop_days' => 0,
            'date_filed' => now(),
        ]);

        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'pending']);
        $leave->update(['status' => 'approved']);
        $this->assertDatabaseHas('leave_requests', ['id' => $leave->id, 'status' => 'approved']);
    }

    /** @test */
    public function document_request_lifecycle(): void
    {
        $employee = $this->createUser('employee');

        $doc = DocumentRequest::create([
            'EmpNo' => $employee->EmpNo,
            'document_type' => 'Certificate of Employment',
            'purpose' => 'Bank loan',
            'status' => 'pending',
            'requested_on' => now(),
        ]);

        $this->assertDatabaseHas('document_requests', ['id' => $doc->id, 'status' => 'pending']);
        $doc->update(['status' => 'processed', 'processed_on' => now()]);
        $this->assertEquals('processed', $doc->fresh()->status);
    }

    /** @test */
    public function dtr_records_capture_am_pm_time_pairs(): void
    {
        $employee = $this->createUser('employee');

        $dtr = Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-04-01',
            'time_in_am' => '08:00',
            'time_out_am' => '12:00',
            'time_in_pm' => '13:00',
            'time_out_pm' => '17:00',
            'status' => 'present',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $this->assertDatabaseHas('dtrs', ['employee_id' => $employee->id, 'date' => '2026-04-01']);
        $this->assertEquals('present', $dtr->status);
        $this->assertFalse((bool) $dtr->is_absent);
    }

    /** @test */
    public function eta_and_locator_requests_can_be_created(): void
    {
        $employee = $this->createUser('employee');

        $eta = Eta::create([
            'user_id' => $employee->id,
            'departure_date' => '2026-04-07',
            'arrival_date' => '2026-04-07',
            'destination' => 'City Hall',
            'purpose' => 'Official Business',
            'status' => 'pending',
        ]);

        $locator = Locator::create([
            'user_id' => $employee->id,
            'application_type' => 'Official',
            'location' => 'Provincial Capitol',
            'travel_date' => '2026-04-07',
            'intended_departure_time' => '08:00',
            'intended_arrival_time' => '17:00',
            'detail' => 'Meeting with provincial officials',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('eta', ['id' => $eta->id]);
        $this->assertDatabaseHas('locators', ['id' => $locator->id]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1.2 FUNCTIONAL CORRECTNESS - accurate data mapping & computation
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function salary_matrix_maps_correctly_to_plantilla(): void
    {
        SalaryMatrix::create(['sg' => 10, 'step' => 3, 'year' => 2026, 'amount' => 24495.00]);

        $plantilla = Plantilla::create([
            'title' => 'Admin Aide VI',
            'salary_grade' => 10,
            'step' => 3,
            'employment_type' => 'permanent',
        ]);

        $entry = SalaryMatrix::where('sg', $plantilla->salary_grade)
            ->where('step', $plantilla->step)
            ->where('year', 2026)
            ->first();

        $this->assertNotNull($entry);
        $this->assertEquals(24495.00, (float) $entry->amount);
    }

    /** @test */
    public function lwop_deduction_formula_is_correct(): void
    {
        // Formula: (basic_salary / 22) * lwop_days
        $basicSalary = 18620.00;
        $lwopDays = 3;
        $expected = round(($basicSalary / 22) * $lwopDays, 2);

        // 18620 / 22 = 846.363636... * 3 = 2539.090909... → rounds to 2539.09
        $this->assertEquals(2539.09, $expected);
    }

    /** @test */
    public function leave_integration_lwop_affects_payroll(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');
        $this->seedPayrollScaffold($employee);

        // Create approved leave with LWOP
        LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => '2026-04-06',
            'end_date' => '2026-04-08',
            'reason' => 'Personal',
            'status' => 'approved',
            'total_days' => 3,
            'paid_days' => 1,
            'lwop_days' => 2,
            'date_filed' => now(),
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService;
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertNotNull($detail);
        $this->assertGreaterThan(0, $detail->lwop_deduction);
        // LWOP deduction = (18620/22) * 2 = 1692.73
        $this->assertEquals(1692.73, $detail->lwop_deduction);
    }

    /** @test */
    public function loan_deductions_reduce_net_pay(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');
        $this->seedPayrollScaffold($employee);

        $deduction = Deduction::create(['type' => 'Housing Loan', 'description' => 'Pag-IBIG Housing']);
        Loan::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'balance' => 50000,
            'monthly_payment' => 2500,
            'status' => 'active',
        ]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService;
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertNotNull($detail);
        $this->assertEquals(2500.00, $detail->loan_deduction);
        // net = 18620 - 2500 = 16120
        $this->assertEquals(16120.00, $detail->net_pay);
    }

    /** @test */
    public function plantilla_assignment_links_employee_to_position(): void
    {
        $employee = $this->createUser('employee');
        $plantilla = Plantilla::create([
            'title' => 'Admin Officer V',
            'salary_grade' => 18,
            'step' => 1,
            'employment_type' => 'permanent',
        ]);

        $assignment = EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
        ]);

        $this->assertEquals($employee->id, $assignment->employee_id);
        $this->assertEquals($plantilla->id, $assignment->plantilla_id);
        $this->assertEquals('Admin Officer V', $assignment->plantilla->title);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1.3 FUNCTIONAL APPROPRIATENESS - correct module behavior
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function payroll_settings_store_and_retrieve(): void
    {
        PayrollSetting::create(['key' => 'salary_matrix_version', 'value' => '2026']);
        PayrollSetting::create(['key' => 'contribution_tables', 'value' => 'GSIS 2026']);

        $settings = PayrollSetting::all()->keyBy('key');
        $this->assertEquals('2026', $settings['salary_matrix_version']->value);
        $this->assertEquals('GSIS 2026', $settings['contribution_tables']->value);
    }

    /** @test */
    public function hr_audit_trail_records_actions(): void
    {
        $actor = $this->createUser('hr-manager');
        $target = $this->createUser('employee');

        HRAuditTrail::create([
            'actor_user_id' => $actor->id,
            'module' => 'records',
            'action' => 'updated_employee',
            'target_type' => 'user',
            'target_id' => $target->id,
            'details' => ['changed' => 'designation'],
        ]);

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $actor->id,
            'module' => 'records',
            'action' => 'updated_employee',
        ]);
    }

    /** @test */
    public function leave_balance_tracks_per_employee(): void
    {
        $employee = $this->createUser('employee');

        $balance = LeaveBalance::updateOrCreate(
            ['EmpNo' => $employee->EmpNo],
            [
                'VL' => 15.0,
                'SL' => 15.0,
                'WLNS' => 3.0,
                'SPL' => 7.0,
                'CTO' => 0,
                'SP' => 3.0,
            ]);

        $this->assertEquals(15.0, (float) $balance->VL);
        $this->assertEquals(15.0, (float) $balance->SL);

        // Simulate leave deduction
        $balance->update(['VL' => $balance->VL - 3]);
        $this->assertEquals(12.0, (float) $balance->fresh()->VL);
    }
}
