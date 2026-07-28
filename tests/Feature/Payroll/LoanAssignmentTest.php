<?php

namespace Tests\Feature\Payroll;

use App\Exports\LoanBillingImportTemplate;
use App\Models\Deduction;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class LoanAssignmentTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeLoanDeduction(): Deduction
    {
        return Deduction::create([
            'type' => 'CGCEMCO',
            'deduction_category' => 'loan',
            'provider' => 'CGCEMCO',
        ]);
    }

    public function test_store_creates_loan_for_employee(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeLoanDeduction();
        $employee = $this->createEmployee();

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.loans.store', $deduction->id),
            [
                'employee_id' => $employee->id,
                'balance' => 5000,
                'monthly_payment' => 500,
                'status' => 'active',
            ]
        );

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
        $this->assertDatabaseHas('loans', [
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'balance' => 5000,
            'monthly_payment' => 500,
            'status' => 'active',
        ]);
    }

    public function test_store_validates_required_fields(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeLoanDeduction();

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.loans.store', $deduction->id),
            []
        );

        $response->assertSessionHasErrors(['employee_id', 'balance', 'monthly_payment', 'status']);
        $this->assertDatabaseCount('loans', 0);
    }

    public function test_store_rejects_duplicate_loan_for_same_employee(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeLoanDeduction();
        $employee = $this->createEmployee();

        Loan::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'balance' => 1000,
            'monthly_payment' => 100,
            'status' => 'active',
        ]);

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.loans.store', $deduction->id),
            [
                'employee_id' => $employee->id,
                'balance' => 2000,
                'monthly_payment' => 200,
                'status' => 'active',
            ]
        );

        $response->assertSessionHas('error');
        $this->assertEquals(1, Loan::where('employee_id', $employee->id)->where('deduction_id', $deduction->id)->count());
    }

    public function test_update_changes_balance_and_monthly_payment(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeLoanDeduction();
        $employee = $this->createEmployee();

        $loan = Loan::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'balance' => 5000,
            'monthly_payment' => 500,
            'status' => 'active',
        ]);

        $response = $this->actingAs($manager)->put(
            route('payroll.contributions.loans.update', [$deduction->id, $loan->id]),
            ['balance' => 4500, 'monthly_payment' => 500, 'status' => 'active']
        );

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
        $this->assertDatabaseHas('loans', ['id' => $loan->id, 'balance' => 4500]);
    }

    public function test_destroy_removes_loan(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeLoanDeduction();
        $employee = $this->createEmployee();

        $loan = Loan::create([
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'balance' => 5000,
            'monthly_payment' => 500,
            'status' => 'active',
        ]);

        $response = $this->actingAs($manager)->delete(route('payroll.contributions.loans.destroy', [$deduction->id, $loan->id]));

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
        $this->assertDatabaseMissing('loans', ['id' => $loan->id]);
    }

    public function test_bulk_assign_creates_zero_balance_placeholder_loans(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeLoanDeduction();
        $employeeOne = $this->createEmployee();
        $employeeTwo = $this->createEmployee();

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.loans.bulk-assign', $deduction->id),
            ['employee_ids' => [$employeeOne->id, $employeeTwo->id]]
        );

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
        $this->assertDatabaseHas('loans', ['employee_id' => $employeeOne->id, 'deduction_id' => $deduction->id, 'balance' => 0, 'monthly_payment' => 0, 'status' => 'active']);
        $this->assertDatabaseHas('loans', ['employee_id' => $employeeTwo->id, 'deduction_id' => $deduction->id, 'balance' => 0, 'monthly_payment' => 0, 'status' => 'active']);
    }

    public function test_bulk_assign_skips_employees_already_on_the_roster(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = $this->makeLoanDeduction();
        $existingEmployee = $this->createEmployee();
        $newEmployee = $this->createEmployee();

        Loan::create(['employee_id' => $existingEmployee->id, 'deduction_id' => $deduction->id, 'balance' => 3000, 'monthly_payment' => 300, 'status' => 'active']);

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.loans.bulk-assign', $deduction->id),
            ['employee_ids' => [$existingEmployee->id, $newEmployee->id]]
        );

        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'Added 1 employee(s)') && str_contains($message, '1 already had a loan');
        });

        $this->assertEquals(1, Loan::where('employee_id', $existingEmployee->id)->where('deduction_id', $deduction->id)->count());
        $this->assertDatabaseHas('loans', ['employee_id' => $existingEmployee->id, 'deduction_id' => $deduction->id, 'balance' => 3000]);
        $this->assertDatabaseHas('loans', ['employee_id' => $newEmployee->id, 'deduction_id' => $deduction->id, 'balance' => 0]);
    }

    public function test_bulk_assign_rejected_for_an_inactive_provider(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'is_active' => false]);
        $employee = $this->createEmployee();

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.loans.bulk-assign', $deduction->id),
            ['employee_ids' => [$employee->id]]
        );

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('loans', 0);
    }

    public function test_bulk_assign_rejected_for_a_non_loan_deduction(): void
    {
        $manager = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other']);
        $employee = $this->createEmployee();

        $response = $this->actingAs($manager)->post(
            route('payroll.contributions.loans.bulk-assign', $deduction->id),
            ['employee_ids' => [$employee->id]]
        );

        $response->assertSessionHas('error');
        $this->assertDatabaseCount('loans', 0);
    }

    public function test_bulk_assigned_employee_appears_in_billing_template_with_blank_amounts(): void
    {
        Excel::fake();

        $manager = $this->createPayrollManager();
        $deduction = $this->makeLoanDeduction();
        $employee = $this->createEmployee(['EmpNo' => '2600020', 'name' => 'Elena Torres']);

        $this->actingAs($manager)->post(
            route('payroll.contributions.loans.bulk-assign', $deduction->id),
            ['employee_ids' => [$employee->id]]
        );

        $response = $this->actingAs($manager)->get(route('payroll.contributions.loans.billing-template', ['contribution' => $deduction->id, 'month' => '2026-05']));

        $response->assertOk();
        Excel::assertDownloaded('cgcemco_billing_2026-05.xlsx', function (LoanBillingImportTemplate $export) {
            $rows = $export->array();

            return collect($rows)->contains(
                fn ($row) => ($row[0] ?? null) === '2600020'
                    && ($row[1] ?? null) === 'Elena Torres'
                    && ($row[3] ?? null) === ''
                    && ($row[4] ?? null) === ''
            );
        });
    }
}
