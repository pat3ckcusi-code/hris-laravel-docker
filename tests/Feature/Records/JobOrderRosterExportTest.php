<?php

namespace Tests\Feature\Records;

use App\Models\Department;
use App\Models\JobOrderAppointment;
use App\Models\Setting;
use App\Services\JobOrderRosterExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class JobOrderRosterExportTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function createAppointment(array $overrides = []): JobOrderAppointment
    {
        $employee = $overrides['employee'] ?? $this->createEmployee(['employee_type' => 'Job Orders']);
        unset($overrides['employee']);

        return JobOrderAppointment::create(array_merge([
            'user_id' => $employee->id,
            'designation' => 'Field Worker/Office Staff',
            'office' => 'BAC',
            'funding_source' => 'CMO - Other General Services',
            'rate_per_day' => 400,
            'rate_note' => 'w/SH',
            'period_from' => now()->toDateString(),
            'period_until' => now()->addMonths(3)->toDateString(),
            'remarks' => 'RENEWAL',
        ], $overrides));
    }

    private function export(array $filters = []): Worksheet
    {
        $rm = $this->createRecordsManager();
        $response = $this->actingAs($rm)->get(route('dashboard.records-manager.job-order-roster.export', $filters));
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'job_order_roster_');
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getSheet(0);
        unlink($tmpPath);

        return $sheet;
    }

    public function test_roster_export_matches_the_official_job_order_document_layout(): void
    {
        $this->createAppointment();

        $sheet = $this->export();

        // Letterhead
        $this->assertSame('Republic of the Philippines', (string) $sheet->getCell('A1')->getValue());
        $this->assertSame('CITY OF CALAPAN', (string) $sheet->getCell('A3')->getValue());
        $this->assertSame('JOB ORDER', (string) $sheet->getCell('A5')->getValue());

        // Office group header
        $this->assertSame('OFFICE: BAC', (string) $sheet->getCell('A7')->getValue());

        // Two-row table header: RATE PER DAY spans D:E as one merged block,
        // PERIOD OF APPOINTMENT spans F:G on its top row only, with FROM/TO
        // as separate sub-headers on the row below.
        $mergedRanges = $sheet->getMergeCells();
        $this->assertContains('D9:E10', $mergedRanges);
        $this->assertContains('F9:G9', $mergedRanges);
        $this->assertSame('RATE PER DAY', (string) $sheet->getCell('D9')->getValue());
        $this->assertSame('PERIOD OF APPOINTMENT', (string) $sheet->getCell('F9')->getValue());
        $this->assertSame('FROM', (string) $sheet->getCell('F10')->getValue());
        $this->assertSame('TO', (string) $sheet->getCell('G10')->getValue());

        // Data row: item number 1, rate amount/note split across D/E (not
        // combined like the old flat export), office repeated in column I.
        $this->assertSame(1, $sheet->getCell('A11')->getValue());
        $this->assertEqualsWithDelta(400.0, (float) $sheet->getCell('D11')->getValue(), 0.001);
        $this->assertSame('w/SH', (string) $sheet->getCell('E11')->getValue());
        $this->assertSame('BAC', (string) $sheet->getCell('I11')->getValue());
        $this->assertSame('RENEWAL', (string) $sheet->getCell('J11')->getValue());

        // Certification paragraphs and 4-column signature block.
        $this->assertStringContainsString('automatically cease upon its expiration', (string) $sheet->getCell('A13')->getValue());
        $this->assertSame('PREPARED BY:', (string) $sheet->getCell('A17')->getValue());
        $this->assertSame('APPROVED BY:', (string) $sheet->getCell('I17')->getValue());
    }

    public function test_roster_export_combines_multiple_offices_into_one_document_with_continuous_item_numbers(): void
    {
        $employeeA = $this->createEmployee(['employee_type' => 'Job Orders']);
        $employeeB = $this->createEmployee(['employee_type' => 'Job Orders']);
        $employeeC = $this->createEmployee(['employee_type' => 'Job Orders']);
        $this->createAppointment(['employee' => $employeeA, 'office' => 'BAC']);
        $this->createAppointment(['employee' => $employeeB, 'office' => 'BAC']);
        $this->createAppointment(['employee' => $employeeC, 'office' => 'HRMO']);

        $sheet = $this->export();

        // One combined document - every distinct office listed together on a
        // single "OFFICE:" line, not a separate repeating block per office.
        $this->assertSame('OFFICE: BAC, HRMO', (string) $sheet->getCell('A7')->getValue());

        // Item numbers run continuously across all 3 appointments (no
        // per-office reset); rows still sort office-first so the two BAC
        // rows sit together ahead of the single HRMO row.
        $this->assertSame(1, $sheet->getCell('A11')->getValue());
        $this->assertSame('BAC', (string) $sheet->getCell('I11')->getValue());
        $this->assertSame(2, $sheet->getCell('A12')->getValue());
        $this->assertSame('BAC', (string) $sheet->getCell('I12')->getValue());
        $this->assertSame(3, $sheet->getCell('A13')->getValue());
        $this->assertSame('HRMO', (string) $sheet->getCell('I13')->getValue());

        // Only one letterhead/signature block in the whole document - the
        // certification paragraphs immediately follow the single data table.
        $this->assertStringContainsString('automatically cease upon its expiration', (string) $sheet->getCell('A15')->getValue());
        $this->assertSame('PREPARED BY:', (string) $sheet->getCell('A19')->getValue());
    }

    public function test_roster_export_uses_configured_signatories_and_the_acting_users_own_prepared_by(): void
    {
        Setting::first()?->delete();
        Setting::create([
            'email_template_body' => '',
            'mayor_name' => 'Juan Dela Cruz',
            'mayor_designation' => 'City Mayor',
            'hr_manager_name' => 'Maria Santos',
            'hr_manager_designation' => 'OIC-CHRMD',
            'budget_officer_name' => 'Pedro Reyes',
            'budget_officer_designation' => 'OIC City Budget Dept.',
        ]);
        $this->createAppointment();

        $rm = $this->createRecordsManager(['name' => 'Ana Preparer', 'designation' => 'Records Officer']);
        $response = $this->actingAs($rm)->get(route('dashboard.records-manager.job-order-roster.export'));
        $response->assertOk();

        $tmpPath = tempnam(sys_get_temp_dir(), 'job_order_roster_');
        file_put_contents($tmpPath, $response->streamedContent());
        $sheet = IOFactory::load($tmpPath)->getSheet(0);
        unlink($tmpPath);

        // Prepared By reflects whoever actually triggered the export, not a
        // stored setting.
        $this->assertSame('Ana Preparer', (string) $sheet->getCell('A20')->getValue());
        $this->assertSame('Records Officer', (string) $sheet->getCell('A21')->getValue());

        // The other three signatories come from configured settings.
        $this->assertSame('Maria Santos', (string) $sheet->getCell('C20')->getValue());
        $this->assertSame('OIC-CHRMD', (string) $sheet->getCell('C21')->getValue());
        $this->assertSame('Pedro Reyes', (string) $sheet->getCell('G20')->getValue());
        $this->assertSame('OIC City Budget Dept.', (string) $sheet->getCell('G21')->getValue());
        $this->assertSame('Juan Dela Cruz', (string) $sheet->getCell('I20')->getValue());
        $this->assertSame('City Mayor', (string) $sheet->getCell('I21')->getValue());
    }

    public function test_roster_filters_by_department(): void
    {
        $rm = $this->createRecordsManager();

        $deptA = Department::forceCreate([
            'DeptCode' => 'DEPTA',
            'Dept_name' => 'Department A',
            'EmpNo' => 'DEPTA-HEAD',
            'Designation' => 'Head',
        ]);
        $deptB = Department::forceCreate([
            'DeptCode' => 'DEPTB',
            'Dept_name' => 'Department B',
            'EmpNo' => 'DEPTB-HEAD',
            'Designation' => 'Head',
        ]);

        $employeeA = $this->createEmployee(['employee_type' => 'Job Orders', 'Dept_id' => $deptA->Dept_id]);
        $employeeB = $this->createEmployee(['employee_type' => 'Job Orders', 'Dept_id' => $deptB->Dept_id]);
        $this->createAppointment(['employee' => $employeeA, 'office' => 'BAC']);
        $this->createAppointment(['employee' => $employeeB, 'office' => 'HRMO']);

        $service = app(JobOrderRosterExportService::class);

        // A single scalar department_id (backward compatible - e.g. a direct
        // service call) still works, wrapped internally into a one-item list.
        $rows = $service->getRows(['department_id' => $deptA->Dept_id]);
        $this->assertCount(1, $rows);
        $this->assertSame($employeeA->id, $rows->first()->user_id);

        // Also confirm the roster page itself renders successfully with an
        // array-style department_id[], the shape the multi-select form sends.
        $response = $this->actingAs($rm)->get(
            route('dashboard.records-manager.job-order-roster').'?'.http_build_query(['department_id' => [$deptA->Dept_id]])
        );
        $response->assertOk();
    }

    public function test_roster_filters_by_two_or_more_departments(): void
    {
        $rm = $this->createRecordsManager();

        $deptA = Department::forceCreate([
            'DeptCode' => 'DEPTA2',
            'Dept_name' => 'Department A2',
            'EmpNo' => 'DEPTA2-HEAD',
            'Designation' => 'Head',
        ]);
        $deptB = Department::forceCreate([
            'DeptCode' => 'DEPTB2',
            'Dept_name' => 'Department B2',
            'EmpNo' => 'DEPTB2-HEAD',
            'Designation' => 'Head',
        ]);
        $deptC = Department::forceCreate([
            'DeptCode' => 'DEPTC2',
            'Dept_name' => 'Department C2',
            'EmpNo' => 'DEPTC2-HEAD',
            'Designation' => 'Head',
        ]);

        $employeeA = $this->createEmployee(['employee_type' => 'Job Orders', 'Dept_id' => $deptA->Dept_id]);
        $employeeB = $this->createEmployee(['employee_type' => 'Job Orders', 'Dept_id' => $deptB->Dept_id]);
        $employeeC = $this->createEmployee(['employee_type' => 'Job Orders', 'Dept_id' => $deptC->Dept_id]);
        $this->createAppointment(['employee' => $employeeA, 'office' => 'BAC']);
        $this->createAppointment(['employee' => $employeeB, 'office' => 'HRMO']);
        $this->createAppointment(['employee' => $employeeC, 'office' => 'CHRMD']);

        $service = app(JobOrderRosterExportService::class);
        $rows = $service->getRows(['department_id' => [$deptA->Dept_id, $deptC->Dept_id]]);

        $this->assertCount(2, $rows);
        $userIds = $rows->pluck('user_id')->all();
        $this->assertContains($employeeA->id, $userIds);
        $this->assertContains($employeeC->id, $userIds);
        $this->assertNotContains($employeeB->id, $userIds);

        $response = $this->actingAs($rm)->get(
            route('dashboard.records-manager.job-order-roster').'?'.http_build_query(['department_id' => [$deptA->Dept_id, $deptC->Dept_id]])
        );
        $response->assertOk();

        $exportResponse = $this->actingAs($rm)->get(
            route('dashboard.records-manager.job-order-roster.export').'?'.http_build_query(['department_id' => [$deptA->Dept_id, $deptC->Dept_id]])
        );
        $exportResponse->assertOk();
    }

    public function test_roster_filters_by_office(): void
    {
        $rm = $this->createRecordsManager();
        $this->createAppointment(['office' => 'BAC']);
        $this->createAppointment(['office' => 'HRMO']);

        $service = app(JobOrderRosterExportService::class);
        $rows = $service->getRows(['office' => 'BAC']);

        $this->assertCount(1, $rows);
        $this->assertSame('BAC', $rows->first()->office);
    }

    public function test_roster_filters_by_period_range(): void
    {
        $service = app(JobOrderRosterExportService::class);

        $this->createAppointment(['period_from' => '2026-01-01', 'period_until' => '2026-03-31']);
        $this->createAppointment(['period_from' => '2026-07-01', 'period_until' => '2026-09-30']);

        $rows = $service->getRows(['period_from' => '2026-01-01', 'period_to' => '2026-03-31']);

        $this->assertCount(1, $rows);
        $this->assertSame('2026-01-01', $rows->first()->period_from->toDateString());
    }

    public function test_roster_defaults_to_currently_active_appointments_when_no_period_filter_given(): void
    {
        $service = app(JobOrderRosterExportService::class);

        $this->createAppointment(['period_from' => now()->subYear()->toDateString(), 'period_until' => now()->subMonths(6)->toDateString()]);
        $this->createAppointment(['period_from' => now()->subMonth()->toDateString(), 'period_until' => now()->addMonth()->toDateString()]);

        $rows = $service->getRows([]);

        $this->assertCount(1, $rows);
    }

    public function test_roster_export_is_records_manager_only(): void
    {
        $employee = $this->createEmployee();

        $response = $this->actingAs($employee)->get(route('dashboard.records-manager.job-order-roster.export'));

        $this->assertTrue($response->getStatusCode() === 403, 'Expected 403, got '.$response->getStatusCode());
    }
}
