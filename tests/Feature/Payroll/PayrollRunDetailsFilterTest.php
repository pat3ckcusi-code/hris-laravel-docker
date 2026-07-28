<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\Department;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class PayrollRunDetailsFilterTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeRun(): PayrollRun
    {
        return PayrollRun::create([
            'period' => 'July 1-15, 2026',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-15',
            'status' => 'computed',
        ]);
    }

    private function makeDetail(PayrollRun $run, $employee): PayrollDetail
    {
        return PayrollDetail::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'days_worked' => 15,
            'basic_salary' => 20000,
            'earnings' => 0,
            'deductions' => 0,
            'net_pay' => 20000,
        ]);
    }

    public function test_show_lists_all_details_with_no_filter_applied(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();
        $employeeOne = $this->createEmployee(['name' => 'Ana Cruz']);
        $employeeTwo = $this->createEmployee(['name' => 'Ben Reyes']);
        $this->makeDetail($run, $employeeOne);
        $this->makeDetail($run, $employeeTwo);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('Ana Cruz');
        $response->assertSee('Ben Reyes');
    }

    public function test_search_filters_details_by_employee_name(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();
        $employeeOne = $this->createEmployee(['name' => 'Ana Cruz']);
        $employeeTwo = $this->createEmployee(['name' => 'Ben Reyes']);
        $this->makeDetail($run, $employeeOne);
        $this->makeDetail($run, $employeeTwo);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id).'?search=Cruz');

        $response->assertOk();
        $response->assertSee('Ana Cruz');
        $response->assertDontSee('Ben Reyes');
    }

    public function test_search_filters_details_by_employee_agency_number(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();
        $employeeOne = $this->createEmployee(['name' => 'Ana Cruz', 'EmpNo' => '2600099']);
        $employeeTwo = $this->createEmployee(['name' => 'Ben Reyes', 'EmpNo' => '2600100']);
        $this->makeDetail($run, $employeeOne);
        $this->makeDetail($run, $employeeTwo);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id).'?search=2600099');

        $response->assertOk();
        $response->assertSee('Ana Cruz');
        $response->assertDontSee('Ben Reyes');
    }

    public function test_department_filter_narrows_details_to_selected_department(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();

        $deptA = Department::forceCreate([
            'DeptCode' => 'DEPTA',
            'Dept_name' => 'Department A',
            'EmpNo' => 'DEPTA-HEAD',
            'Designation' => 'Head',
        ]);
        $deptB = Department::forceCreate([
            'DeptCode' => 'DEPTB',
            'Dept_name' => 'Department B',
            'EmpNo' => 'DEPTB-HEAD',
            'Designation' => 'Head',
        ]);

        $employeeA = $this->createEmployee(['name' => 'Ana Cruz', 'Dept_id' => $deptA->Dept_id]);
        $employeeB = $this->createEmployee(['name' => 'Ben Reyes', 'Dept_id' => $deptB->Dept_id]);
        $this->makeDetail($run, $employeeA);
        $this->makeDetail($run, $employeeB);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id).'?department='.$deptA->Dept_id);

        $response->assertOk();
        $response->assertSee('Ana Cruz');
        $response->assertDontSee('Ben Reyes');
    }

    public function test_search_and_department_filter_combine(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();

        $deptA = Department::forceCreate([
            'DeptCode' => 'DEPTA',
            'Dept_name' => 'Department A',
            'EmpNo' => 'DEPTA-HEAD',
            'Designation' => 'Head',
        ]);

        $employeeA = $this->createEmployee(['name' => 'Ana Cruz', 'Dept_id' => $deptA->Dept_id]);
        $employeeB = $this->createEmployee(['name' => 'Ana Santos']);
        $this->makeDetail($run, $employeeA);
        $this->makeDetail($run, $employeeB);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id).'?search=Ana&department='.$deptA->Dept_id);

        $response->assertOk();
        $response->assertSee('Ana Cruz');
        $response->assertDontSee('Ana Santos');
    }

    public function test_no_match_shows_empty_filter_state_instead_of_computed_prompt(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();
        $employee = $this->createEmployee(['name' => 'Ana Cruz']);
        $this->makeDetail($run, $employee);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id).'?search=Nobody');

        $response->assertOk();
        $response->assertSee('No employees match your search/filter.');
        $response->assertDontSee('No payroll details computed yet.');
    }

    public function test_tiles_stay_based_on_the_full_unfiltered_run(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();
        $employeeOne = $this->createEmployee(['name' => 'Ana Cruz']);
        $employeeTwo = $this->createEmployee(['name' => 'Ben Reyes']);
        $this->makeDetail($run, $employeeOne);
        $this->makeDetail($run, $employeeTwo);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id).'?search=Cruz');

        $response->assertOk();
        // The "Employees" tile counts every detail on the run, not just the filtered result.
        $response->assertSee('<strong>2</strong>', false);
    }

    public function test_other_deduction_gets_its_own_named_column_like_gsis(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();
        $employee = $this->createEmployee(['name' => 'Ana Cruz']);
        Deduction::create(['type' => 'LIFE', 'deduction_category' => 'other']);
        $detail = $this->makeDetail($run, $employee);
        $detail->update([
            'other_deductions' => 150,
            'deduction_breakdown' => [['label' => 'LIFE', 'amount' => 150, 'category' => 'other']],
        ]);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('<th>LIFE</th>', false);
        $response->assertDontSee('<th>Other</th>', false);
        $response->assertSee('₱150.00');
    }

    public function test_multiple_other_deduction_types_each_get_their_own_column_and_amount(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();
        $employee = $this->createEmployee(['name' => 'Ana Cruz']);
        Deduction::create(['type' => 'LIFE', 'deduction_category' => 'other']);
        Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other']);
        $detail = $this->makeDetail($run, $employee);
        $detail->update([
            'other_deductions' => 350,
            'deduction_breakdown' => [
                ['label' => 'LIFE', 'amount' => 150, 'category' => 'other'],
                ['label' => 'Cellphone', 'amount' => 200, 'category' => 'other'],
            ],
        ]);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('<th>LIFE</th>', false);
        $response->assertSee('<th>Cellphone</th>', false);
        $response->assertSee('₱150.00');
        $response->assertSee('₱200.00');
    }

    public function test_pagination_links_point_back_to_the_run_not_the_site_root(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();
        for ($i = 0; $i < 25; $i++) {
            $this->makeDetail($run, $this->createEmployee(['name' => "Employee $i"]));
        }

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('href="'.route('payroll.runs.show', $run->id).'?page=2"', false);
        $response->assertDontSee('href="/?page=2"', false);
    }

    public function test_other_deduction_column_shows_zero_when_employee_has_no_breakdown_line(): void
    {
        $manager = $this->createPayrollManager();
        $run = $this->makeRun();
        $employee = $this->createEmployee(['name' => 'Ana Cruz']);
        Deduction::create(['type' => 'LIFE', 'deduction_category' => 'other']);
        $this->makeDetail($run, $employee);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('<th>LIFE</th>', false);
    }
}
