<?php

namespace Tests\Feature\Payroll;

use App\Exports\WithholdingTaxImportTemplate;
use App\Models\Deduction;
use App\Models\EmployeeAssignment;
use App\Models\PayrollDetail;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\SalaryMatrix;
use App\Models\WithholdingTax;
use App\Services\PayrollComputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Withholding tax is no longer bracket-computed - Accounting computes it
 * themselves and the Payroll Manager uploads it monthly, per employee, for a
 * whole year at a time. See "Replace computed BIR withholding tax with an
 * Accounting-uploaded monthly table".
 */
class WithholdingTaxTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    /**
     * Mirrors the real template's layout: a title row, a 14-column header
     * row (Employee Agency Number, Name, Jan..Dec), then data.
     */
    private function makeWithholdingTaxFile(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Withholding Tax: 2026'],
            ['Employee Agency Number', 'Name', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            ...$rows,
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'wtax').'.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);
        $file = UploadedFile::fake()->createWithContent('wtax.xlsx', file_get_contents($tmpPath));
        unlink($tmpPath);

        return $file;
    }

    private function makeRunForEmployee($employee, string $periodStart, string $periodEnd, int $sg = 10, int $step = 1, float $amount = 25000.00): PayrollRun
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

        SalaryMatrix::firstOrCreate(['sg' => $sg, 'step' => $step, 'year' => 2026], ['amount' => $amount, 'effective_date' => '2026-01-01']);

        return PayrollRun::create([
            'period' => "{$periodStart} to {$periodEnd}",
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
    }

    // ── grid, embedded on the BIR Deduction's own show page ────────────

    public function test_bir_show_page_renders_grid_with_month_columns_and_year_selector(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();
        $employee = $this->createEmployee(['name' => 'Ana Cruz']);
        WithholdingTax::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 4, 'amount' => 500]);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.show', ['contribution' => $bir->id, 'year' => 2026]));

        $response->assertOk();
        $response->assertSee('<th>Jan</th>', false);
        $response->assertSee('<th>Dec</th>', false);
        $response->assertSee('Ana Cruz');
        $response->assertSee('₱500.00');
    }

    public function test_empty_cell_is_clickable_to_add_a_new_entry(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();
        $employee = $this->createEmployee(['name' => 'Ana Cruz']);
        // No WithholdingTax row created - every month cell starts empty.

        $response = $this->actingAs($admin)->get(route('payroll.contributions.show', ['contribution' => $bir->id, 'year' => 2026]));

        $response->assertOk();
        $response->assertSee("openAddWithholdingTax({$employee->id}, 'Ana Cruz', 1, 2026)", false);
    }

    public function test_bir_show_page_search_filters_by_name(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();
        $this->createEmployee(['name' => 'Ana Cruz']);
        $this->createEmployee(['name' => 'Ben Reyes']);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.show', ['contribution' => $bir->id, 'search' => 'Cruz']));

        $response->assertOk();
        $response->assertSee('Ana Cruz');
        $response->assertDontSee('Ben Reyes');
    }

    public function test_bir_show_page_filters_by_employee_type(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();
        $this->createEmployee(['name' => 'Ana Cruz', 'employee_type' => 'Permanent']);
        $this->createEmployee(['name' => 'Ben Reyes', 'employee_type' => 'Casual']);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.show', ['contribution' => $bir->id, 'type' => 'Permanent']));

        $response->assertOk();
        $response->assertSee('Ana Cruz');
        $response->assertDontSee('Ben Reyes');
    }

    public function test_bir_show_page_rejects_an_invalid_employee_type_filter(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();

        $this->actingAs($admin)->get(route('payroll.contributions.show', ['contribution' => $bir->id, 'type' => 'Not A Real Type']))
            ->assertSessionHasErrors('type');
    }

    public function test_bir_show_page_paginates_the_employee_grid(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();
        $this->createBulkUsers('employee', 25);

        $response = $this->actingAs($admin)->get(route('payroll.contributions.show', ['contribution' => $bir->id, 'year' => 2026]));

        $response->assertOk();
        $response->assertSee('hris-pagination-wrapper', false);

        $secondPage = $this->actingAs($admin)->get(route('payroll.contributions.show', ['contribution' => $bir->id, 'year' => 2026, 'wt_page' => 2]));
        $secondPage->assertOk();
    }

    // ── store() ─────────────────────────────────────────────────────────

    public function test_store_creates_a_new_entry_from_an_empty_cell(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();
        $employee = $this->createEmployee();

        $response = $this->actingAs($admin)->post(route('payroll.withholding-tax.store'), [
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 5,
            'amount' => 300,
        ]);

        $response->assertRedirect(route('payroll.contributions.show', ['contribution' => $bir->id, 'year' => 2026]));
        $this->assertDatabaseHas('withholding_taxes', ['employee_id' => $employee->id, 'year' => 2026, 'month' => 5, 'amount' => 300]);
    }

    public function test_store_updates_in_place_instead_of_duplicating_on_a_repeat_submit(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee();

        $submit = fn ($amount) => $this->actingAs($admin)->post(route('payroll.withholding-tax.store'), [
            'employee_id' => $employee->id,
            'year' => 2026,
            'month' => 5,
            'amount' => $amount,
        ]);

        $submit(300);
        $submit(350);

        $this->assertEquals(1, WithholdingTax::where('employee_id', $employee->id)->where('year', 2026)->where('month', 5)->count());
        $this->assertDatabaseHas('withholding_taxes', ['employee_id' => $employee->id, 'year' => 2026, 'month' => 5, 'amount' => 350]);
    }

    // ── update() ────────────────────────────────────────────────────────

    public function test_update_changes_an_existing_entrys_amount(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();
        $employee = $this->createEmployee();
        $entry = WithholdingTax::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 4, 'amount' => 500]);

        $response = $this->actingAs($admin)->put(route('payroll.withholding-tax.update', $entry->id), ['amount' => 750]);

        $response->assertRedirect(route('payroll.contributions.show', ['contribution' => $bir->id, 'year' => 2026]));
        $this->assertEquals(750.00, $entry->fresh()->amount);
    }

    // ── template download ──────────────────────────────────────────────

    public function test_template_download_is_prefilled_with_existing_values_for_the_year(): void
    {
        Excel::fake();

        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600010', 'name' => 'Nena Villar']);
        WithholdingTax::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 1, 'amount' => 500]);

        $response = $this->actingAs($admin)->get(route('payroll.withholding-tax.template', ['year' => 2026]));

        $response->assertOk();
        Excel::assertDownloaded('withholding_tax_2026.xlsx', function (WithholdingTaxImportTemplate $export) {
            $rows = $export->array();

            $titleMatches = str_contains($rows[0][0], '2026');
            $headerMatches = $rows[1] === ['Employee Agency Number', 'Name', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $hasEmployee = collect($rows)->contains(fn ($row) => ($row[0] ?? null) === '2600010' && ($row[1] ?? null) === 'Nena Villar' && ($row[2] ?? null) === 500.0);

            return $titleMatches && $headerMatches && $hasEmployee;
        });
    }

    // ── upload ──────────────────────────────────────────────────────────

    public function test_upload_creates_entries_for_every_filled_month(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600011', 'name' => 'Juan Dela Cruz']);

        $row = ['2600011', 'Juan Dela Cruz', '100', '200', '', '', '', '', '', '', '', '', '', '500'];
        $file = $this->makeWithholdingTaxFile([$row]);

        $response = $this->actingAs($admin)->post(route('payroll.withholding-tax.upload'), [
            'year' => 2026,
            'withholding_tax_file' => $file,
        ]);

        $response->assertSessionHas('status', fn ($m) => str_contains($m, '3 entr'));
        $this->assertDatabaseHas('withholding_taxes', ['employee_id' => $employee->id, 'year' => 2026, 'month' => 1, 'amount' => 100]);
        $this->assertDatabaseHas('withholding_taxes', ['employee_id' => $employee->id, 'year' => 2026, 'month' => 2, 'amount' => 200]);
        $this->assertDatabaseHas('withholding_taxes', ['employee_id' => $employee->id, 'year' => 2026, 'month' => 12, 'amount' => 500]);
        $this->assertDatabaseMissing('withholding_taxes', ['employee_id' => $employee->id, 'year' => 2026, 'month' => 3]);
    }

    public function test_reuploading_updates_existing_entries(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600012', 'name' => 'Maria Santos']);
        WithholdingTax::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 1, 'amount' => 100]);

        $row = ['2600012', 'Maria Santos', '150', '', '', '', '', '', '', '', '', '', '', ''];
        $this->actingAs($admin)->post(route('payroll.withholding-tax.upload'), [
            'year' => 2026,
            'withholding_tax_file' => $this->makeWithholdingTaxFile([$row]),
        ]);

        $this->assertEquals(1, WithholdingTax::where('employee_id', $employee->id)->where('year', 2026)->where('month', 1)->count());
        $this->assertDatabaseHas('withholding_taxes', ['employee_id' => $employee->id, 'year' => 2026, 'month' => 1, 'amount' => 150]);
    }

    public function test_unmatched_empno_is_reported_and_does_not_abort_other_rows(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600013', 'name' => 'Pedro Reyes']);

        $rows = [
            ['2600013', 'Pedro Reyes', '100', '', '', '', '', '', '', '', '', '', '', ''],
            ['9999999', 'Unknown Person', '100', '', '', '', '', '', '', '', '', '', '', ''],
        ];

        $response = $this->actingAs($admin)->post(route('payroll.withholding-tax.upload'), [
            'year' => 2026,
            'withholding_tax_file' => $this->makeWithholdingTaxFile($rows),
        ]);

        $response->assertSessionHas('status', fn ($m) => str_contains($m, 'Employee Agency Number not found: 9999999'));
        $this->assertDatabaseHas('withholding_taxes', ['employee_id' => $employee->id, 'year' => 2026, 'month' => 1, 'amount' => 100]);
    }

    public function test_upload_flags_a_name_mismatch_without_blocking_the_row(): void
    {
        $admin = $this->createPayrollManager();
        $employee = $this->createEmployee(['EmpNo' => '2600014', 'name' => 'Rosa Fernandez']);

        $row = ['2600014', 'Wrong Name', '100', '', '', '', '', '', '', '', '', '', '', ''];

        $response = $this->actingAs($admin)->post(route('payroll.withholding-tax.upload'), [
            'year' => 2026,
            'withholding_tax_file' => $this->makeWithholdingTaxFile([$row]),
        ]);

        $response->assertSessionHas('status', fn ($m) => str_contains($m, 'Name mismatch') && str_contains($m, '2600014'));
        $this->assertDatabaseHas('withholding_taxes', ['employee_id' => $employee->id, 'year' => 2026, 'month' => 1, 'amount' => 100]);
    }

    // ── PayrollComputationService integration ──────────────────────────

    public function test_uploaded_amount_is_applied_in_full_when_only_one_run_exists_that_month(): void
    {
        $employee = $this->createEmployee();
        WithholdingTax::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 4, 'amount' => 1200]);
        $run = $this->makeRunForEmployee($employee, '2026-04-01', '2026-04-30');

        (new PayrollComputationService)->compute($run, $run->creator);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(1200.00, $detail->bir_deduction);
        $this->assertTrue(collect($detail->deduction_breakdown)->contains(fn ($item) => $item['label'] === 'Withholding Tax' && (float) $item['amount'] === 1200.0));
    }

    public function test_uploaded_amount_splits_evenly_across_two_runs_in_the_same_month(): void
    {
        $employee = $this->createEmployee();
        WithholdingTax::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 4, 'amount' => 1200]);

        $firstHalf = $this->makeRunForEmployee($employee, '2026-04-01', '2026-04-15');
        PayrollRun::create([
            'period' => '2026-04-16 to 2026-04-30',
            'period_start' => '2026-04-16',
            'period_end' => '2026-04-30',
            'status' => 'draft',
            'created_by' => $firstHalf->created_by,
        ]);

        (new PayrollComputationService)->compute($firstHalf, $firstHalf->creator);

        $detail = PayrollDetail::where('payroll_run_id', $firstHalf->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(600.00, $detail->bir_deduction);
    }

    public function test_missing_withholding_tax_charges_zero_and_logs_an_exception(): void
    {
        $employee = $this->createEmployee();
        $run = $this->makeRunForEmployee($employee, '2026-04-01', '2026-04-30');

        (new PayrollComputationService)->compute($run, $run->creator);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(0.0, $detail->bir_deduction);

        $this->assertTrue(
            PayrollException::where('payroll_run_id', $run->id)->where('type', 'missing_withholding_tax')->exists()
        );
    }

    public function test_recompute_does_not_duplicate_auto_exceptions_but_preserves_manual_ones(): void
    {
        $employee = $this->createEmployee();
        $run = $this->makeRunForEmployee($employee, '2026-04-01', '2026-04-30');

        (new PayrollComputationService)->compute($run, $run->creator);

        PayrollException::create([
            'payroll_run_id' => $run->id,
            'type' => 'manual_note',
            'description' => 'Manually logged concern unrelated to the automatic checks.',
        ]);

        (new PayrollComputationService)->compute($run, $run->creator);

        $this->assertEquals(
            1,
            PayrollException::where('payroll_run_id', $run->id)->where('type', 'missing_withholding_tax')->count()
        );
        $this->assertTrue(
            PayrollException::where('payroll_run_id', $run->id)->where('type', 'manual_note')->exists()
        );
    }

    public function test_withholding_tax_line_ignores_bir_rows_is_active_and_eligible_employee_types(): void
    {
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();
        $bir->update(['is_active' => false, 'eligible_employee_types' => ['Casual']]);

        $employee = $this->createEmployee(['employee_type' => 'Permanent']);
        WithholdingTax::create(['employee_id' => $employee->id, 'year' => 2026, 'month' => 4, 'amount' => 900]);
        $run = $this->makeRunForEmployee($employee, '2026-04-01', '2026-04-30');

        (new PayrollComputationService)->compute($run, $run->creator);

        $detail = PayrollDetail::where('payroll_run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
        $this->assertEquals(900.00, $detail->bir_deduction);
        $this->assertTrue(collect($detail->deduction_breakdown)->contains(fn ($item) => $item['label'] === 'Withholding Tax'));
    }

    // ── BIR Deduction show page ─────────────────────────────────────────

    public function test_bir_show_page_embeds_the_table_instead_of_rate_configuration(): void
    {
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('payroll.contributions.show', $bir->id));

        $response->assertOk();
        $response->assertSee('Withholding Tax Table');
        $response->assertSee('Download Template');
        $response->assertSee('Upload Withholding Tax');
        $response->assertSee('<th>Jan</th>', false);
        $response->assertDontSee('Rate Configuration');
        $response->assertDontSee('Assign Employee Types');
    }

    public function test_bir_show_page_hides_the_profile_summary_card(): void
    {
        // The card's own data (computation_type/eligible_employee_types) is
        // vestigial for BIR now - showing it would misleadingly imply BIR
        // still uses a bracket table and is restricted to certain types.
        $admin = $this->createPayrollManager();
        $bir = Deduction::where('mandatory_key', 'bir')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('payroll.contributions.show', $bir->id));

        $response->assertOk();
        $response->assertDontSee('bracket table');

        $gsis = Deduction::where('mandatory_key', 'gsis')->firstOrFail();
        $this->actingAs($admin)->get(route('payroll.contributions.show', $gsis->id))
            ->assertSee('profile-card', false);
    }
}
