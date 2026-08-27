<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceMonitoringExportService;
use App\Services\Form48ExportService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A crossing shift's dtrs row is stored on its START date, but the checkout
 * physically happens on the day AFTER - real incident: a 24-on/24-off duty
 * started with no closing punch at all, while a same-employee Office Order
 * covering only the NEXT day directed him straight into an all-day event
 * instead of back to post to punch out. DtrController::data(),
 * AttendanceMonitoringExportService, and Form48ExportService each only ever
 * checked coverage (Office Order/Travel Order/Leave/ETA/etc.) against the
 * row's own start date, never the day after - so a perfectly legitimate
 * whole-day authorization on the checkout's actual date was invisible, and
 * the shift rendered as a flat "Missing OUT" with a full day's imputed
 * undertime (1440 minutes) everywhere. Fixed via WorkSchedule::slotDate(),
 * which resolves which calendar date each Form 48 slot's reference time
 * actually falls on for a crossing shift, reused as a fallback - checked
 * only once the row's own date has no coverage, and only ever affecting the
 * pm_out slot - across all three consumers.
 */
class CrossingShiftNextDayCoverageTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const START_DATE = '2026-08-13';

    private const CHECKOUT_DATE = '2026-08-14';

    private function assign24HourNoBreakShift(User $user): void
    {
        $shift = Shift::create([
            'name' => '24-Hour Duty', 'time_in' => '08:00', 'time_out' => '08:00',
            'break_out' => null, 'break_in' => null, 'crosses_midnight' => true, 'is_active' => true,
        ]);
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse(self::START_DATE), null, null, null, [0, 1, 2, 3, 4, 5, 6], true
        );
    }

    /** Only the opening punch exists - no closing punch anywhere in the log, reproducing the real incident. */
    private function importOpeningPunchWithNoCheckout(User $user): void
    {
        AttendanceLog::create([
            'user_id' => $user->id, 'emp_no' => $user->EmpNo,
            'logdate' => self::START_DATE, 'logtime' => '08:00:00',
        ]);
        app(PersonnelLogImportService::class)->recomputeDtr($user, self::START_DATE, self::CHECKOUT_DATE);
    }

    private function fileOfficeOrderCoveringCheckoutDate(User $user): string
    {
        $officeOrderNum = 'OO-2026-999';
        $officeOrderId = DB::table('office_orders')->insertGetId([
            'office_order_num' => $officeOrderNum,
            'subject' => 'All-day congress',
            'issued_date' => self::CHECKOUT_DATE,
            'effective_date' => self::CHECKOUT_DATE,
            'status' => 'Pending Recommendation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('office_order_employees')->insert([
            'office_order_id' => $officeOrderId,
            'emp_no' => $user->EmpNo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $officeOrderNum;
    }

    // ── DtrController::data() ────────────────────────────────────────────────

    public function test_dtr_page_recognizes_next_day_office_order_as_covering_the_checkout(): void
    {
        $user = $this->createEmployee(['last_name' => 'Crossshiftdtr']);
        $this->assign24HourNoBreakShift($user);
        $this->importOpeningPunchWithNoCheckout($user);
        $officeOrderNum = $this->fileOfficeOrderCoveringCheckoutDate($user);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => '2026-08',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse(self::START_DATE)->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('08:00', $row['time_in_am'], 'The real opening punch must never be relabeled by the checkout-side fix.');
        $this->assertStringContainsString('Office Order', $row['time_out_pm']);
        $this->assertSame(0, $row['undertime_minutes'], 'The checkout is covered by the next-day Office Order - no imputed penalty.');
        $this->assertStringNotContainsString('Missing OUT', $row['status_badge']);
        $this->assertStringContainsString('OO #'.$officeOrderNum, $row['office_order_badge']);
    }

    // ── AttendanceMonitoringExportService ────────────────────────────────────

    public function test_monitoring_matrix_excludes_undertime_covered_by_next_day_office_order(): void
    {
        $user = $this->createEmployee(['last_name' => 'Crossshiftmatrix']);
        $this->assign24HourNoBreakShift($user);
        $this->importOpeningPunchWithNoCheckout($user);
        $this->fileOfficeOrderCoveringCheckoutDate($user);

        $departments = Department::where('Dept_id', $user->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere('user_id', $user->id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['undertime_count'], 'A checkout covered by a next-day Office Order must not count toward undertime.');
        $this->assertSame(0, $row['undertime_minutes']);
        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertStringNotContainsString('Undertime', $row['remarks']);
        $this->assertStringNotContainsString('Unofficial Exit', $row['remarks']);
    }

    // ── Form48ExportService ──────────────────────────────────────────────────

    public function test_form48_export_shows_office_order_label_and_zero_undertime_for_covered_checkout(): void
    {
        $user = $this->createEmployee(['last_name' => 'Crossshiftform48']);
        $this->assign24HourNoBreakShift($user);
        $this->importOpeningPunchWithNoCheckout($user);
        $this->fileOfficeOrderCoveringCheckoutDate($user);

        $exportService = app(Form48ExportService::class);
        $records = $exportService->buildRecords($user->id, '2026-08-01', '2026-08-31');
        $leaveMap = $exportService->buildLeaveMap($user->id, '2026-08-01', '2026-08-31');
        $etaMap = $exportService->buildEtaMap($user->id, '2026-08-01', '2026-08-31');
        $officeOrderMap = $exportService->buildOfficeOrderMap($user->id, '2026-08-01', '2026-08-31');

        $spreadsheet = IOFactory::load(storage_path('app/templates/form48.xls'));
        $sheet = $spreadsheet->getActiveSheet();

        $exportService->fill($sheet, $records, $user, 'August 2026', '2026-08-01', $leaveMap, $etaMap, [], [], [], [], $officeOrderMap);

        // Day 13 -> row DATA_ROW_OFFSET(11) + 13 = 24; column group 0 = C (am_in) / F (pm_out) / G,H (undertime h/m).
        $row = 24;
        $this->assertSame('08:00', $sheet->getCell("C{$row}")->getValue(), 'The real opening punch must never be relabeled by the checkout-side fix.');
        $this->assertSame('Office Order', $sheet->getCell("F{$row}")->getValue());
        $this->assertSame(0, $sheet->getCell("G{$row}")->getValue());
        $this->assertSame(0, $sheet->getCell("H{$row}")->getValue());
    }

    // ── Negative control: no next-day coverage anywhere ──────────────────────

    public function test_missing_checkout_without_any_next_day_coverage_still_shows_missing_out(): void
    {
        $user = $this->createEmployee(['last_name' => 'Crossshiftuncovered']);
        $this->assign24HourNoBreakShift($user);
        $this->importOpeningPunchWithNoCheckout($user);
        // Deliberately no Office Order/ETA/Leave filed for self::CHECKOUT_DATE.

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => '2026-08',
            ]));
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse(self::START_DATE)->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('Missing', $row['time_out_pm'], 'With no next-day coverage of any kind, the checkout must still read as genuinely missing.');
        $this->assertSame(1440, $row['undertime_minutes']);
        $this->assertStringContainsString('Missing OUT', $row['status_badge']);

        $departments = Department::where('Dept_id', $user->Dept_id)->get();
        $matrixRow = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026)
            ->firstWhere('user_id', $user->id);
        $this->assertSame(1, $matrixRow['undertime_count'], 'The fix must be a fallback only - a genuine, uncovered missing checkout is still flagged.');
        $this->assertSame(1440, $matrixRow['undertime_minutes']);
    }
}
