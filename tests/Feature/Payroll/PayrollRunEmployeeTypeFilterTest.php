<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\PayrollDetail;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Services\PayrollComputationService;
use App\Support\HrisConstants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class PayrollRunEmployeeTypeFilterTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_store_persists_a_subset_of_employee_types(): void
    {
        $manager = $this->createPayrollManager();

        $response = $this->actingAs($manager)->post(route('payroll.runs.store'), [
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'employee_types' => ['Permanent', 'Co-Terminus', 'Elected Officials'],
        ]);

        $response->assertRedirect();
        $run = PayrollRun::latest('id')->first();
        $this->assertSame(['Permanent', 'Co-Terminus', 'Elected Officials'], $run->eligible_employee_types);
    }

    public function test_store_selecting_every_type_canonicalizes_to_null(): void
    {
        $manager = $this->createPayrollManager();

        $this->actingAs($manager)->post(route('payroll.runs.store'), [
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'employee_types' => HrisConstants::EMPLOYEE_TYPES,
        ]);

        $run = PayrollRun::latest('id')->first();
        $this->assertNull($run->eligible_employee_types);
    }

    public function test_store_omitting_employee_types_defaults_to_null(): void
    {
        $manager = $this->createPayrollManager();

        $this->actingAs($manager)->post(route('payroll.runs.store'), [
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
        ]);

        $run = PayrollRun::latest('id')->first();
        $this->assertNull($run->eligible_employee_types);
    }

    private function assignPlantilla($employee, string $step = '1'): void
    {
        $plantilla = Plantilla::create([
            'title' => 'Clerk '.uniqid(),
            'salary_grade' => 6,
            'step' => 1,
            'employment_type' => 'permanent',
        ]);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'step' => $step,
            'start_date' => '2026-04-01',
        ]);
    }

    public function test_compute_scoped_to_one_type_excludes_the_others(): void
    {
        $manager = $this->createPayrollManager();

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $permanent = $this->createEmployee(['employee_type' => 'Permanent']);
        $elected = $this->createEmployee(['employee_type' => 'Elected Officials']);
        $this->assignPlantilla($permanent);
        $this->assignPlantilla($elected);

        $run = PayrollRun::create([
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'eligible_employee_types' => ['Permanent'],
            'created_by' => $manager->id,
        ]);

        (new PayrollComputationService)->compute($run, $manager);

        $this->assertTrue(PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $permanent->id)->exists());
        $this->assertFalse(PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $elected->id)->exists());
    }

    public function test_compute_with_null_eligible_types_covers_every_type_as_before(): void
    {
        $manager = $this->createPayrollManager();

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $permanent = $this->createEmployee(['employee_type' => 'Permanent']);
        $elected = $this->createEmployee(['employee_type' => 'Elected Officials']);
        $this->assignPlantilla($permanent);
        $this->assignPlantilla($elected);

        $run = PayrollRun::create([
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $manager->id,
        ]);

        (new PayrollComputationService)->compute($run, $manager);

        $this->assertTrue(PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $permanent->id)->exists());
        $this->assertTrue(PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $elected->id)->exists());
    }

    public function test_compute_scoped_type_also_excludes_the_others_auto_exceptions(): void
    {
        $manager = $this->createPayrollManager();

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $permanent = $this->createEmployee(['employee_type' => 'Permanent']);
        $elected = $this->createEmployee(['employee_type' => 'Elected Officials']);
        $this->assignPlantilla($permanent);
        $this->assignPlantilla($elected);

        $run = PayrollRun::create([
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'eligible_employee_types' => ['Permanent'],
            'created_by' => $manager->id,
        ]);

        (new PayrollComputationService)->compute($run, $manager);

        $electedExceptions = PayrollException::where('payroll_run_id', $run->id)
            ->where('description', 'like', '%'.$elected->name.'%')
            ->count();

        $this->assertSame(0, $electedExceptions, 'An excluded employee type must not generate any auto-exceptions for this run.');
    }

    public function test_run_show_page_displays_all_when_unrestricted_and_selected_types_when_restricted(): void
    {
        $manager = $this->createPayrollManager();

        $unrestricted = PayrollRun::create([
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $manager->id,
        ]);
        $restricted = PayrollRun::create([
            'period' => 'April 16-30, 2026',
            'period_start' => '2026-04-16',
            'period_end' => '2026-04-30',
            'status' => 'draft',
            'eligible_employee_types' => ['Permanent', 'Co-Terminus'],
            'created_by' => $manager->id,
        ]);

        $this->actingAs($manager)->get(route('payroll.runs.show', $unrestricted->id))
            ->assertSee('Employee Types')
            ->assertSee('<strong>All</strong>', false);
        $this->actingAs($manager)->get(route('payroll.runs.show', $restricted->id))
            ->assertSee('<strong>Permanent, Co-Terminus</strong>', false);
    }
}
