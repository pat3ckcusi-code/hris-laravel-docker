<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\EmployeeAssignment;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * The 4 mandatory-contribution rows (gsis/philhealth/pagibig/bir) are seeded
 * system Deduction catalog rows, edited via their own show page rather than a
 * separate Settings panel - see "Unify mandatory-deduction rates into the
 * Deductions page". Their computation_type (flat/percentage/bracket) is
 * itself editable independent of which government program the row
 * represents - see "Make mandatory-deduction computation type itself
 * configurable" - so any of the 4 can switch formula shape entirely.
 */
class MandatoryDeductionConfigTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function seedPayrollScaffold(): array
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();

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

        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620.00]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        return compact('admin', 'employee', 'run');
    }

    public function test_migration_seeds_the_four_mandatory_rows_with_default_config(): void
    {
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();
        $this->assertEquals('percentage', $gsis->computation_type);
        $this->assertEquals(0.09, $gsis->mandatory_config['rate']);

        $philhealth = Deduction::where('mandatory_key', 'philhealth')->firstOrFail();
        $this->assertEquals('percentage', $philhealth->computation_type);
        // Corrected from the pre-refactor 0.05 "combined rate, auto-halved
        // in code" default down to the true employee-side 0.025, now that
        // PayrollComputationService no longer hardcodes the 50/50 split.
        $this->assertEquals(0.025, $philhealth->mandatory_config['rate']);
        $this->assertEquals(400.00, $philhealth->mandatory_config['floor']);
        $this->assertEquals(3750.00, $philhealth->mandatory_config['ceiling']);

        $pagibig = Deduction::where('mandatory_key', 'pagibig')->firstOrFail();
        $this->assertEquals('flat', $pagibig->computation_type);
        $this->assertEquals(100.00, $pagibig->mandatory_config['amount']);

        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();
        $this->assertEquals('bracket', $bir->computation_type);
        $this->assertCount(6, $bir->mandatory_config['brackets']);
    }

    public function test_default_computed_amounts_are_unchanged_by_the_computation_type_refactor(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();

        $this->assertEquals(round(18620.00 * 0.09, 2), $detail->gsis_deduction);
        // Old formula: basic * 0.05 / 2 = 465.50. New formula: basic * 0.025 (post-migration-corrected default) = identical.
        $this->assertEquals(465.50, $detail->philhealth_deduction);
        $this->assertEquals(100.00, $detail->pagibig_deduction);
    }

    public function test_gsis_percentage_truncates_instead_of_rounding_up(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();

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
        // 11111.17 * 0.09 = 1000.0053 - the 3rd decimal is 5, so round()
        // would round up to 1000.01. Truncation must keep 1000.00.
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 11111.17]);

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(1000.00, $detail->gsis_deduction);
        $this->assertNotEquals(1000.01, $detail->gsis_deduction);
    }

    public function test_philhealth_ceiling_clamp_still_applies_after_truncation(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();

        $philhealth = Deduction::where('mandatory_key', 'philhealth')->firstOrFail();
        $philhealth->update(['mandatory_config' => ['rate' => 0.025, 'floor' => 400.00, 'ceiling' => 3750.00]]);

        // 18620.00 basic salary * 0.025 = 465.50, well under the 3750
        // ceiling - bump the underlying salary matrix so the raw percentage
        // clearly exceeds the ceiling either way, proving the clamp still
        // wins over a truncated (not rounded) base amount.
        SalaryMatrix::where('sg', 6)->where('step', 1)->update(['amount' => 200000.00]);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(3750.00, $detail->philhealth_deduction);
    }

    public function test_updating_gsis_rate_changes_next_payroll_computation(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->put(
            route('payroll.contributions.mandatory-config.update', $gsis->id),
            ['computation_type' => 'percentage', 'rate_percent' => 12]
        )->assertRedirect(route('payroll.contributions.show', $gsis->id));

        $this->assertEquals(0.12, $gsis->fresh()->mandatory_config['rate']);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(round(18620.00 * 0.12, 2), $detail->gsis_deduction);
    }

    public function test_updating_philhealth_floor_and_ceiling_still_clamps(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $philhealth = Deduction::where('mandatory_key', 'philhealth')->firstOrFail();

        // rate_percent is now the true employee-side rate directly - no
        // hidden /2 halving happens in code anymore, so 2.5 (not 5) reaches
        // the same effective 2.5% as before this refactor.
        $this->actingAs($admin)->put(
            route('payroll.contributions.mandatory-config.update', $philhealth->id),
            ['computation_type' => 'percentage', 'rate_percent' => 2.5, 'floor' => 500, 'ceiling' => 600]
        )->assertSessionHas('status');

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        // 18620 * 2.5% = 465.50, clamped up to the new floor of 500.
        $this->assertEquals(500.00, $detail->philhealth_deduction);
    }

    public function test_percentage_type_with_no_floor_or_ceiling_applies_no_clamp(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->put(
            route('payroll.contributions.mandatory-config.update', $gsis->id),
            ['computation_type' => 'percentage', 'rate_percent' => 50]
        )->assertSessionHas('status');

        $this->assertNull($gsis->fresh()->mandatory_config['floor']);
        $this->assertNull($gsis->fresh()->mandatory_config['ceiling']);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(round(18620.00 * 0.50, 2), $detail->gsis_deduction);
    }

    public function test_updating_mandatory_config_rejects_the_bir_row(): void
    {
        // BIR is no longer bracket-computed at all - Accounting computes
        // withholding tax and it's uploaded via the Withholding Tax Table
        // (see WithholdingTaxTest.php) instead of being configured here.
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();

        $this->actingAs($admin)->put(
            route('payroll.contributions.mandatory-config.update', $bir->id),
            ['computation_type' => 'bracket', 'brackets' => [
                ['min' => 0, 'max' => null, 'base' => 0, 'rate_percent' => 10],
            ]]
        )->assertStatus(422);
    }

    public function test_updating_eligibility_rejects_the_bir_row(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();

        $this->actingAs($admin)->put(
            route('payroll.contributions.eligibility.update', $bir->id),
            ['employee_types' => ['Permanent']]
        )->assertStatus(422);
    }

    public function test_switching_pagibig_from_flat_to_percentage_changes_computation(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $pagibig = Deduction::where('mandatory_key', 'pagibig')->firstOrFail();

        $this->actingAs($admin)->put(
            route('payroll.contributions.mandatory-config.update', $pagibig->id),
            ['computation_type' => 'percentage', 'rate_percent' => 2, 'ceiling' => 200]
        )->assertSessionHas('status');

        $pagibig->refresh();
        $this->assertEquals('percentage', $pagibig->computation_type);
        $this->assertEquals(0.02, $pagibig->mandatory_config['rate']);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        // 18620 * 2% = 372.40, under the 200 ceiling would clamp down - but
        // 372.40 > 200, so it clamps to the ceiling.
        $this->assertEquals(200.00, $detail->pagibig_deduction);
    }

    public function test_switching_gsis_from_percentage_to_flat_changes_computation(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->put(
            route('payroll.contributions.mandatory-config.update', $gsis->id),
            ['computation_type' => 'flat', 'amount' => 850]
        )->assertSessionHas('status');

        $gsis->refresh();
        $this->assertEquals('flat', $gsis->computation_type);
        $this->assertEquals(850.00, $gsis->mandatory_config['amount']);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(850.00, $detail->gsis_deduction);
    }

    public function test_mandatory_config_update_accepts_an_other_category_deduction(): void
    {
        // "Other" rows can now opt into a Standing Rate too (auto-computed
        // per employee_type, like Mandatory) - see
        // OtherDeductionStandingRateTest.php for the full behavior. Only
        // Loan-category/uncategorized rows remain rejected.
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other']);

        $this->actingAs($admin)->put(
            route('payroll.contributions.mandatory-config.update', $deduction->id),
            ['computation_type' => 'percentage', 'rate_percent' => 5]
        )->assertRedirect(route('payroll.contributions.show', $deduction->id));

        $this->assertEquals('percentage', $deduction->fresh()->computation_type);
    }

    public function test_mandatory_config_update_rejects_a_loan_category_deduction(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan']);

        $this->actingAs($admin)->put(
            route('payroll.contributions.mandatory-config.update', $deduction->id),
            ['computation_type' => 'percentage', 'rate_percent' => 5]
        )->assertStatus(422);
    }

    public function test_store_and_update_reject_mandatory_category(): void
    {
        $admin = $this->createPayrollManager();

        $this->actingAs($admin)->post(route('payroll.contributions.store'), [
            'type' => 'Custom Mandatory',
            'deduction_category' => 'mandatory',
        ])->assertSessionHasErrors('deduction_category');

        $other = Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other']);
        $this->actingAs($admin)->put(route('payroll.contributions.update', $other->id), [
            'type' => 'Cellphone',
            'deduction_category' => 'mandatory',
        ])->assertSessionHasErrors('deduction_category');
    }

    public function test_destroy_blocks_a_system_mandatory_row(): void
    {
        $admin = $this->createPayrollManager();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->delete(route('payroll.contributions.destroy', $gsis->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('deductions', ['id' => $gsis->id]);
    }

    public function test_renaming_a_mandatory_row_changes_the_payslip_breakdown_label(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->put(route('payroll.contributions.update', $gsis->id), [
            'type' => 'GSIS Premium',
        ])->assertRedirect(route('payroll.contributions.index'));

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $labels = collect($detail->deduction_breakdown)->pluck('label')->all();

        $this->assertContains('GSIS Premium', $labels);
        $this->assertNotContains('Life & Retirement', $labels);
    }
}
