<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\EmployeeAssignment;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\User;
use App\Services\PayrollComputationService;
use App\Support\HrisConstants;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Employee Type eligibility for mandatory deduction rows - lets a Payroll
 * Manager exclude an employee type (e.g. Job Orders) from a mandatory
 * contribution entirely, since not every type is actually a member of that
 * government program. See "Employee Type eligibility for mandatory
 * deduction rows".
 */
class MandatoryEligibilityTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function seedAssignmentFor(User $employee): void
    {
        $plantilla = Plantilla::create([
            'title' => 'Clerk III',
            'salary_grade' => 6,
            'step' => 1,
            'employment_type' => 'permanent',
        ]);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::firstOrCreate(['sg' => 6, 'step' => 1, 'year' => 2026], ['amount' => 18620.00]);
    }

    public function test_default_no_restriction_applies_gsis_to_every_employee_type(): void
    {
        $admin = $this->createPayrollManager();
        $permanent = $this->createEmployee(['employee_type' => 'Permanent']);
        $jobOrder = $this->createEmployee(['employee_type' => 'Job Orders']);
        $this->seedAssignmentFor($permanent);
        $this->seedAssignmentFor($jobOrder);

        $run = PayrollRun::create([
            'period' => '2026-04 1st', 'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'status' => 'draft', 'created_by' => $admin->id,
        ]);
        (new PayrollComputationService)->compute($run, $admin);

        $permanentDetail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $permanent->id)->firstOrFail();
        $jobOrderDetail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $jobOrder->id)->firstOrFail();

        $this->assertEquals(round(18620.00 * 0.09, 2), $permanentDetail->gsis_deduction);
        $this->assertEquals(round(18620.00 * 0.09, 2), $jobOrderDetail->gsis_deduction);
    }

    public function test_restricting_gsis_excludes_job_orders_but_keeps_permanent_in_the_same_run(): void
    {
        $admin = $this->createPayrollManager();
        $permanent = $this->createEmployee(['employee_type' => 'Permanent']);
        $jobOrder = $this->createEmployee(['employee_type' => 'Job Orders']);
        $this->seedAssignmentFor($permanent);
        $this->seedAssignmentFor($jobOrder);

        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();
        $allExceptJobOrders = array_values(array_diff(HrisConstants::EMPLOYEE_TYPES, ['Job Orders']));

        $this->actingAs($admin)->put(route('payroll.contributions.eligibility.update', $gsis->id), [
            'employee_types' => $allExceptJobOrders,
        ])->assertSessionHas('status');

        $run = PayrollRun::create([
            'period' => '2026-04 1st', 'period_start' => '2026-04-01', 'period_end' => '2026-04-15',
            'status' => 'draft', 'created_by' => $admin->id,
        ]);
        (new PayrollComputationService)->compute($run, $admin);

        $permanentDetail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $permanent->id)->firstOrFail();
        $jobOrderDetail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $jobOrder->id)->firstOrFail();

        $this->assertEquals(round(18620.00 * 0.09, 2), $permanentDetail->gsis_deduction);
        $this->assertContains('Life & Retirement', collect($permanentDetail->deduction_breakdown)->pluck('label')->all());

        $this->assertEquals(0.0, $jobOrderDetail->gsis_deduction);
        $this->assertNotContains('Life & Retirement', collect($jobOrderDetail->deduction_breakdown)->pluck('label')->all());
    }

    public function test_selecting_every_employee_type_canonicalizes_to_null(): void
    {
        $admin = $this->createPayrollManager();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->put(route('payroll.contributions.eligibility.update', $gsis->id), [
            'employee_types' => HrisConstants::EMPLOYEE_TYPES,
        ])->assertSessionHas('status');

        $this->assertNull($gsis->fresh()->eligible_employee_types);
    }

    public function test_empty_selection_is_rejected(): void
    {
        $admin = $this->createPayrollManager();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->put(route('payroll.contributions.eligibility.update', $gsis->id), [
            'employee_types' => [],
        ])->assertSessionHasErrors('employee_types');

        $this->assertNull($gsis->fresh()->eligible_employee_types);
    }

    public function test_unknown_employee_type_is_rejected(): void
    {
        $admin = $this->createPayrollManager();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->put(route('payroll.contributions.eligibility.update', $gsis->id), [
            'employee_types' => ['Not A Real Type'],
        ])->assertSessionHasErrors('employee_types.0');
    }

    public function test_eligibility_update_rejects_a_non_mandatory_deduction(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other']);

        $this->actingAs($admin)->put(route('payroll.contributions.eligibility.update', $deduction->id), [
            'employee_types' => ['Permanent'],
        ])->assertStatus(422);
    }

    public function test_show_page_renders_assign_employee_types_button_and_current_restriction(): void
    {
        $admin = $this->createPayrollManager();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $unrestricted = $this->actingAs($admin)->get(route('payroll.contributions.show', $gsis->id));
        $unrestricted->assertSee('Assign Employee Types')->assertSee('All employee types');

        $gsis->update(['eligible_employee_types' => ['Permanent', 'Co-Terminus']]);

        $restricted = $this->actingAs($admin)->get(route('payroll.contributions.show', $gsis->id));
        $restricted->assertSee('Permanent')->assertSee('Co-Terminus');
    }
}
