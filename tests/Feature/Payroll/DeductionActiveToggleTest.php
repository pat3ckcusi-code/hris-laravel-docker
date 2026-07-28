<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDeduction;
use App\Models\Loan;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Active/Inactive toggle for deduction types. For Loan/Other, it lets a
 * Payroll Manager retire an old provider from new assignment without
 * deleting it (destroy() already blocks deletion once employees are
 * attached) - existing Loan/EmployeeDeduction rows keep computing regardless
 * of the parent type's active status. For a system mandatory row
 * (GSIS/PhilHealth/Pag-IBIG/BIR), there is no per-employee assignment row to
 * fall back on - deactivating stops that contribution from being withheld
 * from EVERY employee, immediately, on the next payroll run - see "Extend
 * the Active/Inactive toggle to mandatory deduction rows".
 */
class DeductionActiveToggleTest extends TestCase
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
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
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

    public function test_toggle_flips_active_status_and_flashes_message(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other']);
        // The DB-level default isn't reflected on the in-memory instance
        // returned by create() - fresh() confirms what actually landed.
        $this->assertTrue($deduction->fresh()->is_active);

        $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $deduction->id))
            ->assertSessionHas('status');
        $this->assertFalse($deduction->fresh()->is_active);

        $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $deduction->id))
            ->assertSessionHas('status');
        $this->assertTrue($deduction->fresh()->is_active);
    }

    public function test_toggling_a_mandatory_row_off_zeroes_its_computed_amount_and_drops_it_from_the_breakdown(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $gsis->id))
            ->assertSessionHas('status');
        $this->assertFalse($gsis->fresh()->is_active);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(0.0, $detail->gsis_deduction);

        $labels = collect($detail->deduction_breakdown)->pluck('label')->all();
        $this->assertNotContains('Life & Retirement', $labels);
    }

    public function test_toggling_bir_off_zeroes_withholding_tax(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();

        $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $bir->id));

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(0.0, $detail->bir_deduction);
    }

    public function test_toggling_gsis_off_then_back_on_restores_normal_computation(): void
    {
        ['admin' => $admin, 'employee' => $employee, 'run' => $run] = $this->seedPayrollScaffold();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $gsis->id));
        $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $gsis->id));
        $this->assertTrue($gsis->fresh()->is_active);

        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(round(18620.00 * 0.09, 2), $detail->gsis_deduction);
    }

    public function test_show_page_displays_a_real_toggle_button_for_a_mandatory_row(): void
    {
        $admin = $this->createPayrollManager();
        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();

        $this->actingAs($admin)->get(route('payroll.contributions.show', $gsis->id))
            ->assertSee('Deactivate');
    }

    public function test_loan_store_rejects_assignment_to_an_inactive_deduction(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'is_active' => false]);

        $this->actingAs($admin)->post(route('payroll.contributions.loans.store', $deduction->id), [
            'employee_id' => $employee->id,
            'balance' => 5000,
            'monthly_payment' => 500,
            'status' => 'active',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('loans', 0);
    }

    public function test_employee_deduction_store_rejects_assignment_to_an_inactive_deduction(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();
        $deduction = Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other', 'is_active' => false]);

        $this->actingAs($admin)->post(route('payroll.contributions.employee-deductions.store', $deduction->id), [
            'employee_ids' => [$employee->id],
            'amount' => 100,
            'recurring' => '1',
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('employee_deductions', 0);
    }

    public function test_existing_assignments_keep_computing_after_deactivation(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();

        $plantilla = Plantilla::create([
            'title' => 'Clerk III',
            'salary_grade' => 6,
            'step' => 1,
            'employment_type' => 'permanent',
        ]);
        EmployeeAssignment::create(['employee_id' => $employee->id, 'plantilla_id' => $plantilla->id, 'start_date' => '2026-01-01']);
        SalaryMatrix::create(['sg' => 6, 'step' => 1, 'year' => 2026, 'amount' => 18620.00]);

        $loanDeduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        Loan::create(['employee_id' => $employee->id, 'deduction_id' => $loanDeduction->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);

        $otherDeduction = Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other']);
        EmployeeDeduction::create(['employee_id' => $employee->id, 'deduction_id' => $otherDeduction->id, 'amount' => 100, 'recurring' => true]);

        // Deactivate both types after the employee is already assigned.
        $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $loanDeduction->id));
        $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $otherDeduction->id));

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        (new PayrollComputationService)->compute($run, $admin);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(500.00, $detail->loan_deduction);
        $this->assertEquals(100.00, $detail->other_deductions);
    }

    public function test_show_page_hides_assign_button_only_when_inactive(): void
    {
        $admin = $this->createPayrollManager();
        $active = Deduction::create(['type' => 'LBP', 'deduction_category' => 'loan']);
        $inactive = Deduction::create(['type' => 'Old Bank', 'deduction_category' => 'loan', 'is_active' => false]);

        $this->actingAs($admin)->get(route('payroll.contributions.show', $active->id))
            ->assertSee('Assign Loan');

        $this->actingAs($admin)->get(route('payroll.contributions.show', $inactive->id))
            ->assertDontSee('Assign Loan')
            ->assertSee('inactive');
    }
}
