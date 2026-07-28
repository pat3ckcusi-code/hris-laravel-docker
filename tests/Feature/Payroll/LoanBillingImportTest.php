<?php

namespace Tests\Feature\Payroll;

use App\Exports\LoanBillingImportTemplate;
use App\Models\Deduction;
use App\Models\Loan;
use App\Models\LoanBillingHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Monthly loan billing upload, with real per-month history - lets a Payroll
 * Manager bulk-refresh a provider's loans from the billing file it sends
 * each month, instead of re-typing every employee by hand. See "Monthly
 * loan billing upload, with real per-month history" and "Billing template:
 * pre-fill Name/Department, print the billing month in the file".
 */
class LoanBillingImportTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    /**
     * Mirrors the real template's layout: a title row, a 5-column header
     * row (Employee Agency Number, Name, Department, Monthly Payment,
     * Balance), then data.
     */
    private function makeBillingFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Billing Month: April 2026 — Test Provider'],
            ['Employee Agency Number', 'Name', 'Department', 'Monthly Payment', 'Balance'],
            ...$rows,
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'billing').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $file = UploadedFile::fake()->createWithContent('billing.xlsx', file_get_contents($tmpPath));
        unlink($tmpPath);

        return $file;
    }

    public function test_upload_updates_an_existing_employees_loan_and_records_history(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600001', 'name' => 'Juan Dela Cruz']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        $loan = Loan::create(['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);

        $file = $this->makeBillingFile([['2600001', 'Juan Dela Cruz', 'Test Dept', '450', '4500']]);

        $response = $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $file,
        ]);

        $response->assertSessionHas('status');
        $loan->refresh();
        $this->assertEquals(4500.00, $loan->balance);
        $this->assertEquals(450.00, $loan->monthly_payment);

        $this->assertDatabaseHas('loan_billing_history', [
            'loan_id' => $loan->id,
            'billing_month' => '2026-04-01',
            'balance' => 4500.00,
            'monthly_payment' => 450.00,
            'uploaded_by' => $admin->id,
        ]);
    }

    public function test_upload_creates_a_new_loan_for_an_unassigned_empno(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600002', 'name' => 'Maria Santos']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $file = $this->makeBillingFile([['2600002', 'Maria Santos', 'Test Dept', '300', '3000']]);

        $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $file,
        ])->assertSessionHas('status', function ($message) {
            return str_contains($message, '1 new loan(s) added');
        });

        $this->assertDatabaseHas('loans', [
            'employee_id' => $employee->id,
            'deduction_id' => $deduction->id,
            'balance' => 3000.00,
            'monthly_payment' => 300.00,
            'status' => 'active',
        ]);
    }

    public function test_unmatched_empno_is_reported_and_does_not_abort_other_rows(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600003', 'name' => 'Pedro Reyes']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $file = $this->makeBillingFile([
            ['2600003', 'Pedro Reyes', 'Test Dept', '300', '3000'],
            ['9999999', 'Unknown Person', 'Test Dept', '100', '1000'],
        ]);

        $response = $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $file,
        ]);

        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'Employee Agency Number not found: 9999999');
        });

        $this->assertDatabaseHas('loans', ['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'balance' => 3000.00]);
    }

    public function test_zero_padded_and_non_padded_empno_both_match(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '02009', 'name' => 'Ana Lopez']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $file = $this->makeBillingFile([['2009', 'Ana Lopez', 'Test Dept', '200', '2000']]);

        $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $file,
        ]);

        $this->assertDatabaseHas('loans', ['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'balance' => 2000.00]);
    }

    public function test_reuploading_the_same_month_updates_the_history_row_instead_of_duplicating(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600004', 'name' => 'Carlos Gomez']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $this->makeBillingFile([['2600004', 'Carlos Gomez', 'Test Dept', '300', '3000']]),
        ]);

        $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $this->makeBillingFile([['2600004', 'Carlos Gomez', 'Test Dept', '280', '2700']]),
        ]);

        $loan = Loan::where('employee_id', $employee->id)->where('deduction_id', $deduction->id)->firstOrFail();
        $this->assertEquals(1, LoanBillingHistory::where('loan_id', $loan->id)->where('billing_month', '2026-04-01')->count());
        $this->assertDatabaseHas('loan_billing_history', ['loan_id' => $loan->id, 'billing_month' => '2026-04-01', 'balance' => 2700.00]);
    }

    public function test_zero_balance_row_marks_the_loan_paid(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600005', 'name' => 'Lito Ramos']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        $loan = Loan::create(['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'balance' => 500, 'monthly_payment' => 500, 'status' => 'active']);

        $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $this->makeBillingFile([['2600005', 'Lito Ramos', 'Test Dept', '0', '0']]),
        ]);

        $this->assertEquals('paid', $loan->fresh()->status);
    }

    public function test_upload_rejected_for_a_non_loan_deduction(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'Cellphone', 'deduction_category' => 'other']);

        $response = $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $this->makeBillingFile([]),
        ]);

        $response->assertSessionHas('error');
    }

    public function test_upload_rejected_for_an_inactive_deduction(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'is_active' => false]);

        $response = $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $this->makeBillingFile([]),
        ]);

        $response->assertSessionHas('error');
    }

    public function test_upload_flags_a_name_mismatch_without_blocking_the_row(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600006', 'name' => 'Rosa Fernandez']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        $loan = Loan::create(['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);

        $file = $this->makeBillingFile([['2600006', 'Wrong Name Here', 'Test Dept', '450', '4500']]);

        $response = $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $file,
        ]);

        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, 'Name mismatch') && str_contains($message, '2600006');
        });

        $this->assertEquals(4500.00, $loan->fresh()->balance);
    }

    public function test_billing_template_downloads_as_xlsx(): void
    {
        $admin = $this->createPayrollManager();
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan']);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.loans.billing-template', $deduction->id));

        $response->assertOk();
    }

    public function test_billing_template_is_prefilled_with_the_providers_active_roster_and_billing_month(): void
    {
        Excel::fake();

        $admin = $this->createPayrollManager();
        $activeEmployee = $this->createEmployee(['EmpNo' => '2600007', 'name' => 'Nena Villar']);
        $paidEmployee = $this->createEmployee(['EmpNo' => '2600008', 'name' => 'Paid Off Employee']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);
        Loan::create(['employee_id' => $activeEmployee->id, 'deduction_id' => $deduction->id, 'balance' => 5000, 'monthly_payment' => 500, 'status' => 'active']);
        Loan::create(['employee_id' => $paidEmployee->id, 'deduction_id' => $deduction->id, 'balance' => 0, 'monthly_payment' => 500, 'status' => 'paid']);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.loans.billing-template', ['contribution' => $deduction->id, 'month' => '2026-04']));

        $response->assertOk();
        Excel::assertDownloaded('cgcemco_billing_2026-04.xlsx', function (LoanBillingImportTemplate $export) {
            $rows = $export->array();

            $titleMatches = str_contains($rows[0][0], 'April 2026') && str_contains($rows[0][0], 'CGCEMCO');
            $headerMatches = $rows[1] === ['Employee Agency Number', 'Name', 'Department', 'Monthly Payment', 'Balance'];
            $noSampleRow = ! collect($rows)->contains(fn ($row) => ($row[0] ?? null) === 'SAMPLE');
            $hasActiveEmployee = collect($rows)->contains(fn ($row) => ($row[0] ?? null) === '2600007' && ($row[1] ?? null) === 'Nena Villar');
            $missingPaidEmployee = ! collect($rows)->contains(fn ($row) => ($row[0] ?? null) === '2600008');

            return $titleMatches && $headerMatches && $noSampleRow && $hasActiveEmployee && $missingPaidEmployee;
        });
    }

    public function test_leaving_the_sample_row_in_an_uploaded_file_is_harmless(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600009', 'name' => 'Dina Cruz']);
        $deduction = Deduction::create(['type' => 'CGCEMCO', 'deduction_category' => 'loan', 'provider' => 'CGCEMCO']);

        $file = $this->makeBillingFile([
            ['SAMPLE', 'Juan Dela Cruz', 'Office of the Mayor', '500.00', '5000.00'],
            ['2600009', 'Dina Cruz', 'Test Dept', '400', '4000'],
        ]);

        $response = $this->actingAs($admin)->post(route('payroll.contributions.loans.billing.upload', $deduction->id), [
            'billing_month' => '2026-04',
            'billing_file' => $file,
        ]);

        $response->assertSessionHas('status', function ($message) {
            return str_contains($message, '1 new loan(s) added')
                && ! str_contains($message, 'not found')
                && ! str_contains($message, 'SAMPLE');
        });

        $this->assertDatabaseHas('loans', ['employee_id' => $employee->id, 'deduction_id' => $deduction->id, 'balance' => 4000.00]);
    }
}
