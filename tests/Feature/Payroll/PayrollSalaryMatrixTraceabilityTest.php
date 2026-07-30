<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class PayrollSalaryMatrixTraceabilityTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function assignPlantilla($employee, int $sg, string $step = '1'): void
    {
        $plantilla = Plantilla::create([
            'title' => 'Clerk '.uniqid(),
            'salary_grade' => $sg,
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

    public function test_compute_stores_the_resolved_salary_matrix_id(): void
    {
        $manager = $this->createPayrollManager();
        $matrix = SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620]);

        $employee = $this->createEmployee();
        $this->assignPlantilla($employee, 6);

        $run = PayrollRun::create([
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $manager->id,
        ]);

        (new PayrollComputationService)->compute($run, $manager);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame($matrix->id, $detail->salary_matrix_id);
    }

    public function test_two_employees_can_resolve_to_different_tranches(): void
    {
        $manager = $this->createPayrollManager();

        // SG 6 only ever had an old tranche; SG 7 has a newer one too.
        $oldMatrix = SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2025, 'effective_date' => '2025-01-01', 'amount' => 17000]);
        SalaryMatrix::create(['sg' => 7, 'step' => 1, 'year' => 2025, 'effective_date' => '2025-01-01', 'amount' => 19000]);
        $newMatrix = SalaryMatrix::create(['sg' => 7, 'step' => 1, 'year' => 2026, 'effective_date' => '2026-01-01', 'amount' => 19940]);

        $employeeSix = $this->createEmployee(['name' => 'SG Six']);
        $employeeSeven = $this->createEmployee(['name' => 'SG Seven']);
        $this->assignPlantilla($employeeSix, 6);
        $this->assignPlantilla($employeeSeven, 7);

        $run = PayrollRun::create([
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $manager->id,
        ]);

        (new PayrollComputationService)->compute($run, $manager);

        $detailSix = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employeeSix->id)->firstOrFail();
        $detailSeven = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employeeSeven->id)->firstOrFail();

        $this->assertSame($oldMatrix->id, $detailSix->salary_matrix_id);
        $this->assertSame($newMatrix->id, $detailSeven->salary_matrix_id);
        $this->assertNotSame($detailSix->salary_matrix_id, $detailSeven->salary_matrix_id);
    }

    public function test_run_show_page_renders_tranche_and_flags_not_yet_effective_fallback(): void
    {
        $manager = $this->createPayrollManager();

        // Only a future tranche exists - getBasicSalary() falls back to it
        // even though it isn't effective yet as of the run's period_start.
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2027, 'effective_date' => '2027-01-01', 'ordinance_reference' => 'Future Tranche', 'amount' => 20000]);

        $employee = $this->createEmployee();
        $this->assignPlantilla($employee, 6);

        $run = PayrollRun::create([
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $manager->id,
        ]);

        (new PayrollComputationService)->compute($run, $manager);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('2027-01-01');
        $response->assertSee('Future Tranche');
        $response->assertSee('not yet effective');
    }

    public function test_run_show_page_shows_no_warning_when_tranche_is_already_effective(): void
    {
        $manager = $this->createPayrollManager();
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'effective_date' => '2026-01-01', 'ordinance_reference' => 'Second Tranche', 'amount' => 18620]);

        $employee = $this->createEmployee();
        $this->assignPlantilla($employee, 6);

        $run = PayrollRun::create([
            'period' => 'April 1-15, 2026',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $manager->id,
        ]);

        (new PayrollComputationService)->compute($run, $manager);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('2026-01-01');
        $response->assertSee('Second Tranche');
        $response->assertDontSee('not yet effective');
    }

    public function test_run_show_page_shows_no_warning_for_a_tranche_effective_mid_period(): void
    {
        $manager = $this->createPayrollManager();
        // Effective 2 days into the run's period - resolved and used for
        // the whole run (period_end-based lookup), so this must NOT be
        // flagged as a "not yet effective" fallback even though it's after
        // period_start.
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'effective_date' => '2026-08-02', 'ordinance_reference' => 'Third Tranche', 'amount' => 22000]);

        $employee = $this->createEmployee();
        $this->assignPlantilla($employee, 6);

        $run = PayrollRun::create([
            'period' => 'August 1-31, 2026',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => 'draft',
            'created_by' => $manager->id,
        ]);

        (new PayrollComputationService)->compute($run, $manager);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('2026-08-02');
        $response->assertSee('Third Tranche');
        $response->assertDontSee('not yet effective');
    }

    public function test_run_show_page_renders_base_and_adjustment_of_a_csc_prorated_mid_period_change(): void
    {
        $manager = $this->createPayrollManager();
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'ordinance_reference' => 'Second Tranche', 'amount' => 19716]);
        // The earlier segment (Aug 1-7) spans 7 calendar days, giving a
        // clean, nonzero CSC ÷22 adjustment to render.
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-08-08', 'ordinance_reference' => 'Third Tranche', 'amount' => 22000]);

        $employee = $this->createEmployee();
        $this->assignPlantilla($employee, 6);

        $run = PayrollRun::create([
            'period' => 'August 1-31, 2026',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => 'draft',
            'created_by' => $manager->id,
        ]);

        (new PayrollComputationService)->compute($run, $manager);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertCount(2, $detail->basic_salary_breakdown);
        $this->assertTrue($detail->basic_salary_breakdown[0]['is_base']);
        $this->assertFalse($detail->basic_salary_breakdown[1]['is_base']);
        // Displayed as an additive split: Third Tranche's own share for its
        // 24 remaining days (Aug 8-31) plus Second Tranche's own share for
        // the 7 days (Aug 1-7) before it took effect - both positive, and
        // summing exactly to the total.
        $this->assertEquals(24, $detail->basic_salary_breakdown[0]['days']);
        $this->assertEquals(7, $detail->basic_salary_breakdown[1]['days']);
        $this->assertEquals(21484.26, $detail->basic_salary);

        $response = $this->actingAs($manager)->get(route('payroll.runs.show', $run->id));

        $response->assertOk();
        $response->assertSee('2026-08-01', false);
        $response->assertSee('2026-08-08', false);
        $response->assertSee('2026-08-31', false);
        $response->assertSee('Second Tranche');
        $response->assertSee('Third Tranche');
        $response->assertSee('Adjustment');
        $response->assertSee('₱17,032.26');
        $response->assertSee('₱4,452.00');
        $response->assertSee('₱21,484.26');
    }
}
