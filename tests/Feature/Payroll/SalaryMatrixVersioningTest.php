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
            'step' => $step,
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

    public function test_ordinance_effective_mid_period_applies_csc_daily_rate_adjustment_for_calendar_days(): void
    {
        [$employee] = $this->makeAssignment(6, 1);

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716, 'ordinance_reference' => 'Second Tranche']);
        // August 2026: Aug 1 is a Saturday. Effective Aug 8 so the earlier
        // segment (Aug 1-7) spans 7 calendar days to exercise the CSC ÷22
        // adjustment formula.
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-08-08', 'amount' => 22000, 'ordinance_reference' => 'Third Tranche']);

        // Base = Third Tranche, paid in full for the whole period. Adjustment
        // = (old - new) / 31 calendar days in August * 7 calendar days in the
        // Aug 1-7 segment (weekends included, not gated on WorkSchedule).
        $expected = round(22000, 2) + round((19716 - 22000) / 31 * 7, 2);
        $this->assertEquals($expected, $this->computeBasicSalary($employee->id, '2026-08-01', '2026-08-31'));
        $this->assertEquals(21484.26, $this->computeBasicSalary($employee->id, '2026-08-01', '2026-08-31'));
    }

    public function test_mid_period_ordinance_computes_philhealth_and_pagibig_off_the_new_tranche_not_the_blended_basic_salary(): void
    {
        [$employee] = $this->makeAssignment(6, 1);

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716, 'ordinance_reference' => 'Second Tranche']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-08-08', 'amount' => 22000, 'ordinance_reference' => 'Third Tranche']);

        $manager = $this->createPayrollManager();
        $run = PayrollRun::create([
            'period' => '2026-08-01',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'status' => 'draft',
        ]);
        app(PayrollComputationService::class)->compute($run, $manager);
        $detail = $run->details()->where('employee_id', $employee->id)->firstOrFail();

        // basic_salary stays the blended figure (base + prorated correction).
        $this->assertEquals(21484.26, $detail->basic_salary);
        // GSIS still follows that same blended figure actually paid this period.
        $this->assertEquals(round(21484.26 * 0.09, 2), $detail->gsis_deduction);
        // PhilHealth/Pag-IBIG instead follow the new tranche's own full
        // monthly amount (22000) - not round(21484.26 * 0.025, 2) = 537.11 -
        // since those are monthly obligations that should track whichever
        // salary is officially in effect, not a mid-month blended figure.
        $this->assertEquals(round(22000 * 0.025, 2), $detail->philhealth_deduction);
        $this->assertEquals(100.00, $detail->pagibig_deduction);
    }

    public function test_ordinance_effective_on_a_weekend_still_produces_a_prorated_adjustment(): void
    {
        [$employee] = $this->makeAssignment(6, 1);

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716]);
        // Reproduces the exact reported real-world case: EO No. 64, s. 2024,
        // effective 2026-08-02 (a Sunday) mid an August 1-31 run. The only
        // differing day (Aug 1, a Saturday) is still one calendar day, so
        // it gets a small nonzero daily-rate adjustment even though it
        // isn't a working day.
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-08-02', 'amount' => 22000]);

        $expected = round(22000, 2) + round((19716 - 22000) / 31 * 1, 2);
        $this->assertEquals($expected, $this->computeBasicSalary($employee->id, '2026-08-01', '2026-08-31'));
        $this->assertEquals(21926.32, $this->computeBasicSalary($employee->id, '2026-08-01', '2026-08-31'));
    }

    public function test_ordinance_effective_exactly_on_period_end_adjusts_for_the_rest_of_the_month(): void
    {
        [$employee] = $this->makeAssignment(6, 1);

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716]);
        // Effective the very last day of the period (Aug 31) - base = this
        // tranche for the whole period, adjusted for the Aug 1-30 segment's
        // 30 calendar days at the prior rate.
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-08-31', 'amount' => 22000]);

        $expected = round(22000, 2) + round((19716 - 22000) / 31 * 30, 2);
        $this->assertEquals($expected, $this->computeBasicSalary($employee->id, '2026-08-01', '2026-08-31'));
        $this->assertEquals(19789.68, $this->computeBasicSalary($employee->id, '2026-08-01', '2026-08-31'));
    }

    public function test_ordinance_effective_the_day_after_period_end_does_not_apply(): void
    {
        [$employee] = $this->makeAssignment(6, 1);

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716]);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-09-01', 'amount' => 22000]);

        $this->assertEquals(19716.0, $this->computeBasicSalary($employee->id, '2026-08-01', '2026-08-31'));
    }

    public function test_two_mid_period_transitions_apply_two_csc_adjustments(): void
    {
        [$employee] = $this->makeAssignment(6, 1);

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 15000]);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-08-10', 'amount' => 18000]);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-08-20', 'amount' => 21000]);

        // Base = the 2026-08-20 tranche (21000), paid for the whole period.
        // Adjustment 1: Aug 1-9 segment, 9 calendar days.
        // Adjustment 2: Aug 10-19 segment, 10 calendar days.
        $expected = round(21000, 2)
            + round((15000 - 21000) / 31 * 9, 2)
            + round((18000 - 21000) / 31 * 10, 2);
        $this->assertEquals($expected, $this->computeBasicSalary($employee->id, '2026-08-01', '2026-08-31'));
        $this->assertEquals(18290.32, $this->computeBasicSalary($employee->id, '2026-08-01', '2026-08-31'));
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

    public function test_updating_a_tranche_moves_all_rows_to_the_new_date(): void
    {
        $manager = $this->createPayrollManager();
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716, 'ordinance_reference' => 'Third Tranche']);
        SalaryMatrix::create(['sg' => 6, 'step' => 2, 'effective_date' => '2026-01-01', 'amount' => 20000, 'ordinance_reference' => 'Third Tranche']);

        $response = $this->actingAs($manager)->put(route('payroll.salary-matrix.versions.update'), [
            'current_effective_date' => '2026-01-01',
            'effective_date' => '2026-03-15',
            'ordinance_reference' => 'Third Tranche (corrected date)',
        ]);

        $response->assertRedirect(route('payroll.salary-matrix.index', ['version' => '2026-03-15']));
        $response->assertSessionHas('status');

        $this->assertSame(0, SalaryMatrix::where('effective_date', '2026-01-01')->count());
        $moved = SalaryMatrix::where('effective_date', '2026-03-15')->orderBy('step')->get();
        $this->assertCount(2, $moved);
        $this->assertSame(2026, $moved->first()->year);
        $this->assertTrue($moved->every(fn ($row) => $row->ordinance_reference === 'Third Tranche (corrected date)'));

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $manager->id,
            'module' => 'payroll',
            'action' => 'salary_matrix_version_updated',
        ]);
    }

    public function test_updating_a_tranche_is_rejected_when_target_date_has_overlapping_cells(): void
    {
        $manager = $this->createPayrollManager();
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716]);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-07-15', 'amount' => 21000]);

        $response = $this->actingAs($manager)->put(route('payroll.salary-matrix.versions.update'), [
            'current_effective_date' => '2026-01-01',
            'effective_date' => '2026-07-15',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('salary_matrices', ['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01']);
        $this->assertDatabaseHas('salary_matrices', ['sg' => 6, 'step' => 1, 'effective_date' => '2026-07-15']);
        $this->assertEquals(19716.0, SalaryMatrix::where('sg', 6)->where('step', 1)->where('effective_date', '2026-01-01')->firstOrFail()->amount);
        $this->assertEquals(21000.0, SalaryMatrix::where('sg', 6)->where('step', 1)->where('effective_date', '2026-07-15')->firstOrFail()->amount);
    }

    public function test_updating_a_tranche_succeeds_when_target_date_has_no_overlapping_cells(): void
    {
        $manager = $this->createPayrollManager();
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716]);
        SalaryMatrix::create(['sg' => 10, 'step' => 3, 'effective_date' => '2026-07-15', 'amount' => 25000]);

        $response = $this->actingAs($manager)->put(route('payroll.salary-matrix.versions.update'), [
            'current_effective_date' => '2026-01-01',
            'effective_date' => '2026-07-15',
        ]);

        $response->assertSessionHas('status');
        $this->assertEquals(19716.0, SalaryMatrix::where('sg', 6)->where('step', 1)->where('effective_date', '2026-07-15')->firstOrFail()->amount);
        $this->assertEquals(25000.0, SalaryMatrix::where('sg', 10)->where('step', 3)->where('effective_date', '2026-07-15')->firstOrFail()->amount);
    }

    public function test_updating_ordinance_reference_only_keeps_the_same_date(): void
    {
        $manager = $this->createPayrollManager();
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716, 'ordinance_reference' => 'Draft label']);

        $this->actingAs($manager)->put(route('payroll.salary-matrix.versions.update'), [
            'current_effective_date' => '2026-01-01',
            'effective_date' => '2026-01-01',
            'ordinance_reference' => 'Final label',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('salary_matrices', ['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'ordinance_reference' => 'Final label']);
    }

    public function test_moving_a_tranches_date_changes_an_unlocked_runs_recompute(): void
    {
        [$employee] = $this->makeAssignment(6, 1);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-01-01', 'amount' => 19716]);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'effective_date' => '2026-07-01', 'amount' => 21000]);

        // Before the move: July period sees the second tranche's rate.
        $this->assertEquals(21000.0, $this->computeBasicSalary($employee->id, '2026-07-15', '2026-07-31'));

        $manager = $this->createPayrollManager();
        $this->actingAs($manager)->put(route('payroll.salary-matrix.versions.update'), [
            'current_effective_date' => '2026-07-01',
            'effective_date' => '2026-09-01',
        ])->assertSessionHas('status');

        // After the move: the same July period now falls back to the
        // earlier tranche, since the second one no longer applies until September.
        $this->assertEquals(19716.0, $this->computeBasicSalary($employee->id, '2026-07-15', '2026-07-31'));
        $this->assertEquals(21000.0, $this->computeBasicSalary($employee->id, '2026-09-15', '2026-09-30'));
    }
}
