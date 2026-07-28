<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use App\Models\Loan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * The dedicated Loans page - a master list across every provider/employee,
 * replacing the removed per-type "Loans" count column on the Deductions
 * catalog. See "Dedicated Loans page; remove the Loans count column from
 * Deductions".
 */
class LoansIndexTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_index_lists_loans_across_multiple_providers_and_employees(): void
    {
        $admin = $this->createPayrollManager();
        $employeeOne = $this->createEmployee(['name' => 'Juan Dela Cruz']);
        $employeeTwo = $this->createEmployee(['name' => 'Maria Santos']);

        $cgcemco = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        $lbp = Deduction::create(['type' => 'Salary Loan', 'deduction_category' => 'loan', 'provider' => 'LBP']);

        Loan::create(['employee_id' => $employeeOne->id, 'deduction_id' => $cgcemco->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);
        Loan::create(['employee_id' => $employeeTwo->id, 'deduction_id' => $lbp->id, 'balance' => 12000, 'monthly_payment' => 1200, 'status' => 'active']);

        $response = $this->actingAs($admin)->get(route('payroll.loans.index'));

        $response->assertOk();
        $response->assertSee('Juan Dela Cruz');
        $response->assertSee('Maria Santos');
        $response->assertSee('CGCEMCO');
        $response->assertSee('LBP');
    }

    public function test_search_matches_employee_name_and_provider(): void
    {
        $admin = $this->createPayrollManager();
        $employeeOne = $this->createEmployee(['name' => 'Juan Dela Cruz']);
        $employeeTwo = $this->createEmployee(['name' => 'Maria Santos']);

        $cgcemco = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        $lbp = Deduction::create(['type' => 'Salary Loan', 'deduction_category' => 'loan', 'provider' => 'LBP']);

        Loan::create(['employee_id' => $employeeOne->id, 'deduction_id' => $cgcemco->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);
        Loan::create(['employee_id' => $employeeTwo->id, 'deduction_id' => $lbp->id, 'balance' => 12000, 'monthly_payment' => 1200, 'status' => 'active']);

        $byName = $this->actingAs($admin)->get(route('payroll.loans.index', ['search' => 'Juan']));
        $byName->assertSee('Juan Dela Cruz')->assertDontSee('Maria Santos');

        $byProvider = $this->actingAs($admin)->get(route('payroll.loans.index', ['search' => 'LBP']));
        $byProvider->assertSee('Maria Santos')->assertDontSee('Juan Dela Cruz');
    }

    public function test_status_filter_narrows_results(): void
    {
        $admin = $this->createPayrollManager();
        $employeeOne = $this->createEmployee(['name' => 'Active Employee']);
        $employeeTwo = $this->createEmployee(['name' => 'Paid Employee']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        Loan::create(['employee_id' => $employeeOne->id, 'deduction_id' => $deduction->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);
        Loan::create(['employee_id' => $employeeTwo->id, 'deduction_id' => $deduction->id, 'balance' => 0, 'monthly_payment' => 500, 'status' => 'paid']);

        $response = $this->actingAs($admin)->get(route('payroll.loans.index', ['status' => 'paid']));

        $response->assertSee('Paid Employee')->assertDontSee('Active Employee');
    }

    public function test_provider_filter_narrows_results(): void
    {
        $admin = $this->createPayrollManager();
        $employeeOne = $this->createEmployee(['name' => 'Juan Dela Cruz']);
        $employeeTwo = $this->createEmployee(['name' => 'Maria Santos']);

        $cgcemco = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        $lbp = Deduction::create(['type' => 'LBP', 'deduction_category' => 'loan', 'provider' => 'LBP']);

        Loan::create(['employee_id' => $employeeOne->id, 'deduction_id' => $cgcemco->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);
        Loan::create(['employee_id' => $employeeTwo->id, 'deduction_id' => $lbp->id, 'balance' => 12000, 'monthly_payment' => 1200, 'status' => 'active']);

        $response = $this->actingAs($admin)->get(route('payroll.loans.index', ['provider' => $lbp->id]));

        $response->assertSee('Maria Santos')->assertDontSee('Juan Dela Cruz');
    }

    public function test_editing_from_the_loans_page_redirects_back_to_it(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        $loan = Loan::create(['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);

        $response = $this->actingAs($admin)->put(
            route('payroll.contributions.loans.update', [$deduction->id, $loan->id]),
            ['balance' => 4500, 'monthly_payment' => 500, 'status' => 'active', '_redirect' => route('payroll.loans.index')]
        );

        $response->assertRedirect(route('payroll.loans.index'));
    }

    public function test_editing_from_the_deduction_show_page_still_redirects_there_by_default(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        $loan = Loan::create(['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);

        $response = $this->actingAs($admin)->put(
            route('payroll.contributions.loans.update', [$deduction->id, $loan->id]),
            ['balance' => 4500, 'monthly_payment' => 500, 'status' => 'active']
        );

        $response->assertRedirect(route('payroll.contributions.show', $deduction->id));
    }

    public function test_deleting_from_the_loans_page_redirects_back_to_it(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        $loan = Loan::create(['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);

        $response = $this->actingAs($admin)->delete(
            route('payroll.contributions.loans.destroy', [$deduction->id, $loan->id]),
            ['_redirect' => route('payroll.loans.index')]
        );

        $response->assertRedirect(route('payroll.loans.index'));
        $this->assertDatabaseMissing('loans', ['id' => $loan->id]);
    }
}
