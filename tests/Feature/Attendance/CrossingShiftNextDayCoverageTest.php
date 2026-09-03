<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSuspension;
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

    private function assign24HourNoBreakShift(User $user, ?string $startDate = null): void
    {
        $shift = Shift::create([
            'name' => '24-Hour Duty', 'time_in' => '08:00', 'time_out' => '08:00',
            'break_out' => null, 'break_in' => null, 'crosses_midnight' => true, 'is_active' => true,
        ]);
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse($startDate ?? self::START_DATE), null, null, null, [0, 1, 2, 3, 4, 5, 6], true
        );
    }

    /** Only the opening punch exists - no closing punch anywhere in the log, reproducing the real incident. */
    private function importOpeningPunchWithNoCheckout(User $user, ?string $startDate = null, ?string $checkoutDate = null): void
    {
        $startDate ??= self::START_DATE;
        $checkoutDate ??= self::CHECKOUT_DATE;
        AttendanceLog::create([
            'user_id' => $user->id, 'emp_no' => $user->EmpNo,
            'logdate' => $startDate, 'logtime' => '08:00:00',
        ]);
        app(PersonnelLogImportService::class)->recomputeDtr($user, $startDate, $checkoutDate);
    }

    private function fileOfficeOrderCoveringCheckoutDate(User $user, ?string $checkoutDate = null): string
    {
        $checkoutDate ??= self::CHECKOUT_DATE;
        $officeOrderNum = 'OO-2026-999';
        $officeOrderId = DB::table('office_orders')->insertGetId([
            'office_order_num' => $officeOrderNum,
            'subject' => 'All-day congress',
            'issued_date' => $checkoutDate,
            'effective_date' => $checkoutDate,
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

    private function fileFullDayLeaveOn(User $user, string $dateStr): void
    {
        $leave = LeaveRequest::create([
            'user_id' => $user->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => $dateStr,
            'end_date' => $dateStr,
            'reason' => 'Test',
            'status' => 'approved',
        ]);
        // 'days' defaults to 1.00 (a full-day leave) per the leave_dates schema.
        LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => $dateStr, 'is_cancelled' => false]);
    }

    private function declareFullDaySuspensionOn(string $dateStr): void
    {
        WorkSuspension::create(['suspension_date' => $dateStr, 'suspension_time' => null, 'reason' => 'Test suspension']);
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

    /**
     * $leaveMap/$suspensionMap were the only two of the six coverage sources
     * DtrController::data() builds that were NOT padded to $toNextDay
     * (unlike $etaDateSet/$officeOrderDateMap/$travelOrderDateMap/
     * $exemptionDateMap) - so a crossing shift starting on the very LAST day
     * of the requested period (here, the monthly period's own Aug 31) could
     * never see a full-day Leave/WorkSuspension filed for its checkout date
     * (Sep 1), since that date fell strictly outside both maps' query range.
     */
    public function test_dtr_page_recognizes_next_day_leave_as_covering_checkout_at_period_boundary(): void
    {
        $user = $this->createEmployee(['last_name' => 'Crossshiftdtrleaveboundary']);
        $startDate = '2026-08-31';
        $checkoutDate = '2026-09-01';
        $this->assign24HourNoBreakShift($user, $startDate);
        $this->importOpeningPunchWithNoCheckout($user, $startDate, $checkoutDate);
        $this->fileFullDayLeaveOn($user, $checkoutDate);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => '2026-08',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse($startDate)->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('08:00', $row['time_in_am'], 'The real opening punch must never be relabeled by the checkout-side fix.');
        $this->assertSame('VL', $row['time_out_pm']);
        $this->assertSame(0, $row['undertime_minutes'], 'The checkout is covered by a full-day Leave filed for the day right after the period ends.');
        $this->assertStringNotContainsString('Missing OUT', $row['status_badge']);
    }

    public function test_dtr_page_recognizes_next_day_suspension_as_covering_checkout_at_period_boundary(): void
    {
        $user = $this->createEmployee(['last_name' => 'Crossshiftdtrsuspensionboundary']);
        $startDate = '2026-08-31';
        $checkoutDate = '2026-09-01';
        $this->assign24HourNoBreakShift($user, $startDate);
        $this->importOpeningPunchWithNoCheckout($user, $startDate, $checkoutDate);
        $this->declareFullDaySuspensionOn($checkoutDate);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => '2026-08',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse($startDate)->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('08:00', $row['time_in_am']);
        $this->assertSame('SUSPENDED', $row['time_out_pm']);
        $this->assertSame(0, $row['undertime_minutes'], 'The checkout is covered by a full-day WorkSuspension declared for the day right after the period ends.');
        $this->assertStringNotContainsString('Missing OUT', $row['status_badge']);
    }

    // ── AttendanceMonitoringExportService ────────────────────────────────────

    /**
     * Unlike DtrController (4 of 6 sources already padded), NONE of Monitoring
     * Matrix's five isSlotCovered() next-day-fallback sources were padded past
     * the report's own calendar month - so this same boundary case was
     * completely uncovered here, for every one of ETA/Office Order/Travel
     * Order/full-day Leave/full-day Suspension, not just two of them.
     */
    public function test_monitoring_matrix_excludes_undertime_covered_by_next_day_leave_at_month_boundary(): void
    {
        $user = $this->createEmployee(['last_name' => 'Crossshiftmatrixleaveboundary']);
        $startDate = '2026-08-31';
        $checkoutDate = '2026-09-01';
        $this->assign24HourNoBreakShift($user, $startDate);
        $this->importOpeningPunchWithNoCheckout($user, $startDate, $checkoutDate);
        $this->fileFullDayLeaveOn($user, $checkoutDate);

        $departments = Department::where('Dept_id', $user->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere('user_id', $user->id);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['undertime_count'], 'A checkout covered by a next-day full-day Leave (filed one day past the report month) must not count toward undertime.');
        $this->assertSame(0, $row['undertime_minutes']);
        $this->assertSame(0, $row['unofficial_exit_count']);

        // Regression guard for the officialLeaveCount/remarks-corruption risk
        // considered and avoided while padding this fix: the leave falls on
        // Sep 1, entirely outside the August report, so it must never be
        // counted as an August leave day or misrendered as a "1-VL" remark
        // (which would collide with a real August 1st entry).
        $this->assertSame(0, $row['official_leave_count']);
        $this->assertStringNotContainsString('1-VL', $row['remarks']);
    }

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

    /**
     * Two compounding gaps, only visible together at the month's LAST day:
     * (1) the row-level pmOutCoveredNextDay fallback was gated `$day <
     * $daysInMonth`, so it could never fire for a shift starting on day 31 -
     * exactly the boundary case a recurring 24-on/24-off rotation hits every
     * month it runs; (2) even where the row-level fallback DID correctly
     * zero a row's own undertime, the sheet's printed grand total
     * (TOT_HRS/TOT_MIN, fillTotals()) had no equivalent logic at all and
     * still silently summed the raw, uncovered minutes - so an individual
     * row could show "Office Order" with zero undertime while the total at
     * the bottom of the same sheet still counted a full day's worth.
     */
    public function test_form48_export_shows_zero_grand_total_for_checkout_covered_on_the_last_day_of_the_month(): void
    {
        $user = $this->createEmployee(['last_name' => 'Crossshiftform48lastday']);
        $startDate = '2026-08-31';
        $checkoutDate = '2026-09-01';
        $this->assign24HourNoBreakShift($user, $startDate);
        $this->importOpeningPunchWithNoCheckout($user, $startDate, $checkoutDate);
        $this->fileOfficeOrderCoveringCheckoutDate($user, $checkoutDate);

        $exportService = app(Form48ExportService::class);
        $records = $exportService->buildRecords($user->id, '2026-08-01', '2026-08-31');
        $leaveMap = $exportService->buildLeaveMap($user->id, '2026-08-01', '2026-08-31');
        $etaMap = $exportService->buildEtaMap($user->id, '2026-08-01', '2026-08-31');
        $officeOrderMap = $exportService->buildOfficeOrderMap($user->id, '2026-08-01', '2026-08-31');

        $spreadsheet = IOFactory::load(storage_path('app/templates/form48.xls'));
        $sheet = $spreadsheet->getActiveSheet();

        $exportService->fill($sheet, $records, $user, 'August 2026', '2026-08-01', $leaveMap, $etaMap, [], [], [], [], $officeOrderMap);

        // Day 31 -> row DATA_ROW_OFFSET(11) + 31 = 42.
        $row = 42;
        $this->assertSame('08:00', $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('Office Order', $sheet->getCell("F{$row}")->getValue());
        $this->assertSame(0, $sheet->getCell("G{$row}")->getValue(), 'The row itself must show zero undertime hours even on the last day of the month.');
        $this->assertSame(0, $sheet->getCell("H{$row}")->getValue(), 'The row itself must show zero undertime minutes even on the last day of the month.');

        // The grand total (row 43) must agree with what every visible row
        // shows - not silently include this day's raw, uncovered minutes.
        $this->assertSame('', $sheet->getCell('G43')->getValue(), 'The printed grand total must not disagree with the zero-undertime row above it.');
        $this->assertSame('', $sheet->getCell('H43')->getValue());
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
