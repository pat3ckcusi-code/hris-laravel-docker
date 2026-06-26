<?php

namespace Tests\Feature\ISO25010;

use App\Models\Department;
use App\Models\Dtr;
use App\Models\Earning;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeEarning;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\User;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISO/IEC 25010 - 2. Performance Efficiency
 *
 * Tests: Time behaviour, Resource utilisation, Capacity
 */
class PerformanceEfficiencyTest extends TestCase
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
            'name' => 'User ' . uniqid(),
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'perf' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'access_level' => $role,
            'employee_type' => 'permanent',
            'Dept_id' => $dept->Dept_id,
            'EmpNo' => 'E' . uniqid(),
        ], $extra));
    }

    /**
     * @test
     * Capacity: Payroll computation handles 100+ employees without error.
     */
    public function payroll_handles_100_employees(): void
    {
        $admin = $this->createUser('payroll-manager');
        $dept = Department::first();

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);
        $plantilla = Plantilla::create(['title' => 'Clerk III', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);

        // Bulk-create 100 employees with assignments
        $employees = [];
        for ($i = 0; $i < 100; $i++) {
            $emp = User::create([
                'name' => "Employee {$i}",
                'first_name' => 'Emp',
                'last_name' => "N{$i}",
                'email' => "emp{$i}_" . uniqid() . '@test.com',
                'password' => bcrypt('password'),
                'access_level' => 'employee',
                'employee_type' => 'permanent',
                'Dept_id' => $dept->Dept_id,
                'EmpNo' => "PERF{$i}" . uniqid(),
            ]);
            EmployeeAssignment::create([
                'employee_id' => $emp->id,
                'plantilla_id' => $plantilla->id,
                'start_date' => '2026-01-01',
            ]);
            $employees[] = $emp;
        }

        $run = PayrollRun::create([
            'period' => '2026-04 bulk',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $start = microtime(true);
        $service = new PayrollComputationService();
        $result = $service->compute($run, $admin);
        $elapsed = microtime(true) - $start;

        $this->assertEquals(100, $result['employee_count']);
        $this->assertCount(100, PayrollDetail::where('payroll_run_id', $run->id)->get());

        // Should complete within 30 seconds even on slow CI
        $this->assertLessThan(30, $elapsed, "Payroll computation for 100 employees took {$elapsed}s");
    }

    /**
     * @test
     * Time behaviour: Dashboard endpoints respond within 2 seconds.
     */
    public function dashboard_loads_within_acceptable_time(): void
    {
        $user = $this->createUser('employee');

        $start = microtime(true);
        $response = $this->actingAs($user)->get('/dashboard');
        $elapsed = microtime(true) - $start;

        $response->assertStatus(200);
        $this->assertLessThan(2, $elapsed, "Dashboard loaded in {$elapsed}s - too slow");
    }

    /**
     * @test
     * Time behaviour: HR Manager dashboard responds in time.
     */
    public function hr_manager_dashboard_responds_quickly(): void
    {
        $hrManager = $this->createUser('hr manager');

        $start = microtime(true);
        $response = $this->actingAs($hrManager)->get('/dashboard/hr-manager');
        $elapsed = microtime(true) - $start;

        $response->assertStatus(200);
        $this->assertLessThan(3, $elapsed, "HR Manager dashboard loaded in {$elapsed}s - too slow");
    }

    /**
     * @test
     * Time behaviour: Payroll dashboard endpoint performs within limits.
     */
    public function payroll_dashboard_responds_quickly(): void
    {
        $payrollManager = $this->createUser('payroll-manager');

        $start = microtime(true);
        $response = $this->actingAs($payrollManager)->get('/payroll-manager/dashboard');
        $elapsed = microtime(true) - $start;

        $response->assertStatus(200);
        $this->assertLessThan(3, $elapsed, "Payroll dashboard loaded in {$elapsed}s - too slow");
    }

    /**
     * @test
     * Resource utilisation: Payroll computation does not create duplicate details on recompute.
     */
    public function recompute_replaces_previous_details(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => Plantilla::first()->id,
            'start_date' => '2026-01-01',
        ]);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $run = PayrollRun::create([
            'period' => '2026-04 recompute',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();

        // Compute twice
        $service->compute($run, $admin);
        $run->update(['status' => 'draft']); // reset for re-computation
        $service->compute($run, $admin);

        // Should only have 1 detail per employee (old deleted, new created)
        $count = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)
            ->count();

        $this->assertEquals(1, $count, 'Recompute should not duplicate payroll details');
    }

    /**
     * @test
     * Capacity: Earnings with many line items compute correctly.
     */
    public function multiple_earnings_sum_correctly(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        Plantilla::create(['title' => 'Clerk', 'salary_grade' => 6, 'step' => 1, 'employment_type' => 'permanent']);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => Plantilla::first()->id,
            'start_date' => '2026-01-01',
        ]);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        // Many earnings
        $types = ['PERA', 'ACA', 'Clothing', 'Subsistence', 'Laundry', 'Hazard', 'LCA', 'Bonus', 'OT', 'Night Diff'];
        $total = 0;
        foreach ($types as $type) {
            $earning = Earning::create(['type' => $type, 'description' => $type . ' allowance', 'recurring' => true]);
            $amount = rand(500, 3000);
            EmployeeEarning::create(['employee_id' => $employee->id, 'earnings_id' => $earning->id, 'amount' => $amount, 'recurring' => true]);
            $total += $amount;
        }

        $run = PayrollRun::create([
            'period' => '2026-04 multi',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $service = new PayrollComputationService();
        $service->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $employee->id)->first();

        $this->assertEquals($total, (float) $detail->earnings);
    }
}
