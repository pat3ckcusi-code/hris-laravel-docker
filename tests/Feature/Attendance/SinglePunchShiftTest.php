<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Dtr;
use App\Models\Eta;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance\AttendanceStatusResolver;
use App\Services\Attendance\LateCalculator;
use App\Services\Attendance\MatchResult;
use App\Services\Attendance\UndertimeCalculator;
use App\Services\Attendance\WeeklyPunchPairReconciliationService;
use App\Services\AttendanceMonitoringExportService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftAssignmentService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Single Punch Shift: employees realistically expected to punch only AM In
 * each workday (though AM Out/PM In/PM Out are still accepted and recorded
 * if punched). Only AM In is graded for lateness, against the shift's own
 * Time In; undertime is never charged. A day with genuinely zero punches is
 * Absent via the pre-existing "no dtrs row" convention (no new code needed
 * for that case - see the reconcile-sweep and zero-punch tests below). A day
 * where AM In specifically is missing but another slot was punched is
 * instead computed as Late, using the earliest of those punches as a
 * stand-in for AM In - real proof of presence, not Absent.
 */
class SinglePunchShiftTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-08-03'; // a Monday

    private function assignSinglePunchShift(User $user, ?string $from = '2026-08-01', ?array $daysOfWeek = null): Shift
    {
        $shift = Shift::create([
            'name' => 'Single Punch',
            'time_in' => '08:00', 'break_out' => '12:00', 'break_in' => '13:00', 'time_out' => '17:00',
            'is_active' => true, 'is_single_punch' => true,
        ]);

        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse($from), null, null, $daysOfWeek, null, false, 'am_in_only_graded'
        );

        return $shift;
    }

    private function punch(User $user, string $time, string $date = self::DATE): void
    {
        AttendanceLog::create([
            'user_id' => $user->id,
            'emp_no' => $user->EmpNo,
            'logdate' => $date,
            'logtime' => $time,
        ]);
    }

    private function recompute(User $user, string $date = self::DATE): void
    {
        app(PersonnelLogImportService::class)->recomputeDtr($user, $date, $date);
    }

    private function dtrFor(User $user, string $date = self::DATE): ?Dtr
    {
        return Dtr::where('employee_id', $user->id)->whereDate('date', $date)->first();
    }

    private function dtrPageRow(User $user, string $date = self::DATE): ?array
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

    // ── AM In present - only AM In is ever graded ──────────────────────────────

    public function test_am_in_on_time_is_present_with_zero_undertime_despite_missing_pm_out(): void
    {
        $user = $this->createEmployee(['last_name' => 'SpOnTime']);
        $this->assignSinglePunchShift($user);
        $this->punch($user, '07:58:00');
        $this->recompute($user);

        $dtr = $this->dtrFor($user);
        $this->assertNotNull($dtr);
        $this->assertSame('present', $dtr->status);
        $this->assertSame(0, $dtr->late_minutes);
        $this->assertSame(0, $dtr->undertime_minutes);
        $this->assertFalse((bool) $dtr->is_absent);
    }

    public function test_am_in_late_charges_late_minutes_with_zero_undertime(): void
    {
        $user = $this->createEmployee(['last_name' => 'SpLate']);
        $this->assignSinglePunchShift($user);
        $this->punch($user, '08:20:00');
        $this->recompute($user);

        $dtr = $this->dtrFor($user);
        $this->assertNotNull($dtr);
        $this->assertSame('late', $dtr->status);
        $this->assertSame(20, $dtr->late_minutes);
        $this->assertSame(0, $dtr->undertime_minutes);
    }

    public function test_late_pm_in_is_never_graded_when_am_in_is_on_time(): void
    {
        $user = $this->createEmployee(['last_name' => 'SpPmInLate']);
        $this->assignSinglePunchShift($user);
        $this->punch($user, '07:58:00'); // am_in, on time
        $this->punch($user, '13:45:00'); // pm_in, way late - must not count
        $this->recompute($user);

        $dtr = $this->dtrFor($user);
        $this->assertNotNull($dtr);
        $this->assertSame('present', $dtr->status);
        $this->assertSame(0, $dtr->late_minutes);
        $this->assertSame(0, $dtr->undertime_minutes);
    }

    // ── AM In missing, another slot punched - Late via stand-in, not Absent ────

    public function test_missing_am_in_with_late_pm_out_stands_in_and_is_computed_as_late(): void
    {
        Carbon::setTestNow(self::DATE.' 20:00:00');
        $user = $this->createEmployee(['last_name' => 'SpStandIn']);
        $this->assignSinglePunchShift($user);
        $this->punch($user, '17:30:00'); // pm_out only - no am_in at all
        $this->recompute($user);

        $dtr = $this->dtrFor($user);
        $row = $this->dtrPageRow($user);
        Carbon::setTestNow();

        $this->assertNotNull($dtr);
        $this->assertSame('late', $dtr->status);
        // 17:30 stands in for AM In against 08:00 workStart = 570 minutes late.
        $this->assertSame(570, $dtr->late_minutes);
        $this->assertSame(0, $dtr->undertime_minutes);
        $this->assertFalse((bool) $dtr->is_absent);

        $this->assertNotNull($row);
        $this->assertStringContainsString('Late', $row['status_badge']);
        $this->assertStringNotContainsString('Absent', $row['status_badge']);
    }

    public function test_missing_am_in_uses_the_earliest_of_multiple_other_punches(): void
    {
        Carbon::setTestNow(self::DATE.' 20:00:00');
        $user = $this->createEmployee(['last_name' => 'SpEarliest']);
        $this->assignSinglePunchShift($user);
        $this->punch($user, '13:05:00'); // pm_in
        $this->punch($user, '17:00:00'); // pm_out
        $this->recompute($user);

        $dtr = $this->dtrFor($user);
        Carbon::setTestNow();

        $this->assertNotNull($dtr);
        $this->assertSame('late', $dtr->status);
        // The earlier of the two (13:05), not the later (17:00), stands in: 08:00 -> 13:05 = 305 minutes.
        $this->assertSame(305, $dtr->late_minutes);
    }

    // ── Zero punches at all - Absent via the pre-existing "no row" convention ──

    public function test_zero_punches_produces_no_dtr_row_and_reads_as_absent(): void
    {
        Carbon::setTestNow(self::DATE.' 20:00:00');
        $user = $this->createEmployee(['last_name' => 'SpZeroPunch']);
        $this->assignSinglePunchShift($user);
        // Nothing punched at all - recompute() with zero AttendanceLog rows.
        $this->recompute($user);

        $dtr = $this->dtrFor($user);
        $row = $this->dtrPageRow($user);
        Carbon::setTestNow();

        $this->assertNull($dtr, 'A punchless day must never get a dtrs row - this is what makes it read as Absent everywhere, with no new is_absent-writing code.');
        $this->assertNotNull($row);
        $this->assertStringContainsString('Absent', $row['status_badge']);
    }

    public function test_zero_punches_with_approved_leave_shows_on_leave_not_absent(): void
    {
        Carbon::setTestNow(self::DATE.' 20:00:00');
        $user = $this->createEmployee(['last_name' => 'SpLeaveCovered']);
        $this->assignSinglePunchShift($user);

        $leave = LeaveRequest::create([
            'user_id' => $user->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => self::DATE,
            'end_date' => self::DATE,
            'reason' => 'Test',
            'status' => 'approved',
        ]);
        LeaveDate::create([
            'leave_request_id' => $leave->id,
            'leave_date' => self::DATE,
            'is_cancelled' => false,
            'days' => 1,
        ]);

        $this->recompute($user);
        $row = $this->dtrPageRow($user);
        Carbon::setTestNow();

        $this->assertNotNull($row);
        $this->assertStringContainsString('On Leave', $row['status_badge']);
        $this->assertStringNotContainsString('Absent', $row['status_badge']);
    }

    public function test_zero_punches_with_approved_eta_shows_official_travel_not_absent(): void
    {
        Carbon::setTestNow(self::DATE.' 20:00:00');
        $user = $this->createEmployee(['last_name' => 'SpEtaCovered']);
        $this->assignSinglePunchShift($user);

        Eta::create([
            'user_id' => $user->id,
            'departure_date' => self::DATE,
            'arrival_date' => self::DATE,
            'destination' => 'City Hall',
            'purpose' => 'Official business',
            'status' => 'approved',
        ]);

        $this->recompute($user);
        $row = $this->dtrPageRow($user);
        Carbon::setTestNow();

        $this->assertNotNull($row);
        $this->assertStringContainsString('On Official Travel', $row['status_badge']);
        $this->assertStringNotContainsString('Absent', $row['status_badge']);
    }

    // ── Edge case: only a stray, entirely out-of-window punch that day ─────────

    public function test_only_an_out_of_window_stray_punch_is_incomplete_not_late(): void
    {
        Carbon::setTestNow(self::DATE.' 20:00:00');
        $user = $this->createEmployee(['last_name' => 'SpStrayOnly']);
        $this->assignSinglePunchShift($user);
        $this->punch($user, '01:00:00'); // hours before any slot's eligibility window
        $this->recompute($user);

        $dtr = $this->dtrFor($user);
        Carbon::setTestNow();

        $this->assertNotNull($dtr);
        $this->assertSame('incomplete', $dtr->status);
        $this->assertFalse((bool) $dtr->is_absent);
        $this->assertNotEmpty($dtr->unmatched_logs);

        // Still correctly falls through to the Monitoring Matrix's existing
        // "fully blank day" absence classification, since none of the four
        // canonical slots hold a real punch.
        $dept = Department::where('Dept_id', $user->Dept_id)->first();
        $rows = app(AttendanceMonitoringExportService::class)->getRows(collect([$dept]), 8, 2026);
        $row = $rows->firstWhere('user_id', $user->id);
        $this->assertNotNull($row);
        $day = Carbon::parse(self::DATE)->day;
        $this->assertStringContainsString("{$day}-Absent (Unfiled Leave)", $row['remarks']);
    }

    // ── Monitoring Matrix tardiness counting ────────────────────────────────────

    public function test_monitoring_matrix_counts_the_stand_in_late_day_as_tardiness(): void
    {
        Carbon::setTestNow(self::DATE.' 20:00:00');
        $dept = Department::create([
            'DeptCode' => 'SPSHIFTTEST',
            'Dept_name' => 'Single Punch Shift Test Dept',
            'Designation' => 'Test',
        ]);
        $user = $this->createEmployee(['last_name' => 'SpMatrixLate', 'Dept_id' => $dept->Dept_id]);
        $this->assignSinglePunchShift($user);
        $this->punch($user, '17:30:00'); // pm_out only, stands in as a late AM In
        $this->recompute($user);

        $departments = Department::where('Dept_id', $dept->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, 8, 2026);
        $row = $rows->firstWhere('user_id', $user->id);
        Carbon::setTestNow();

        $this->assertNotNull($row);
        $this->assertSame(1, $row['tardiness_count']);
        $this->assertSame(570, $row['tardiness_minutes']);
        $this->assertSame(0, $row['undertime_count']);
        $this->assertSame(0, $row['undertime_minutes']);
    }

    // ── Never swept into the weekly Field Work reconciler ───────────────────────

    public function test_single_punch_shift_assignments_are_never_touched_by_weekly_reconciliation(): void
    {
        $user = $this->createEmployee(['last_name' => 'SpNoReconcile']);
        // Whole week, Mon-Fri (the default when $daysOfWeek is null).
        $this->assignSinglePunchShift($user);
        $this->punch($user, '08:00:00', '2026-08-03'); // Mon, on time
        // Tue-Thu deliberately unpunched.
        $this->punch($user, '07:55:00', '2026-08-07'); // Fri, on time
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $date) {
            $this->recompute($user, $date);
        }

        $before = [
            '2026-08-03' => $this->dtrFor($user, '2026-08-03')?->status,
            '2026-08-04' => $this->dtrFor($user, '2026-08-04'),
            '2026-08-05' => $this->dtrFor($user, '2026-08-05'),
            '2026-08-06' => $this->dtrFor($user, '2026-08-06'),
            '2026-08-07' => $this->dtrFor($user, '2026-08-07')?->status,
        ];

        $result = app(WeeklyPunchPairReconciliationService::class)->reconcile(Carbon::parse('2026-08-08'));

        $this->assertSame(0, $result['weeks_reconciled'], 'A daily Single Punch Shift week must never be recognized as a Field Work pair week.');
        $this->assertSame('present', $before['2026-08-03']);
        $this->assertNull($before['2026-08-04'], 'Tue is a genuine, independent absence - not voided/rewritten by the reconciler.');
        $this->assertNull($before['2026-08-05']);
        $this->assertNull($before['2026-08-06']);
        $this->assertSame('present', $before['2026-08-07'], 'Fri must keep its own real status - never voided the way a Field Work pair week would.');

        // Confirm the reconciler made no changes at all to any of these dates.
        $this->assertSame($before['2026-08-03'], $this->dtrFor($user, '2026-08-03')?->status);
        $this->assertNull($this->dtrFor($user, '2026-08-04'));
        $this->assertSame($before['2026-08-07'], $this->dtrFor($user, '2026-08-07')?->status);
    }

    // ── Controller forces punch_requirement server-side, both add and edit ─────

    public function test_controller_forces_punch_requirement_on_assignment_regardless_of_submitted_value(): void
    {
        $tk = $this->createTimeKeeper();
        $employee = $this->createEmployee(['last_name' => 'SpControllerAssign']);
        $shift = Shift::create([
            'name' => 'Single Punch Via Controller',
            'time_in' => '08:00', 'break_out' => '12:00', 'break_in' => '13:00', 'time_out' => '17:00',
            'is_active' => true, 'is_single_punch' => true,
        ]);

        // A stale/tampered edit-form dropdown submits 'both' - the controller
        // must override this to 'am_in_only_graded' regardless, since the
        // shift itself is flagged is_single_punch.
        $this->actingAs($tk)
            ->put(route('attendance.schedules.update', $employee), [
                'form_type' => 'add',
                'shift_id' => $shift->id,
                'effective_from' => '2026-08-01',
                'effective_until' => '2026-12-31',
                'punch_requirement' => 'both',
            ])
            ->assertRedirect();

        $assignment = ShiftAssignment::where('user_id', $employee->id)->where('shift_id', $shift->id)->firstOrFail();
        $this->assertSame('am_in_only_graded', $assignment->punch_requirement);
    }

    // ── Isolated calculator/resolver coverage (bypasses AttendanceMatcher's own
    //    eligibility-window matching, so the stand-in logic can be exercised
    //    precisely, including a defensive edge case unreachable through a real
    //    punch - see the comment on test_stand_in_before_cutoff_yields_zero_late) ──

    private function isolatedSchedule(): WorkSchedule
    {
        return new WorkSchedule('08:00', '13:00', '17:00', '12:00', '14:00', false, false, false, 'am_in_only_graded');
    }

    public function test_late_calculator_ignores_pm_in_lateness_entirely(): void
    {
        $result = new MatchResult([
            'am_in' => Carbon::parse(self::DATE.' 07:58:00'),
            'am_out' => null,
            'pm_in' => Carbon::parse(self::DATE.' 13:45:00'),
            'pm_out' => null,
        ], []);

        $late = (new LateCalculator)->minutes($result, self::DATE, $this->isolatedSchedule());
        $this->assertSame(0, $late);
    }

    /**
     * Defensive-only case: the matcher will always prefer assigning a
     * near-workStart punch to am_in itself rather than am_out, so this exact
     * shape (am_in null, a stand-in candidate at/before workStart) is not
     * reachable through the real matching pipeline - but LateCalculator's
     * substitution must still behave correctly if it ever were.
     */
    public function test_stand_in_before_cutoff_yields_zero_late(): void
    {
        $result = new MatchResult([
            'am_in' => null,
            'am_out' => Carbon::parse(self::DATE.' 07:50:00'),
            'pm_in' => null,
            'pm_out' => null,
        ], []);

        $late = (new LateCalculator)->minutes($result, self::DATE, $this->isolatedSchedule());
        $this->assertSame(0, $late);
    }

    public function test_undertime_calculator_always_returns_zero_regardless_of_slots(): void
    {
        $result = new MatchResult([
            'am_in' => Carbon::parse(self::DATE.' 08:00:00'),
            'am_out' => Carbon::parse(self::DATE.' 11:00:00'),
            'pm_in' => Carbon::parse(self::DATE.' 13:00:00'),
            'pm_out' => Carbon::parse(self::DATE.' 15:00:00'), // 2 hours early - would normally be undertime
        ], []);

        $undertime = (new UndertimeCalculator)->minutes($result, self::DATE, $this->isolatedSchedule());
        $this->assertSame(0, $undertime);
    }

    public function test_status_resolver_scores_late_when_am_in_missing_but_another_slot_punched(): void
    {
        $result = new MatchResult(['am_in' => null, 'am_out' => null, 'pm_in' => null, 'pm_out' => Carbon::parse(self::DATE.' 17:30:00')], []);
        $status = (new AttendanceStatusResolver)->resolve($result, 570, 0, false, 'am_in_only_graded');
        $this->assertSame(AttendanceStatus::Late, $status);
    }

    public function test_status_resolver_returns_incomplete_when_nothing_matched_at_all(): void
    {
        $result = new MatchResult(['am_in' => null, 'am_out' => null, 'pm_in' => null, 'pm_out' => null], []);
        $status = (new AttendanceStatusResolver)->resolve($result, 0, 0, false, 'am_in_only_graded');
        $this->assertSame(AttendanceStatus::Incomplete, $status);
    }
}
