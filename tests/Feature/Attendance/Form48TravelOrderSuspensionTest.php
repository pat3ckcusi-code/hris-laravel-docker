<?php

namespace Tests\Feature\Attendance;

use App\Models\Dtr;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Services\Form48ExportService;
use App\Services\ShiftAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Form 48 export previously had no support at all for Travel Order (blank/
 * missing punches shown regardless of an approved order) or Work Suspension
 * (only reshaped which punches got matched upstream, never rendered a
 * "SUSPENDED" label) - both gaps relative to the DTR page, which already
 * handles both correctly. This brings Form 48 to parity, following the same
 * "a real punch always wins, the label only fills a slot with no real
 * punch" convention as ETA/Office Order/DtrExcuse in the same file.
 */
class Form48TravelOrderSuspensionTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-08-05';

    private function assignEightToFiveShift(User $user): void
    {
        $shift = Shift::create([
            'name' => 'Eight To Five', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse(self::DATE)->subDay(), null, null, null, [0, 1, 2, 3, 4, 5, 6], false
        );
    }

    private function fileApprovedTravelOrder(User $user): void
    {
        $toId = DB::table('travel_orders')->insertGetId([
            'travel_order_num' => 'TO-TEST-'.self::DATE,
            'destination' => 'Provincial Capitol',
            'purpose' => 'Official business',
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'status' => 'Approved',
            'recommender' => $user->id,
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('travel_order_employees')->insert([
            'travel_order_id' => $toId,
            'emp_no' => $user->EmpNo,
        ]);
    }

    private function fillSheet(Form48ExportService $exportService, User $user, array $travelOrderMap = []): Worksheet
    {
        $records = $exportService->buildRecords($user->id, '2026-08-01', '2026-08-31');

        $templatePath = storage_path('app/templates/form48.xls');
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $exportService->fill(
            $sheet, $records, $user, 'August 2026', '2026-08-01',
            travelOrderMap: $travelOrderMap,
        );

        return $sheet;
    }

    // ── Travel Order ─────────────────────────────────────────────────────────

    public function test_form48_export_shows_travel_order_label_with_no_punches(): void
    {
        $user = $this->createEmployee(['last_name' => 'Travelorderform48']);
        $this->assignEightToFiveShift($user);
        $this->fileApprovedTravelOrder($user);

        $exportService = app(Form48ExportService::class);
        $travelOrderMap = $exportService->buildTravelOrderMap($user->id, '2026-08-01', '2026-08-31');
        $sheet = $this->fillSheet($exportService, $user, $travelOrderMap);

        $row = 16;
        $this->assertTrue($sheet->getCell("C{$row}")->isMergeRangeValueCell());
        $this->assertSame('Travel Order', $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    public function test_form48_export_shows_real_punch_and_travel_order_label_for_partial_punches(): void
    {
        $user = $this->createEmployee(['last_name' => 'Travelorderpartial']);
        $this->assignEightToFiveShift($user);
        $this->fileApprovedTravelOrder($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:00:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $exportService = app(Form48ExportService::class);
        $travelOrderMap = $exportService->buildTravelOrderMap($user->id, '2026-08-01', '2026-08-31');
        $sheet = $this->fillSheet($exportService, $user, $travelOrderMap);

        $row = 16;
        $this->assertSame('08:00', $sheet->getCell("C{$row}")->getValue(), 'Real AM In punch must not be hidden behind the Travel Order label.');
        $this->assertTrue($sheet->getCell("D{$row}")->isMergeRangeValueCell());
        $this->assertSame('Travel Order', $sheet->getCell("D{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    // ── Work Suspension ──────────────────────────────────────────────────────

    public function test_form48_export_shows_suspended_label_for_full_day_suspension_with_no_punches(): void
    {
        $user = $this->createEmployee(['last_name' => 'Suspendedform48']);
        $this->assignEightToFiveShift($user);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Test suspension',
            'type' => 'weather',
        ]);

        $sheet = $this->fillSheet(app(Form48ExportService::class), $user);

        $row = 16;
        $this->assertTrue($sheet->getCell("C{$row}")->isMergeRangeValueCell());
        $this->assertSame('WEATHER / TYPHOON', $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    public function test_form48_export_shows_holiday_label_for_full_day_holiday_suspension_with_no_punches(): void
    {
        $user = $this->createEmployee(['last_name' => 'Holidayform48']);
        $this->assignEightToFiveShift($user);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Test holiday',
            'type' => 'holiday',
        ]);

        $sheet = $this->fillSheet(app(Form48ExportService::class), $user);

        $row = 16;
        $this->assertTrue($sheet->getCell("C{$row}")->isMergeRangeValueCell());
        $this->assertSame('HOLIDAY', $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    public function test_form48_export_shows_real_am_punches_and_holiday_label_for_pm_of_afternoon_holiday_cutoff(): void
    {
        $user = $this->createEmployee(['last_name' => 'Holidaypartial']);
        $this->assignEightToFiveShift($user);

        // A 12:00 cutoff (between morningEnd and lunchReturn on the 8-5
        // schedule) excludes only the PM slots - the AM half was worked
        // before the holiday suspension took effect. Proves a real punch
        // still wins over the label even for the new holiday-type path,
        // not just the pre-existing generic/weather path.
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '12:00',
            'reason' => 'Afternoon holiday dismissal',
            'type' => 'holiday',
        ]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $sheet = $this->fillSheet(app(Form48ExportService::class), $user);

        $row = 16;
        $this->assertSame('08:00', $sheet->getCell("C{$row}")->getValue(), 'Real AM In punch must not be hidden behind the HOLIDAY label.');
        $this->assertSame('12:00', $sheet->getCell("D{$row}")->getValue(), 'Real AM Out punch must not be hidden behind the HOLIDAY label.');
        $this->assertSame('HOLIDAY', $sheet->getCell("E{$row}")->getValue());
        $this->assertSame('HOLIDAY', $sheet->getCell("F{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    public function test_form48_export_shows_real_am_punches_and_suspended_pm_for_afternoon_cutoff(): void
    {
        $user = $this->createEmployee(['last_name' => 'Suspendedpartial']);
        $this->assignEightToFiveShift($user);

        // A 12:00 cutoff (between morningEnd and lunchReturn on the 8-5
        // schedule) excludes only the PM slots - the AM half was worked
        // before the suspension took effect.
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '12:00',
            'reason' => 'Afternoon dismissal',
            'type' => 'weather',
        ]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $sheet = $this->fillSheet(app(Form48ExportService::class), $user);

        $row = 16;
        $this->assertSame('08:00', $sheet->getCell("C{$row}")->getValue(), 'Real AM In punch must not be hidden behind the WEATHER / TYPHOON label.');
        $this->assertSame('12:00', $sheet->getCell("D{$row}")->getValue(), 'Real AM Out punch must not be hidden behind the WEATHER / TYPHOON label.');
        $this->assertSame('WEATHER / TYPHOON', $sheet->getCell("E{$row}")->getValue());
        $this->assertSame('WEATHER / TYPHOON', $sheet->getCell("F{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    public function test_frontline_exempt_employee_form48_unaffected_by_suspension(): void
    {
        $user = $this->createEmployee(['last_name' => 'Frontlineform48', 'is_frontline' => true]);
        $this->assignEightToFiveShift($user);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Test suspension',
            'type' => 'weather',
        ]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $sheet = $this->fillSheet(app(Form48ExportService::class), $user);

        $row = 16;
        $this->assertSame('08:00', $sheet->getCell("C{$row}")->getValue(), 'A frontline-exempt employee is unaffected by a declared suspension.');
        $this->assertSame('12:00', $sheet->getCell("D{$row}")->getValue());
        $this->assertSame('13:00', $sheet->getCell("E{$row}")->getValue());
        $this->assertSame('17:00', $sheet->getCell("F{$row}")->getValue());
    }
}
