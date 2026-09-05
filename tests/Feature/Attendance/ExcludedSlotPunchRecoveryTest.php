<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\Locator;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Services\Form48ExportService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A WorkSuspension/DtrExcuse's unconditional slot exclusion tells
 * AttendanceMatcher not to expect a punch in that slot at all - so a real
 * biometric punch that happens anyway never lands in dtrs.time_in_am/
 * time_out_am/time_in_pm/time_out_pm, it falls into dtrs.unmatched_logs (or,
 * on a Standard Day schedule, gets claimed by the independent overtime-
 * matching path into time_in_ot/time_out_ot instead). Both the DTR page and
 * Form 48 export used to show a blank "SUSPENDED"/"EXCUSED" day with no
 * evidence the employee was ever there.
 *
 * Real incident that surfaced this: employee EmpNo 2101110 genuinely worked
 * a complete, on-time day (07:47/12:01/12:31/17:00) on a date with a
 * full-day Work Suspension declared - both surfaces showed a blank
 * "SUSPENDED" day despite the punches being right there in attendance_logs.
 *
 * ExcludedSlotPunchRecovery fixes this by re-resolving the day's punches
 * WITHOUT the exclusion, then only accepting a slot's recovered value if it
 * also appears in the REAL (persisted) resolution's leftover pool
 * (unmatched_logs + time_in_ot/time_out_ot) - display-only, never touches
 * the stored dtrs row or late_minutes/undertime_minutes.
 */
class ExcludedSlotPunchRecoveryTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-08-19';

    private function punchAt(User $user, string $date, string $time): void
    {
        AttendanceLog::create([
            'user_id' => $user->id,
            'emp_no' => $user->EmpNo,
            'logdate' => $date,
            'logtime' => $time,
        ]);
    }

    private function recompute(User $user, string $date): void
    {
        app(PersonnelLogImportService::class)->recomputeDtr($user, $date, $date);
    }

    /**
     * A plain createEmployee() has no ShiftAssignment, so it falls back to
     * WorkSchedule::global() - a Standard Day schedule. AttendanceMatcher only
     * builds ot_in/ot_out candidate events on a Standard Day, both anchored at
     * the exact same reference time as PM Out; a punch that lands there ties
     * all three candidates and can resolve to OT Out instead of PM Out - a
     * real but unrelated ambiguity that has nothing to do with Locator
     * recovery. Assigning a normal (non-Standard-Day) Shift template sidesteps
     * it entirely, matching FAMILARA's real-world shift setup.
     */
    private function assignOrdinaryShift(User $user, string $date): void
    {
        $shift = Shift::create([
            'name' => 'Locator Recovery Test Shift', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse($date)->subDay(), null, null, null, [0, 1, 2, 3, 4, 5, 6], false
        );
    }

    private function fillForm48(User $user): Worksheet
    {
        $exportService = app(Form48ExportService::class);
        $records = $exportService->buildRecords($user->id, '2026-08-01', '2026-08-31');
        $excuseMap = $exportService->buildExcuseMap($user->id, '2026-08-01', '2026-08-31');

        $templatePath = storage_path('app/templates/form48.xls');
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $exportService->fill(
            $sheet, $records, $user, 'August 2026', '2026-08-01',
            excuseMap: $excuseMap,
        );

        return $sheet;
    }

    private function fillForm48WithLocator(User $user): Worksheet
    {
        $exportService = app(Form48ExportService::class);
        $records = $exportService->buildRecords($user->id, '2026-08-01', '2026-08-31');
        $locatorMap = $exportService->buildLocatorMap($user->id, '2026-08-01', '2026-08-31');

        $templatePath = storage_path('app/templates/form48.xls');
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $exportService->fill(
            $sheet, $records, $user, 'August 2026', '2026-08-01',
            locatorMap: $locatorMap,
        );

        return $sheet;
    }

    private function dtrPageRow(User $user, string $date): ?array
    {
        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => Carbon::parse($date)->format('Y-m'),
            ]));

        $response->assertOk();

        return collect($response->json('data'))
            ->firstWhere('date', Carbon::parse($date)->format('M d, Y (D)'));
    }

    // ── Full-day Work Suspension ─────────────────────────────────────────────

    public function test_dtr_page_recovers_real_punches_hidden_by_a_full_day_suspension(): void
    {
        $user = $this->createEmployee(['last_name' => 'Suspendedrecovery']);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Test suspension',
            'type' => 'weather',
        ]);

        foreach (['07:47:00', '12:01:00', '12:31:00', '17:00:00'] as $time) {
            $this->punchAt($user, self::DATE, $time);
        }
        $this->recompute($user, self::DATE);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        $this->assertStringContainsString('07:47', $row['time_in_am'], 'Real AM In punch must be recovered instead of hidden behind SUSPENDED.');
        $this->assertStringContainsString('12:01', $row['time_out_am']);
        $this->assertStringContainsString('12:31', $row['time_in_pm']);
        $this->assertStringContainsString('17:00', $row['time_out_pm']);
        $this->assertSame(0, $row['late_minutes'], 'A suspended day still charges zero penalty despite the recovered punch.');
        $this->assertSame(0, $row['undertime_minutes']);
    }

    public function test_form48_export_recovers_real_punches_hidden_by_a_full_day_suspension(): void
    {
        $user = $this->createEmployee(['last_name' => 'Suspendedrecoveryform48']);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Test suspension',
            'type' => 'weather',
        ]);

        foreach (['07:47:00', '12:01:00', '12:31:00', '17:00:00'] as $time) {
            $this->punchAt($user, self::DATE, $time);
        }
        $this->recompute($user, self::DATE);

        $sheet = $this->fillForm48($user);

        // Day 19 -> row DATA_ROW_OFFSET(11) + 19 = 30; column group 0 = C/D/E/F/G/H.
        $row = 30;
        $this->assertSame('07:47', $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('12:01', $sheet->getCell("D{$row}")->getValue());
        $this->assertSame('12:31', $sheet->getCell("E{$row}")->getValue());
        $this->assertSame('17:00', $sheet->getCell("F{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue(), 'A suspended day still charges zero penalty despite the recovered punch.');
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    public function test_form48_export_still_shows_suspended_label_when_no_real_punch_exists(): void
    {
        $user = $this->createEmployee(['last_name' => 'Suspendednopunch']);

        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => null,
            'reason' => 'Test suspension',
            'type' => 'weather',
        ]);

        // No attendance_logs at all - nothing to recover.
        $this->recompute($user, self::DATE);

        $sheet = $this->fillForm48($user);

        $row = 30;
        $this->assertTrue($sheet->getCell("C{$row}")->isMergeRangeValueCell());
        $this->assertSame('WEATHER / TYPHOON', $sheet->getCell("C{$row}")->getValue());
    }

    // ── Half-day (PM-only) Work Suspension ───────────────────────────────────

    public function test_dtr_page_recovers_only_the_excluded_half_of_a_pm_only_suspension(): void
    {
        $user = $this->createEmployee(['last_name' => 'Suspendedpmonly']);

        // A 12:00 cutoff on the 8-5 default schedule excludes only pm_in/pm_out -
        // the AM half was worked before the suspension took effect and matches normally.
        WorkSuspension::create([
            'suspension_date' => self::DATE,
            'suspension_time' => '12:00',
            'reason' => 'Afternoon dismissal',
            'type' => 'weather',
        ]);

        foreach (['07:47:00', '12:01:00', '12:31:00', '17:00:00'] as $time) {
            $this->punchAt($user, self::DATE, $time);
        }
        $this->recompute($user, self::DATE);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        $this->assertSame('07:47', $row['time_in_am'], 'AM slots were never excluded - they must resolve normally, not through recovery.');
        $this->assertSame('12:01', $row['time_out_am']);
        $this->assertStringContainsString('12:31', $row['time_in_pm'], 'The excluded PM In slot must recover the real punch.');
        $this->assertStringContainsString('17:00', $row['time_out_pm'], 'The excluded PM Out slot must recover the real punch.');
        $this->assertSame(0, $row['late_minutes']);
        $this->assertSame(0, $row['undertime_minutes']);
    }

    // ── DtrExcuse ─────────────────────────────────────────────────────────────

    public function test_dtr_page_recovers_real_punch_hidden_by_a_full_day_dtr_excuse(): void
    {
        $user = $this->createEmployee(['last_name' => 'Excusedrecovery']);

        DtrExcuse::create([
            'user_id' => $user->id,
            'date' => self::DATE,
            'excuse_type' => 'power_interruption',
            'is_full_day' => true,
            'excuse_am_in' => true,
            'excuse_am_out' => true,
            'excuse_pm_in' => true,
            'excuse_pm_out' => true,
        ]);

        foreach (['07:47:00', '12:01:00', '12:31:00', '17:00:00'] as $time) {
            $this->punchAt($user, self::DATE, $time);
        }
        $this->recompute($user, self::DATE);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        $this->assertStringContainsString('07:47', $row['time_in_am'], 'Real AM In punch must be recovered instead of hidden behind EXCUSED.');
        $this->assertStringContainsString('12:01', $row['time_out_am']);
        $this->assertStringContainsString('12:31', $row['time_in_pm']);
        $this->assertStringContainsString('17:00', $row['time_out_pm']);
        $this->assertSame(0, $row['late_minutes']);
        $this->assertSame(0, $row['undertime_minutes']);
    }

    public function test_form48_export_recovers_real_punch_hidden_by_a_full_day_dtr_excuse(): void
    {
        $user = $this->createEmployee(['last_name' => 'Excusedrecoveryform48']);

        DtrExcuse::create([
            'user_id' => $user->id,
            'date' => self::DATE,
            'excuse_type' => 'power_interruption',
            'is_full_day' => true,
            'excuse_am_in' => true,
            'excuse_am_out' => true,
            'excuse_pm_in' => true,
            'excuse_pm_out' => true,
        ]);

        foreach (['07:47:00', '12:01:00', '12:31:00', '17:00:00'] as $time) {
            $this->punchAt($user, self::DATE, $time);
        }
        $this->recompute($user, self::DATE);

        $sheet = $this->fillForm48($user);

        $row = 30;
        $this->assertSame('07:47', $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('12:01', $sheet->getCell("D{$row}")->getValue());
        $this->assertSame('12:31', $sheet->getCell("E{$row}")->getValue());
        $this->assertSame('17:00', $sheet->getCell("F{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    // ── Locator (windowed exclusion) ─────────────────────────────────────────

    /**
     * A Locator's exclusion is windowed, not unconditional - it only rejects a
     * punch actually falling inside the approved [departure, arrival] travel
     * window, not the whole slot regardless of time. Recovery reuses the same
     * $decorateSlot() stacked time+badge convention DtrController::data()
     * already applies to Excuse/Suspension-covered slots (e.g. a Suspension-
     * recovered PM In renders as the real time stacked above a "Weather /
     * Typhoon" badge) - a Locator-covered slot gets an equivalent "Locator"
     * badge rather than a bespoke marker, for visual consistency across all
     * three exclusion types.
     *
     * Real incident that surfaced this: EmpNo 2501982, 2026-08-06, an approved
     * Locator (11:00-13:00) covered AM Out/PM In; real punches at 12:03/12:49
     * were correctly excluded from those slots by AttendanceMatcher and landed
     * in unmatched_logs, but neither the DTR page nor Form 48 showed any trace
     * of them - both displayed the bare "LOCATOR" placeholder.
     */
    public function test_dtr_page_recovers_real_punch_hidden_by_a_locator_conflict(): void
    {
        $user = $this->createEmployee(['last_name' => 'Locatorrecovery']);
        $this->assignOrdinaryShift($user, self::DATE);

        Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Personal',
            'location' => 'Test location',
            'travel_date' => self::DATE,
            'intended_departure_time' => '11:00:00',
            'intended_arrival_time' => '13:00:00',
            'detail' => 'Test errand',
            'status' => 'approved',
        ]);

        foreach (['07:47:00', '12:03:00', '12:49:00', '17:00:00'] as $time) {
            $this->punchAt($user, self::DATE, $time);
        }
        $this->recompute($user, self::DATE);

        $row = $this->dtrPageRow($user, self::DATE);

        $this->assertNotNull($row);
        $this->assertSame('07:47', $row['time_in_am'], 'AM In was never covered - resolves as a plain value, no badge.');
        $this->assertSame('17:00', $row['time_out_pm'], 'PM Out was never covered - resolves as a plain value, no badge.');
        // Recovered slots get the same stacked time+badge treatment as a
        // Suspension/Excuse recovery, not a bespoke marker.
        $this->assertStringContainsString('12:03', $row['time_out_am'], 'The excluded AM Out slot must recover the real punch.');
        $this->assertStringContainsString('Locator', $row['time_out_am']);
        $this->assertStringContainsString('12:49', $row['time_in_pm'], 'The excluded PM In slot must recover the real punch.');
        $this->assertStringContainsString('Locator', $row['time_in_pm']);
        $this->assertStringNotContainsString('Locator', $row['time_in_am']);
        $this->assertStringNotContainsString('Locator', $row['time_out_pm']);
    }

    public function test_form48_export_recovers_real_punch_hidden_by_a_locator_conflict(): void
    {
        $user = $this->createEmployee(['last_name' => 'Locatorrecoveryform48']);
        $this->assignOrdinaryShift($user, self::DATE);

        Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Personal',
            'location' => 'Test location',
            'travel_date' => self::DATE,
            'intended_departure_time' => '11:00:00',
            'intended_arrival_time' => '13:00:00',
            'detail' => 'Test errand',
            'status' => 'approved',
        ]);

        foreach (['07:47:00', '12:03:00', '12:49:00', '17:00:00'] as $time) {
            $this->punchAt($user, self::DATE, $time);
        }
        $this->recompute($user, self::DATE);

        $sheet = $this->fillForm48WithLocator($user);

        $row = 30;
        $this->assertSame('07:47', $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('12:03', $sheet->getCell("D{$row}")->getValue());
        $this->assertSame('12:49', $sheet->getCell("E{$row}")->getValue());
        $this->assertSame('17:00', $sheet->getCell("F{$row}")->getValue());
        // Recovered cells get a distinct amber font marker (Excel can't carry
        // an HTML tooltip); untouched cells keep the default color.
        $this->assertSame('FFB45309', $sheet->getStyle("D{$row}")->getFont()->getColor()->getARGB());
        $this->assertSame('FFB45309', $sheet->getStyle("E{$row}")->getFont()->getColor()->getARGB());
        $this->assertNotSame('FFB45309', $sheet->getStyle("C{$row}")->getFont()->getColor()->getARGB());
        $this->assertNotSame('FFB45309', $sheet->getStyle("F{$row}")->getFont()->getColor()->getARGB());
    }

    public function test_form48_export_still_shows_locator_label_when_no_real_punch_exists(): void
    {
        $user = $this->createEmployee(['last_name' => 'Locatornopunch']);
        $this->assignOrdinaryShift($user, self::DATE);

        Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Personal',
            'location' => 'Test location',
            'travel_date' => self::DATE,
            'intended_departure_time' => '11:00:00',
            'intended_arrival_time' => '13:00:00',
            'detail' => 'Test errand',
            'status' => 'approved',
        ]);

        // No attendance_logs at all - nothing to recover.
        $this->recompute($user, self::DATE);

        $sheet = $this->fillForm48WithLocator($user);

        $row = 30;
        $this->assertSame('LOCATOR', $sheet->getCell("D{$row}")->getValue());
        $this->assertSame('LOCATOR', $sheet->getCell("E{$row}")->getValue());
    }
}
