<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDeduction;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * "Other" category deductions can be switched into a Standing Rate mode
 * (auto-computed per employee_type, no per-employee row), mirroring the 4
 * system Mandatory contributions - see "Let 'Other' deduction types use a
 * standing per-type rate, like Mandatory".
 */
class OtherDeductionStandingRateTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeOtherDeduction(array $overrides = []): Deduction
    {
        return Deduction::create(array_merge([
            'type' => 'LIFE',
            'deduction_category' => 'other',
        ], $overrides));
    }

    private function makeRunForEmployee($employee, int $sg = 10, int $step = 1, float $amount = 25000.00): PayrollRun
    {
        $admin = $this->createPayrollManager(['employee_type' => 'Elected Officials']);

        $plantilla = Plantilla::create([
            'title' => 'Clerk',
            'salary_grade' => $sg,
            'step' => $step,
            'employment_type' => 'permanent',
        ]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => '2026-01-01',
            'step' => $step,
        ]);

        SalaryMatrix::create(['sg' => $sg, 'step' => $step, 'year' => 2026, 'amount' => $amount]);

        return PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
    }

    // ── Controller: updateMandatoryConfig ──────────────────────────────

    public function test_update_mandatory_config_accepts_a_flat_rate_for_an_other_deduction(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();

        $response = $this->actingAs($manager)->put(
            route('payroll.contributions.mandatory-config.update', $deduction->id),
            ['computation_type' => 'flat', 'amount' => 150]
        );

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
        $deduction->refresh();
        $this->assertEquals('flat', $deduction->computation_type);
        $this->assertEquals(150.0, $deduction->mandatory_config['amount']);
    }

    public function test_update_mandatory_config_individual_clears_computation_type(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction(['computation_type' => 'flat', 'mandatory_config' => ['amount' => 150]]);

        $response = $this->actingAs($manager)->put(
            route('payroll.contributions.mandatory-config.update', $deduction->id),
            ['computation_type' => 'individual']
        );

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
        $deduction->refresh();
        $this->assertNull($deduction->computation_type);
        $this->assertNull($deduction->mandatory_config);
    }

    public function test_update_mandatory_config_rejected_for_a_loan_category_deduction(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan']);

        $this->actingAs($manager)->put(
            route('payroll.contributions.mandatory-config.update', $deduction->id),
            ['computation_type' => 'flat', 'amount' => 100]
        )->assertStatus(422);
    }

    public function test_update_mandatory_config_rejects_individual_for_a_mandatory_row(): void
    {
        $manager = $this->createPayrollManager();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($manager)->put(
            route('payroll.contributions.mandatory-config.update', $gsis->id),
            ['computation_type' => 'individual']
        )->assertSessionHasErrors('computation_type');
    }

    // ── Controller: updateEligibility ──────────────────────────────────

    public function test_update_eligibility_rejected_for_an_other_deduction_still_in_individual_mode(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction();

        $this->actingAs($manager)->put(
            route('payroll.contributions.eligibility.update', $deduction->id),
            ['employee_types' => ['Permanent']]
        )->assertStatus(422);
    }

    public function test_update_eligibility_accepted_for_an_other_deduction_in_standing_rate_mode(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction(['computation_type' => 'flat', 'mandatory_config' => ['amount' => 150]]);

        $response = $this->actingAs($manager)->put(
            route('payroll.contributions.eligibility.update', $deduction->id),
            ['employee_types' => ['Permanent', 'Casual']]
        );

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
        $this->assertEquals(['Permanent', 'Casual'], $deduction->fresh()->eligible_employee_types);
    }

    // ── EmployeeDeductionController guards ──────────────────────────────

    public function test_store_rejected_once_deduction_is_in_standing_rate_mode(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction(['computation_type' => 'flat', 'mandatory_config' => ['amount' => 150]]);
        $employee = $this->createEmployee();

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.store', $deduction->id),
            ['employee_ids' => [$employee->id], 'amount' => 100, 'recurring' => '1']
        );

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('employee_deductions', 0);
    }

    public function test_bulk_assign_by_type_rejected_once_deduction_is_in_standing_rate_mode(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeOtherDeduction(['computation_type' => 'flat', 'mandatory_config' => ['amount' => 150]]);
        $this->createEmployee(['employee_type' => 'Permanent']);

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.employee-deductions.bulk-by-type', $deduction->id),
            ['employee_types' => ['Permanent'], 'amount' => 100]
        );

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('employee_deductions', 0);
    }

    // ── PayrollComputationService integration ──────────────────────────

    public function test_standing_rate_charges_every_active_employee_when_no_eligibility_restriction(): void
    {
        $this->makeOtherDeduction(['computation_type' => 'flat', 'mandatory_config' => ['amount' => 150]]);
        $employee = $this->createEmployee(['employee_type' => 'Permanent']);
        $run = $this->makeRunForEmployee($employee);

        (new PayrollComputationService)->compute($run, $run->creator);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(150.0, $detail->other_deductions);
        $this->assertTrue(collect($detail->deduction_breakdown)->contains(fn ($item) => $item['label'] === 'LIFE' && (float) $item['amount'] === 150.0));
    }

    public function test_standing_rate_percentage_still_rounds_unlike_mandatory_deductions(): void
    {
        $this->makeOtherDeduction(['computation_type' => 'percentage', 'mandatory_config' => ['rate' => 0.09]]);
        $employee = $this->createEmployee(['employee_type' => 'Permanent']);
        // Same 11111.17 basic salary and 0.09 rate used by
        // MandatoryDeductionConfigTest::test_gsis_percentage_truncates_instead_of_rounding_up -
        // GSIS truncates that to 1000.00, but this shared computeMandatoryAmount()
        // call site (computeOtherDeductions()) never passes truncate:true,
        // so Standing Rate "Other" deductions must keep rounding up to 1000.01.
        $run = $this->makeRunForEmployee($employee, 10, 1, 11111.17);

        (new PayrollComputationService)->compute($run, $run->creator);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(1000.01, $detail->other_deductions);
    }

    public function test_standing_rate_charges_zero_and_omits_breakdown_line_for_an_ineligible_type(): void
    {
        $this->makeOtherDeduction([
            'computation_type' => 'flat',
            'mandatory_config' => ['amount' => 150],
            'eligible_employee_types' => ['Permanent'],
        ]);
        $employee = $this->createEmployee(['employee_type' => 'Casual']);
        $run = $this->makeRunForEmployee($employee);

        (new PayrollComputationService)->compute($run, $run->creator);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(0.0, $detail->other_deductions);
        $this->assertFalse(collect($detail->deduction_breakdown)->contains(fn ($item) => $item['label'] === 'LIFE'));
    }

    public function test_switching_to_standing_rate_makes_an_existing_individual_assignment_dormant(): void
    {
        $deduction = $this->makeOtherDeduction();
        $employee = $this->createEmployee(['employee_type' => 'Permanent']);

        EmployeeDeduction::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'amount' => 50,
            'recurring' => true,
        ]);

        $run = $this->makeRunForEmployee($employee);

        (new PayrollComputationService)->compute($run, $run->creator);
        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(50.0, $detail->other_deductions);

        $deduction->update(['computation_type' => 'flat', 'mandatory_config' => ['amount' => 150]]);

        (new PayrollComputationService)->compute($run, $run->creator);
        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(150.0, $detail->other_deductions);

        // The old row is untouched in the database, just no longer read.
        $this->assertDatabaseHas('employee_deductions', ['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'amount' => 50]);
    }

    public function test_switching_back_to_individual_resumes_the_old_custom_amount(): void
    {
        $deduction = $this->makeOtherDeduction(['computation_type' => 'flat', 'mandatory_config' => ['amount' => 150]]);
        $employee = $this->createEmployee(['employee_type' => 'Permanent']);

        EmployeeDeduction::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'amount' => 50,
            'recurring' => true,
        ]);

        $run = $this->makeRunForEmployee($employee);

        (new PayrollComputationService)->compute($run, $run->creator);
        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(150.0, $detail->other_deductions);

        $deduction->update(['computation_type' => null, 'mandatory_config' => null]);

        (new PayrollComputationService)->compute($run, $run->creator);
        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(50.0, $detail->other_deductions);
    }
}
