<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\Plantilla;
use App\Models\User;
use App\Services\CscPlantillaImportService;
use App\Services\PayrollComputationService;
use Database\Seeders\SalaryMatrix2026Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * CSC Plantilla of Personnel import
 *
 * Covers: workbook parsing (anchor detection, section labels, vacancies,
 * terminator), name matching with suffixes, assignment idempotency and
 * replacement, users salary column sync, and 2026 payroll resolution.
 */
class CscPlantillaImportTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private string $fixturePath;

    private CscPlantillaImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CscPlantillaImportService::class);
        $this->fixturePath = storage_path('framework/testing/csc-plantilla-fixture.xls');
        $this->writeFixture();
    }

    protected function tearDown(): void
    {
        @unlink($this->fixturePath);

        parent::tearDown();
    }

    /**
     * Two sheets mimicking the CSC layout: variable header height, the
     * column-index anchor row, a section label, a vacant item, a suffixed
     * incumbent name, and the "(19) Total..." terminator.
     */
    private function writeFixture(): void
    {
        $spreadsheet = new Spreadsheet;

        $one = $spreadsheet->getActiveSheet();
        $one->setTitle('TEST 1');
        $one->setCellValue('B7', '  (1) TEST OFFICE OF THE MAYOR');
        $one->setCellValue('N7', ' (2) Bureau/ Agency/ Subsidiary:');
        foreach (['B' => '3', 'D' => '4', 'G' => '5', 'J' => '8', 'N' => '12'] as $col => $index) {
            $one->setCellValue($col.'15', $index);
        }
        // 44197 = 2021-01-01, 45658 = 2025-01-01 as Excel date serials
        $one->fromArray($this->itemRow('1', '901', 'Administrative Aide II', 2, 5, 'Dela Cruz', 'Juan', 'Santos', 'P', 44197, 45658), null, 'B16');
        $one->setCellValue('D17', 'RECORDS SECTION');
        $one->fromArray($this->itemRow('2', '902', 'Administrative Aide I', 1, 1, 'Vacant', '', '', 'P'), null, 'B18');
        $one->fromArray($this->itemRow('3', '903', 'Engineer II', 16, 2, 'Reyes III', 'Pedro, Jr.', 'Cruz', 'CoTP', '10/101972'), null, 'B19');
        $one->setCellValue('B21', '(19) Total Number of Position Items:');
        $one->setCellValue('B24', 'ghost'); // below the terminator: must not be parsed

        $two = $spreadsheet->createSheet();
        $two->setTitle('TEST 2');
        $two->setCellValue('B7', '  (1) TEST HEALTH DEPARTMENT');
        foreach (['B' => '3', 'D' => '4', 'G' => '5'] as $col => $index) {
            $two->setCellValue($col.'12', $index);
        }
        $two->fromArray($this->itemRow('1', '904', 'Nurse I', 15, 3, 'Nonexistent', 'Person', 'X', 'P'), null, 'B13');
        $two->setCellValue('B15', '(19) Total Number of Position Items:');

        (new XlsWriter($spreadsheet))->save($this->fixturePath);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * A data row shaped like the CSC layout, starting at column B:
     * B=old item, C=new item, D=title, G=SG, J=step, N/O/P=name,
     * R=original appointment, S=last promotion (Excel serials), T=status.
     */
    private function itemRow(string $old, string $new, string $title, int $sg, int $step, string $last, string $first, string $middle, string $status, mixed $origAppointment = null, mixed $lastPromotion = null): array
    {
        return [
            $old, $new, $title, null, null, $sg, null, null, $step,
            null, null, null, $last, $first, $middle, null, $origAppointment, $lastPromotion, $status,
        ];
    }

    private function createIncumbents(): array
    {
        return [
            $this->createEmployee(['last_name' => 'Dela Cruz', 'first_name' => 'Juan']),
            $this->createEmployee(['last_name' => 'Reyes', 'first_name' => 'Pedro']),
        ];
    }

    public function test_import_parses_items_and_matches_incumbents(): void
    {
        [$juan, $pedro] = $this->createIncumbents();

        $report = $this->service->import($this->fixturePath);

        $this->assertSame(2, $report['sheets_processed']);
        $this->assertSame(4, $report['items_parsed']);
        $this->assertSame(1, $report['vacant_items']);
        $this->assertSame(4, $report['plantillas_created']);
        $this->assertSame(2, $report['matched']);
        $this->assertCount(1, $report['unmatched_incumbents']);
        $this->assertSame('Nonexistent, Person X', $report['unmatched_incumbents'][0]['name']);

        // Section labels and rows below the terminator are not items
        $this->assertSame(0, Plantilla::where('title', 'like', '%SECTION%')->count());
        $this->assertSame(0, Plantilla::where('title', 'like', '%ghost%')->count());

        $item = Plantilla::where('item_number', '901')->first();
        $this->assertNotNull($item);
        $this->assertSame('Administrative Aide II', $item->title);
        $this->assertSame('TEST OFFICE OF THE MAYOR', $item->department);
        $this->assertSame(2, $item->salary_grade);
        $this->assertSame(5, $item->step);
        $this->assertSame('permanent', $item->employment_type);

        // Suffixes on either side of the comparison still match
        $suffixed = Plantilla::where('item_number', '903')->first();
        $this->assertSame('co-terminus', $suffixed->employment_type);
        $this->assertTrue(
            EmployeeAssignment::where('employee_id', $pedro->id)
                ->where('plantilla_id', $suffixed->id)
                ->whereNull('end_date')
                ->exists()
        );

        // Vacant item exists but has no assignment
        $vacant = Plantilla::where('item_number', '902')->first();
        $this->assertNotNull($vacant);
        $this->assertSame(0, $vacant->assignments()->count());

        // Denormalized user columns synced from the assignment
        $this->assertSame(2, $juan->refresh()->salary_grade);
        $this->assertSame(5, $juan->salary_step);

        // Appointment dates parsed from Excel serials in columns R/S
        $this->assertSame('2021-01-01', $juan->date_of_original_appointment?->toDateString());
        $this->assertSame('2025-01-01', $juan->date_of_last_promotion?->toDateString());

        // Malformed date text is skipped (not guessed) and surfaces as a warning
        $this->assertNull($pedro->refresh()->date_of_original_appointment);
        $this->assertNotEmpty(array_filter(
            $report['warnings'],
            fn (string $w) => str_contains($w, 'not valid Excel dates')
        ));
    }

    public function test_import_is_idempotent(): void
    {
        [$juan] = $this->createIncumbents();

        $this->service->import($this->fixturePath);
        $report = $this->service->import($this->fixturePath);

        $this->assertSame(0, $report['plantillas_created']);
        $this->assertSame(4, $report['plantillas_unchanged']);
        $this->assertSame(0, $report['assignments_created']);
        $this->assertSame(2, $report['assignments_unchanged']);
        $this->assertSame(0, $report['stale_assignments_ended']);

        $this->assertSame(4, Plantilla::count());
        $this->assertSame(
            1,
            EmployeeAssignment::where('employee_id', $juan->id)->whereNull('end_date')->count()
        );
    }

    public function test_import_replaces_a_changed_assignment(): void
    {
        [$juan] = $this->createIncumbents();

        $oldPlantilla = Plantilla::create([
            'title' => 'Old Position',
            'item_number' => '999',
            'salary_grade' => 10,
            'step' => 1,
            'employment_type' => 'permanent',
        ]);
        EmployeeAssignment::create([
            'employee_id' => $juan->id,
            'plantilla_id' => $oldPlantilla->id,
            'start_date' => '2025-01-01',
        ]);

        $report = $this->service->import($this->fixturePath);

        $this->assertSame(1, $report['assignments_replaced']);

        $old = EmployeeAssignment::where('plantilla_id', $oldPlantilla->id)->first();
        $this->assertSame('2025-12-31', $old->end_date?->toDateString());

        $active = EmployeeAssignment::where('employee_id', $juan->id)->whereNull('end_date')->get();
        $this->assertCount(1, $active);
        $this->assertSame('901', $active->first()->plantilla->item_number);
    }

    public function test_dry_run_persists_nothing(): void
    {
        $this->createIncumbents();

        $report = $this->service->import($this->fixturePath, dryRun: true);

        $this->assertSame(2, $report['matched']);
        $this->assertSame(0, Plantilla::count());
        $this->assertSame(0, EmployeeAssignment::count());
    }

    public function test_payroll_resolves_2026_salary_from_imported_assignment(): void
    {
        [$juan] = $this->createIncumbents();
        $this->seed(SalaryMatrix2026Seeder::class);

        $this->service->import($this->fixturePath);

        $run = PayrollRun::create([
            'period' => 'January 2026',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'status' => 'draft',
        ]);

        app(PayrollComputationService::class)->compute($run, $this->createPayrollManager());

        $detail = PayrollDetail::where('payroll_run_id', $run->id)
            ->where('employee_id', $juan->id)
            ->first();

        // SG 2 Step 5 in the 2026 Third Tranche
        $this->assertNotNull($detail);
        $this->assertEqualsWithDelta(15986.00, (float) $detail->basic_salary, 0.01);
    }
}
