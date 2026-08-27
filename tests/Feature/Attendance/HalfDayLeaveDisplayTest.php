<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\Dtr;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceMonitoringExportService;
use App\Services\Form48ExportService;
use App\Services\ShiftAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * A half-day (0.5-day) approved leave must not hide real punches for the
 * half of the day actually worked - reported incident: an employee with a
 * 0.5-day SL and a real biometric log for that date had every DTR slot show
 * "SL", discarding her real punch times. DtrController::data(),
 * Form48ExportService, and AttendanceMonitoringExportService each
 * independently treated any approved leave as covering the whole day
 * regardless of its own 'days' value. Since there is no AM/PM column
 * anywhere for a half-day leave, "which half" is inferred from the punch
 * data itself: a slot with a real punch always wins, a slot with none is
 * treated as leave-covered.
 *
 * A full-day (days >= 1) leave was originally meant to override every slot
 * unconditionally regardless of any incidental punch - later changed so a
 * real biometric punch always takes priority first, even on a full-day
 * leave date (matching the stated rule: biometric punches are shown before
 * any status label, including Leave). Late/undertime still stay zeroed on a
 * full-day leave day regardless, since no work obligation exists that day.
 */
class HalfDayLeaveDisplayTest extends TestCase
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

    private function fileHalfDayLeave(User $user, string $leaveType = 'Sick Leave', float $days = 0.5): void
    {
        $leave = LeaveRequest::create([
            'user_id' => $user->id,
            'leave_type' => $leaveType,
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'reason' => 'Test',
            'status' => 'approved',
        ]);
        LeaveDate::create([
            'leave_request_id' => $leave->id,
            'leave_date' => self::DATE,
            'is_cancelled' => false,
            'days' => $days,
        ]);
    }

    // ── DtrController::data() ────────────────────────────────────────────────

    public function test_dtr_page_shows_real_pm_punches_for_am_half_day_leave(): void
    {
        $user = $this->createEmployee(['last_name' => 'Amleave']);
        $this->assignEightToFiveShift($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => null,
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '15:55:00',
            'late_minutes' => 0,
            'undertime_minutes' => 65,
            'is_absent' => false,
        ]);
        $this->fileHalfDayLeave($user);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => '2026-08',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse(self::DATE)->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('SL', $row['time_in_am']);
        $this->assertSame('SL', $row['time_out_am']);
        $this->assertSame('13:00', $row['time_in_pm'], 'Real PM In punch must not be hidden behind the leave code.');
        $this->assertSame('15:55', $row['time_out_pm'], 'Real PM Out punch must not be hidden behind the leave code.');
        $this->assertSame(0, $row['late_minutes'], 'AM half has no punch - nothing to be late for.');
        $this->assertSame(65, $row['undertime_minutes'], 'Genuine undertime on the worked PM half must not be zeroed just because AM is on leave.');
    }

    public function test_dtr_page_shows_real_am_punches_and_late_minutes_for_pm_half_day_leave(): void
    {
        $user = $this->createEmployee(['last_name' => 'Pmleave']);
        $this->assignEightToFiveShift($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:20:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 20,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);
        $this->fileHalfDayLeave($user);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => '2026-08',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse(self::DATE)->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('08:20', $row['time_in_am'], 'Real AM In punch must not be hidden behind the leave code.');
        $this->assertSame('12:00', $row['time_out_am'], 'Real AM Out punch must not be hidden behind the leave code.');
        $this->assertSame('SL', $row['time_in_pm']);
        $this->assertSame('SL', $row['time_out_pm']);
        $this->assertSame(20, $row['late_minutes'], 'Genuine tardiness on the worked AM half must not be zeroed just because PM is on leave.');
        $this->assertSame(0, $row['undertime_minutes'], 'PM half has no punch - nothing to be undertime for.');
    }

    public function test_dtr_page_full_day_leave_shows_real_punch_when_present(): void
    {
        $user = $this->createEmployee(['last_name' => 'Fulldayleave']);
        $this->assignEightToFiveShift($user);

        // Incidental punches despite a full-day leave being on file - a real
        // biometric punch always takes priority and must be shown, though the
        // day still charges zero late/undertime since no work obligation
        // exists on an approved full-day leave.
        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:20:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '15:55:00',
            'late_minutes' => 20,
            'undertime_minutes' => 65,
            'is_absent' => false,
        ]);
        $this->fileHalfDayLeave($user, 'Vacation Leave', 1.0);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => '2026-08',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse(self::DATE)->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('08:20', $row['time_in_am'], 'A real punch must win over the leave code, even on a full-day leave date.');
        $this->assertSame('12:00', $row['time_out_am']);
        $this->assertSame('13:00', $row['time_in_pm']);
        $this->assertSame('15:55', $row['time_out_pm']);
        $this->assertSame(0, $row['late_minutes'], 'No work obligation exists on an approved full-day leave, so no penalty applies despite the incidental punch.');
        $this->assertSame(0, $row['undertime_minutes']);
    }

    public function test_dtr_page_full_day_leave_with_no_punches_still_shows_leave_code(): void
    {
        $user = $this->createEmployee(['last_name' => 'Fulldayleaveempty']);
        $this->assignEightToFiveShift($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);
        $this->fileHalfDayLeave($user, 'Vacation Leave', 1.0);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $user->id,
                'dtr_type' => 'monthly',
                'month' => '2026-08',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse(self::DATE)->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('VL', $row['time_in_am'], 'With no real punch to show, the leave code still fills the slot as before.');
        $this->assertSame('VL', $row['time_out_am']);
        $this->assertSame('VL', $row['time_in_pm']);
        $this->assertSame('VL', $row['time_out_pm']);
        $this->assertSame(0, $row['late_minutes']);
        $this->assertSame(0, $row['undertime_minutes']);
    }

    // ── Form48ExportService ──────────────────────────────────────────────────

    public function test_form48_export_shows_real_pm_punches_for_am_half_day_leave(): void
    {
        $user = $this->createEmployee(['last_name' => 'Amleaveform48']);
        $this->assignEightToFiveShift($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => null,
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '15:55:00',
            'late_minutes' => 0,
            'undertime_minutes' => 65,
            'is_absent' => false,
        ]);
        $this->fileHalfDayLeave($user);

        $exportService = app(Form48ExportService::class);
        $records = $exportService->buildRecords($user->id, '2026-08-01', '2026-08-31');
        $leaveMap = $exportService->buildLeaveMap($user->id, '2026-08-01', '2026-08-31');

        $templatePath = storage_path('app/templates/form48.xls');
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $exportService->fill($sheet, $records, $user, 'August 2026', '2026-08-01', $leaveMap);

        // Day 5 -> row DATA_ROW_OFFSET(11) + 5 = 16; column group 0 = C/D/E/F/G/H.
        $row = 16;
        $this->assertSame('SL', $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('SL', $sheet->getCell("D{$row}")->getValue());
        $this->assertSame('13:00', $sheet->getCell("E{$row}")->getValue(), 'Real PM In punch must not be hidden behind the leave code.');
        $this->assertSame('15:55', $sheet->getCell("F{$row}")->getValue(), 'Real PM Out punch must not be hidden behind the leave code.');
        $this->assertSame(1, $sheet->getCell("G{$row}")->getValue(), 'Genuine undertime (65 min) on the worked PM half must not be suppressed.');
        $this->assertSame(5, $sheet->getCell("H{$row}")->getValue());
    }

    public function test_form48_export_full_day_leave_shows_real_punch_when_present(): void
    {
        $user = $this->createEmployee(['last_name' => 'Fulldayleaveform48']);
        $this->assignEightToFiveShift($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:20:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '15:55:00',
            'late_minutes' => 20,
            'undertime_minutes' => 65,
            'is_absent' => false,
        ]);
        $this->fileHalfDayLeave($user, 'Vacation Leave', 1.0);

        $exportService = app(Form48ExportService::class);
        $records = $exportService->buildRecords($user->id, '2026-08-01', '2026-08-31');
        $leaveMap = $exportService->buildLeaveMap($user->id, '2026-08-01', '2026-08-31');

        $templatePath = storage_path('app/templates/form48.xls');
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $exportService->fill($sheet, $records, $user, 'August 2026', '2026-08-01', $leaveMap);

        // A real punch always wins over the leave code, even on a full-day
        // leave date - no merge happens once any real punch exists. Zero
        // late/undertime still applies since no work obligation exists on an
        // approved full-day leave.
        $row = 16;
        $this->assertSame('08:20', $sheet->getCell("C{$row}")->getValue(), 'A real punch must win over the leave code, even on a full-day leave date.');
        $this->assertSame('12:00', $sheet->getCell("D{$row}")->getValue());
        $this->assertSame('13:00', $sheet->getCell("E{$row}")->getValue());
        $this->assertSame('15:55', $sheet->getCell("F{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    public function test_form48_export_full_day_leave_with_no_punches_still_merges_and_shows_leave_code(): void
    {
        $user = $this->createEmployee(['last_name' => 'Fulldayleaveform48empty']);
        $this->assignEightToFiveShift($user);

        $this->fileHalfDayLeave($user, 'Vacation Leave', 1.0);

        $exportService = app(Form48ExportService::class);
        $records = $exportService->buildRecords($user->id, '2026-08-01', '2026-08-31');
        $leaveMap = $exportService->buildLeaveMap($user->id, '2026-08-01', '2026-08-31');

        $templatePath = storage_path('app/templates/form48.xls');
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        $exportService->fill($sheet, $records, $user, 'August 2026', '2026-08-01', $leaveMap);

        // With no real punch to preserve, C16:F16 still merge into a single
        // leave-code cell exactly as before this fix.
        $row = 16;
        $this->assertTrue($sheet->getCell("C{$row}")->isMergeRangeValueCell());
        $this->assertSame('VL', $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("G{$row}")->getValue());
        $this->assertSame('', $sheet->getCell("H{$row}")->getValue());
    }

    // ── AttendanceMonitoringExportService ────────────────────────────────────

    public function test_monitoring_matrix_counts_undertime_on_worked_pm_half_of_am_half_day_leave(): void
    {
        $user = $this->createEmployee(['last_name' => 'Amleavematrix']);
        $this->assignEightToFiveShift($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => null,
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '15:55:00',
            'late_minutes' => 0,
            'undertime_minutes' => 65,
            'is_absent' => false,
        ]);
        $this->fileHalfDayLeave($user);

        $departments = Department::where('Dept_id', $user->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere(fn ($r) => str_contains($r['name'], $user->last_name));

        $this->assertNotNull($row);
        $this->assertSame(1, $row['undertime_count'], 'Real undertime on the worked PM half must not be suppressed just because AM is on leave.');
        $this->assertSame(65, $row['undertime_minutes']);
        $this->assertSame(0, $row['tardiness_count']);
    }

    public function test_monitoring_matrix_full_day_leave_still_suppresses_tardiness_and_undertime(): void
    {
        $user = $this->createEmployee(['last_name' => 'Fulldayleavematrix']);
        $this->assignEightToFiveShift($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:20:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '15:55:00',
            'late_minutes' => 20,
            'undertime_minutes' => 65,
            'is_absent' => false,
        ]);
        $this->fileHalfDayLeave($user, 'Vacation Leave', 1.0);

        $departments = Department::where('Dept_id', $user->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere(fn ($r) => str_contains($r['name'], $user->last_name));

        $this->assertNotNull($row);
        $this->assertSame(0, $row['undertime_count']);
        $this->assertSame(0, $row['tardiness_count']);
        $this->assertSame(0, $row['undertime_minutes']);
        $this->assertSame(0, $row['tardiness_minutes']);
    }
}
