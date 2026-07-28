<?php

namespace Tests\Feature\Payroll;

use App\Models\Deduction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Loan-category Deduction rows (providers) are managed exclusively from the
 * dedicated Loans page now, not the Deductions catalog. See "Separate Loans
 * out of the Deductions catalog".
 */
class LoanProviderManagementTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_deductions_index_does_not_list_a_loan_provider(): void
    {
        $admin = $this->createPayrollManager();
        Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.index'));

        $response->assertOk();
        $response->assertDontSee('CGCEMCO');
    }

    public function test_deductions_index_still_lists_mandatory_and_other_types(): void
    {
        $admin = $this->createPayrollManager();
        Deduction::create(['type' => 'Cellphone Allowance Deduction', 'deduction_category' => 'other']);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.index'));

        $response->assertOk();
        $response->assertSee('Cellphone Allowance Deduction');
        $response->assertSee('Life & Retirement');
    }

    public function test_loans_index_lists_loan_providers(): void
    {
        $admin = $this->createPayrollManager();
        Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $response = $this->actingAs($admin)->get(route('payroll.loans.index'));

        $response->assertOk();
        $response->assertSee('CGCEMCO');
        $response->assertSee('Loan Providers');
    }

    public function test_adding_a_provider_from_the_loans_page_creates_it_and_redirects_there(): void
    {
        $admin = $this->createPayrollManager();

        $response = $this->actingAs($admin)->post(route('payroll.contributions.store'), [
            'type' => 'DBP Salary Loan',
            'deduction_category' => 'loan',
            'provider' => 'DBP',
            '_redirect' => route('payroll.loans.index'),
        ]);

        $response->assertRedirect(route('payroll.loans.index'));
        $this->assertDatabaseHas('deductions', ['type' => 'DBP Salary Loan', 'deduction_category' => 'loan', 'provider' => 'DBP']);
    }

    public function test_editing_a_provider_from_the_loans_page_persists_and_redirects_there(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $response = $this->actingAs($admin)->put(route('payroll.contributions.update', $deduction->id), [
            'type' => 'CGCEMCO Renamed',
            'deduction_category' => 'loan',
            'provider' => 'CGCEMCO',
            '_redirect' => route('payroll.loans.index'),
        ]);

        $response->assertRedirect(route('payroll.loans.index'));
        $this->assertDatabaseHas('deductions', ['id' => $deduction->id, 'type' => 'CGCEMCO Renamed']);
    }

    public function test_toggling_a_provider_from_the_loans_page_redirects_there(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $response = $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $deduction->id), [
            '_redirect' => route('payroll.loans.index'),
        ]);

        $response->assertRedirect(route('payroll.loans.index'));
        $this->assertFalse($deduction->fresh()->is_active);
    }

    public function test_deleting_an_unused_provider_from_the_loans_page_redirects_there(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $response = $this->actingAs($admin)->delete(route('payroll.contributions.destroy', $deduction->id), [
            '_redirect' => route('payroll.loans.index'),
        ]);

        $response->assertRedirect(route('payroll.loans.index'));
        $this->assertDatabaseMissing('deductions', ['id' => $deduction->id]);
    }

    public function test_default_redirects_without_redirect_param_still_go_to_deductions_index(): void
    {
        $admin = $this->createPayrollManager();

        $storeResponse = $this->actingAs($admin)->post(route('payroll.contributions.store'), [
            'type' => 'Uniform Deduction',
            'deduction_category' => 'other',
        ]);
        $storeResponse->assertRedirect(route('payroll.contributions.index'));

        $deduction = Deduction::where('type', 'Uniform Deduction')->firstOrFail();

        $updateResponse = $this->actingAs($admin)->put(route('payroll.contributions.update', $deduction->id), [
            'type' => 'Uniform Deduction',
            'deduction_category' => 'other',
        ]);
        $updateResponse->assertRedirect(route('payroll.contributions.index'));

        $toggleResponse = $this->actingAs($admin)->put(route('payroll.contributions.toggle-active', $deduction->id), []);
        $toggleResponse->assertRedirect(route('payroll.contributions.index'));

        $destroyResponse = $this->actingAs($admin)->delete(route('payroll.contributions.destroy', $deduction->id), []);
        $destroyResponse->assertRedirect(route('payroll.contributions.index'));
    }

    public function test_show_page_back_link_points_to_loans_for_a_loan_provider(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.show', $deduction->id));

        $response->assertOk();
        $response->assertSee(route('payroll.loans.index'), false);
    }

    public function test_show_page_back_link_points_to_deductions_for_a_non_loan_type(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'Cellphone Allowance Deduction', 'deduction_category' => 'other']);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.show', $deduction->id));

        $response->assertOk();
        $response->assertSee(route('payroll.contributions.index'), false);
    }
}
