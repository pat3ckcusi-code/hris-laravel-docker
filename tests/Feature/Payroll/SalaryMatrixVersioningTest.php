<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Salary matrix versioning
 *
 * Covers the scenario a plain (sg, step, year) key cannot represent: a new
 * ordinance/memorandum taking effect on an arbitrary date, including
 * mid-year, without disturbing rates that were already in force.
 */
class SalaryMatrixVersioningTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeAssignment(int $sg, int $step): array
    {
        $employee = $this->createEmployee();
        $plantilla = Plantilla::create([
            'title' => 'Test Position',
            'salary_grade' => $sg,
            'step' => $step,
            'employment_type' => 'permanent',
        ]);
        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2020-01-01',
        ]);

        return [$employee, $plantilla];
    }

    private function computeBasicSalary(int $employeeId, string $periodStart, string $periodEnd): float
    {
        $manager = $this->createPayrollManager();
        $run = PayrollRun::create([
            'period' => $periodStart,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'draft',
        ]);

        app(PayrollComputationService::class)->compute($run, $manager);

        return (float) $run->details()->where('employee_id', $employeeId)->value('basic_salary');
    }

    public function test_mid_year_ordinance_applies_only_from_its_effective_date(): void
    {
        [$employee] = $this->makeAssignment(6, 1);

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716, 'ordinance_reference' => 'Third Tranche']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-07-15', 'amount' => 21000, 'ordinance_reference' => 'City Ordinance 2026-45']);

        // Same calendar year, before the mid-year memo takes effect
        $this->assertEquals(19716.0, $this->computeBasicSalary($employee->id, '2026-03-01', '2026-03-31'));

        // On/after the effective date, the new rate applies immediately
        $this->assertEquals(21000.0, $this->computeBasicSalary($employee->id, '2026-07-15', '2026-07-31'));
        $this->assertEquals(21000.0, $this->computeBasicSalary($employee->id, '2026-12-01', '2026-12-31'));
    }

    public function test_two_versions_in_the_same_year_do_not_violate_uniqueness(): void
    {
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716]);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-07-15', 'amount' => 21000]);

        $this->assertSame(2, SalaryMatrix::where('sg', 6)->where('step', 1)->count());
    }

    public function test_year_only_creation_still_works_and_derives_effective_date(): void
    {
        // Mirrors the many pre-existing ISO25010 suite calls that only set 'year'.
        $entry = SalaryMatrix::create(['sg' => 11, 'step' => 1, 'year' => 2026, 'amount' => 27000]);

        $this->assertSame('2026-01-01', $entry->fresh()->effective_date->toDateString());
    }

    public function test_effective_date_only_creation_derives_year(): void
    {
        $entry = SalaryMatrix::create(['sg' => 11, 'step' => 1, 'effective_date' => '2027-07-15', 'amount' => 28000]);

        $this->assertSame(2027, $entry->fresh()->year);
    }

    public function test_bulk_tranche_publishes_many_rows_and_logs_audit_entry(): void
    {
        $manager = $this->createPayrollManager();

        $response = $this->actingAs($manager)->post(route('payroll.salary-matrix.versions.store'), [
            'effective_date' => '2027-01-01',
            'ordinance_reference' => 'EO No. 70, s. 2026',
            'amounts' => [
                1 => [1 => 15000, 2 => 15100],
                2 => [1 => 16000],
                33 => [1 => 460000, 2 => 470000, 3 => null, 4 => ''], // sparse - only 2 valid cells
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $this->assertSame(5, SalaryMatrix::where('effective_date', '2027-01-01')->count());
        $this->assertSame(
            'EO No. 70, s. 2026',
            SalaryMatrix::where('sg', 2)->where('step', 1)->where('effective_date', '2027-01-01')->value('ordinance_reference')
        );

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $manager->id,
            'module' => 'payroll',
            'action' => 'salary_matrix_version_created',
        ]);
    }

    public function test_index_page_lists_versions_and_shows_selected_ordinance(): void
    {
        $manager = $this->createPayrollManager();
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716, 'ordinance_reference' => 'Third Tranche']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2027-01-01', 'amount' => 21500, 'ordinance_reference' => 'Fourth Tranche']);

        $this->actingAs($manager)->get(route('payroll.salary-matrix.index'))
            ->assertStatus(200)
            ->assertSee('Fourth Tranche') // most recent version selected by default
            ->assertSee('21,500.00');

        $this->actingAs($manager)->get(route('payroll.salary-matrix.index', ['version' => '2026-01-01']))
            ->assertStatus(200)
            ->assertSee('Third Tranche')
            ->assertSee('19,716.00')
            ->assertDontSee('21,500.00');
    }

    public function test_run_before_any_matrix_data_falls_back_to_earliest_version(): void
    {
        [$employee] = $this->makeAssignment(6, 1);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716]);

        // A payroll period entirely before the earliest known rate should
        // still resolve to something rather than silently paying zero.
        $this->assertEquals(19716.0, $this->computeBasicSalary($employee->id, '2025-01-01', '2025-01-31'));
    }
}
