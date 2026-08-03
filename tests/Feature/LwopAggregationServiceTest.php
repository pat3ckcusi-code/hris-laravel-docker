<?php

namespace Tests\Feature;

use App\Console\Commands\ProcessMonthlyLeaveCredits;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\EmployeeShiftSchedule;
use App\Models\Eta;
use App\Models\Holiday;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\Locator;
use App\Models\MonthlyAttendance;
use App\Models\Shift;
use App\Services\LwopAggregationService;
use App\Services\ShiftAssignmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Verifies days_present / abs_wop_days aggregation against the CSC Omnibus
 * Rules on Leave worked examples (30-day month, non-illness LWOP reduces
 * days present, illness LWOP does not, month-boundary proration doesn't
 * double-count).
 */
class LwopAggregationServiceTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    /**
     * Seed Dtr "present" rows for every weekday in [start, end], skipping any dates in
     * $exceptDates (days covered by a leave request in the same test -- realistically
     * an employee on leave that day wouldn't have punched in).
     */
    private function seedFullAttendance($user, string $start, string $end, array $exceptDates = []): void
    {
        $except = array_flip($exceptDates);
        $cursor = Carbon::parse($start);
        $endDate = Carbon::parse($end);

        while ($cursor->lessThanOrEqualTo($endDate)) {
            $dateStr = $cursor->toDateString();
            if ($cursor->isWeekday() && ! isset($except[$dateStr])) {
                Dtr::create(['employee_id' => $user->id, 'date' => $dateStr, 'is_absent' => false]);
            }
            $cursor->addDay();
        }
    }

    public function test_no_lwop_gives_full_days_present(): void
    {
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);
        $this->seedFullAttendance($emp, '2026-04-01', '2026-04-30');

        $aggregate = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);

        $this->assertSame(30.0, $aggregate['days_present']);
        $this->assertSame(0.0, $aggregate['abs_wop_days']);
    }

    public function test_five_days_non_illness_lwop_matches_csc_example_one(): void
    {
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);
        $this->seedFullAttendance($emp, '2026-04-01', '2026-04-30', ['2026-04-01', '2026-04-02', '2026-04-03']);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-05',
            'reason' => 'Test',
            'status' => 'approved',
            'lwop_days' => 5,
        ]);
        foreach (['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-04', '2026-04-05'] as $date) {
            LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => $date, 'is_cancelled' => false]);
        }

        $aggregate = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);
        $this->assertSame(25.0, $aggregate['days_present']);
        $this->assertSame(5.0, $aggregate['abs_wop_days']);

        app(ProcessMonthlyLeaveCredits::class)->processBatch(2026, 4, $emp->id, false);

        $attendance = MonthlyAttendance::where('user_id', $emp->id)->where('year', 2026)->where('month', 4)->first();
        $this->assertEquals(1.042, (float) $attendance->computed_vl);
        $this->assertEquals(1.042, (float) $attendance->computed_sl);
    }

    public function test_ten_days_non_illness_lwop_matches_csc_example_two(): void
    {
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);
        $this->seedFullAttendance($emp, '2026-04-01', '2026-04-30', [
            '2026-04-01', '2026-04-02', '2026-04-03', '2026-04-06', '2026-04-07', '2026-04-08', '2026-04-09', '2026-04-10',
        ]);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-10',
            'reason' => 'Test',
            'status' => 'approved',
            'lwop_days' => 10,
        ]);
        foreach (['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-04', '2026-04-05', '2026-04-06', '2026-04-07', '2026-04-08', '2026-04-09', '2026-04-10'] as $date) {
            LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => $date, 'is_cancelled' => false]);
        }

        app(ProcessMonthlyLeaveCredits::class)->processBatch(2026, 4, $emp->id, false);

        $attendance = MonthlyAttendance::where('user_id', $emp->id)->where('year', 2026)->where('month', 4)->first();
        $this->assertEquals(0.833, (float) $attendance->computed_vl);
        $this->assertEquals(0.833, (float) $attendance->computed_sl);
    }

    public function test_full_month_non_illness_lwop_earns_zero_credit_not_the_one_day_floor(): void
    {
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'reason' => 'Test',
            'status' => 'approved',
            'lwop_days' => 30,
        ]);
        $cursor = Carbon::parse('2026-04-01');
        while ($cursor->lessThanOrEqualTo(Carbon::parse('2026-04-30'))) {
            LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => $cursor->toDateString(), 'is_cancelled' => false]);
            $cursor->addDay();
        }

        $aggregate = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);
        $this->assertSame(0.0, $aggregate['days_present'], 'A full-month non-illness LWOP leave should zero out days present.');

        app(ProcessMonthlyLeaveCredits::class)->processBatch(2026, 4, $emp->id, false);

        $attendance = MonthlyAttendance::where('user_id', $emp->id)->where('year', 2026)->where('month', 4)->first();
        $this->assertEquals(0.0, (float) $attendance->computed_vl, 'Zero days present must earn zero credit, not the days_present=1 table floor (0.042).');
        $this->assertEquals(0.0, (float) $attendance->computed_sl);
    }

    public function test_illness_lwop_does_not_reduce_days_present(): void
    {
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);
        $this->seedFullAttendance($emp, '2026-04-01', '2026-04-30', ['2026-04-01', '2026-04-02', '2026-04-03']);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'SL',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-05',
            'reason' => 'Illness',
            'status' => 'approved',
            'lwop_days' => 5,
        ]);
        foreach (['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-04', '2026-04-05'] as $date) {
            LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => $date, 'is_cancelled' => false]);
        }

        $aggregate = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);

        $this->assertSame(30.0, $aggregate['days_present'], 'Illness LWOP must not reduce days present.');
        $this->assertSame(0.0, $aggregate['abs_wop_days']);
    }

    public function test_sanggunian_member_earns_credit_same_as_a_permanent_employee(): void
    {
        $emp = $this->createEmployee(['is_sanggunian_member' => true]);
        $this->createLeaveBalance($emp);
        $this->seedFullAttendance($emp, '2026-04-01', '2026-04-30');

        app(ProcessMonthlyLeaveCredits::class)->processBatch(2026, 4, $emp->id, false);

        $attendance = MonthlyAttendance::where('user_id', $emp->id)->where('year', 2026)->where('month', 4)->first();
        $this->assertEquals(1.25, (float) $attendance->computed_vl, 'Sanggunian members must earn full credit like any other employee, not be zeroed out.');
        $this->assertEquals(1.25, (float) $attendance->computed_sl);
    }

    public function test_new_hire_mid_month_prorates_the_30_day_baseline(): void
    {
        // April has 30 calendar days; hired on the 16th -> 15 days of actual service.
        $emp = $this->createEmployee(['date_hired' => '2026-04-16']);
        $this->createLeaveBalance($emp);
        $this->seedFullAttendance($emp, '2026-04-16', '2026-04-30');

        $aggregate = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);

        $this->assertSame(15.0, $aggregate['days_present']);
        $this->assertSame(0.0, $aggregate['abs_wop_days']);
    }

    public function test_hired_on_first_of_month_gets_no_proration(): void
    {
        $emp = $this->createEmployee(['date_hired' => '2026-04-01']);
        $this->createLeaveBalance($emp);
        $this->seedFullAttendance($emp, '2026-04-01', '2026-04-30');

        $aggregate = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);

        $this->assertSame(30.0, $aggregate['days_present']);
    }

    public function test_not_yet_hired_this_month_earns_no_credit(): void
    {
        $emp = $this->createEmployee(['date_hired' => '2026-05-01']);
        $this->createLeaveBalance($emp);

        $aggregate = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);

        $this->assertSame(0.0, $aggregate['days_present']);
        $this->assertSame(0.0, $aggregate['abs_wop_days']);
    }

    public function test_new_hire_with_lwop_combines_both_prorations(): void
    {
        // Hired Apr 16 (15 service days), then 5 non-illness LWOP days within that window.
        $emp = $this->createEmployee(['date_hired' => '2026-04-16']);
        $this->createLeaveBalance($emp);
        $this->seedFullAttendance($emp, '2026-04-16', '2026-04-30', [
            '2026-04-20', '2026-04-21', '2026-04-22', '2026-04-23', '2026-04-24',
        ]);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => '2026-04-20',
            'end_date' => '2026-04-24',
            'reason' => 'Test',
            'status' => 'approved',
            'lwop_days' => 5,
        ]);
        foreach (['2026-04-20', '2026-04-21', '2026-04-22', '2026-04-23', '2026-04-24'] as $date) {
            LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => $date, 'is_cancelled' => false]);
        }

        $aggregate = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);

        $this->assertSame(10.0, $aggregate['days_present'], '15 service days minus 5 LWOP days.');
        $this->assertSame(5.0, $aggregate['abs_wop_days']);
    }

    public function test_request_spanning_two_months_prorates_without_double_counting(): void
    {
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);
        $this->seedFullAttendance($emp, '2026-04-01', '2026-04-30', ['2026-04-28', '2026-04-29', '2026-04-30']);
        $this->seedFullAttendance($emp, '2026-05-01', '2026-05-31', ['2026-05-01']);

        // Apr 28 - May 3: 6 total days, all LWOP. April overlap = 3 days (28,29,30),
        // May overlap = 3 days (1,2,3).
        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => '2026-04-28',
            'end_date' => '2026-05-03',
            'reason' => 'Test',
            'status' => 'approved',
            'lwop_days' => 6,
        ]);
        foreach (['2026-04-28', '2026-04-29', '2026-04-30', '2026-05-01', '2026-05-02', '2026-05-03'] as $date) {
            LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => $date, 'is_cancelled' => false]);
        }

        $april = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);
        $may = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 5);

        $this->assertSame(3.0, $april['abs_wop_days'], 'April should only get its 3 overlapping days, not the full 6.');
        $this->assertSame(3.0, $may['abs_wop_days'], 'May should only get its 3 overlapping days, not the full 6.');
        $this->assertSame(6.0, $april['abs_wop_days'] + $may['abs_wop_days'], 'Split must reconstruct the original total.');
    }

    // ──────────────────────────────────────────────
    // True AWOL detection (classifyWorkdays)
    // ──────────────────────────────────────────────

    public function test_weekday_with_no_attendance_or_coverage_is_awol(): void
    {
        // 2026-04-01 is a Wednesday.
        $emp = $this->createEmployee();

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-01')
        );

        $this->assertTrue($classified->get('2026-04-01'));
    }

    public function test_dtr_exempt_employee_is_never_awol(): void
    {
        // Same shape as the previous test (no Dtr row, no coverage) but dtr_exempt --
        // must not be flagged, since they never punch a biometric device at all.
        $emp = $this->createEmployee(['dtr_exempt' => true]);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-01')
        );

        $this->assertTrue($classified->isEmpty());
    }

    public function test_attended_day_is_not_awol(): void
    {
        $emp = $this->createEmployee();
        Dtr::create(['employee_id' => $emp->id, 'date' => '2026-04-01', 'is_absent' => false]);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-01')
        );

        $this->assertFalse($classified->get('2026-04-01'));
    }

    public function test_day_covered_by_approved_leave_is_not_awol(): void
    {
        $emp = $this->createEmployee();
        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-01',
            'reason' => 'Test',
            'status' => 'approved',
        ]);
        LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => '2026-04-01', 'is_cancelled' => false]);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-01')
        );

        $this->assertFalse($classified->get('2026-04-01'));
    }

    public function test_day_covered_by_dtr_excuse_is_not_awol(): void
    {
        $emp = $this->createEmployee();
        DtrExcuse::create([
            'user_id' => $emp->id,
            'date' => '2026-04-01',
            'excuse_type' => 'power_interruption',
            'is_full_day' => true,
            'reason' => 'Brownout',
        ]);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-01')
        );

        $this->assertFalse($classified->get('2026-04-01'));
    }

    public function test_day_covered_by_approved_locator_is_not_awol(): void
    {
        $emp = $this->createEmployee();
        Locator::create([
            'user_id' => $emp->id,
            'application_type' => 'Field Work',
            'location' => 'City Hall',
            'travel_date' => '2026-04-01',
            'intended_departure_time' => '08:00',
            'intended_arrival_time' => '17:00',
            'detail' => 'Field work',
            'status' => 'approved',
        ]);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-01')
        );

        $this->assertFalse($classified->get('2026-04-01'));
    }

    public function test_day_covered_by_approved_eta_is_not_awol(): void
    {
        $emp = $this->createEmployee();
        Eta::create([
            'user_id' => $emp->id,
            'departure_date' => '2026-04-01',
            'arrival_date' => '2026-04-01',
            'destination' => 'City Hall',
            'purpose' => 'Official business',
            'status' => 'approved',
        ]);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-01')
        );

        $this->assertFalse($classified->get('2026-04-01'));
    }

    public function test_holiday_is_excluded_from_classification(): void
    {
        $emp = $this->createEmployee();
        Holiday::create(['title' => 'Test Holiday', 'holiday_date' => '2026-04-01', 'type' => 'Regular']);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-01')
        );

        $this->assertFalse($classified->has('2026-04-01'), 'A holiday is not a scheduled workday, so it should not appear at all.');
    }

    public function test_rest_day_is_excluded_from_classification(): void
    {
        $emp = $this->createEmployee();
        EmployeeShiftSchedule::create([
            'user_id' => $emp->id,
            'date' => '2026-04-01',
            'shift_id' => null,
            'type' => 'rest_day',
        ]);

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-01')
        );

        $this->assertFalse($classified->has('2026-04-01'));
    }

    public function test_weekend_is_excluded_from_classification(): void
    {
        // 2026-04-04 is a Saturday.
        $emp = $this->createEmployee();

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-04'), Carbon::parse('2026-04-04')
        );

        $this->assertFalse($classified->has('2026-04-04'));
    }

    public function test_mon_sat_shift_counts_saturday_as_workday_and_sunday_as_rest(): void
    {
        // 2026-04-04 is a Saturday, 2026-04-05 is a Sunday.
        $shift = Shift::create([
            'name' => 'Mon-Sat',
            'time_in' => '08:00',
            'break_out' => '12:00',
            'break_in' => '13:00',
            'time_out' => '17:00',
        ]);
        $emp = $this->createEmployee();
        app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-01-01'), null, null, null, [1, 2, 3, 4, 5, 6]
        );

        $classified = app(LwopAggregationService::class)->classifyWorkdays(
            $emp, Carbon::parse('2026-04-04'), Carbon::parse('2026-04-05')
        );

        $this->assertTrue($classified->has('2026-04-04'), 'Saturday must be a workday for a Mon-Sat shift.');
        $this->assertTrue($classified->get('2026-04-04'), 'An unpunched Saturday workday is AWOL.');
        $this->assertFalse($classified->has('2026-04-05'), 'Sunday stays excluded even for a Mon-Sat shift.');
    }

    public function test_awol_is_tracked_separately_and_does_not_reduce_days_present_or_credit(): void
    {
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp);
        // Full attendance for the month except 2026-04-01 (left as the true AWOL day)
        // and the LWOP-covered range below.
        $this->seedFullAttendance($emp, '2026-04-01', '2026-04-30', [
            '2026-04-01', '2026-04-10', '2026-04-13', '2026-04-14',
        ]);

        // 1 true AWOL day (2026-04-01, no attendance/coverage) + a separate 5-day
        // non-illness LWOP request later in the month. The LWOP request's own dates
        // are covered by LeaveDate rows (leave was filed, just exceeded balance) so
        // they don't also get double-flagged as AWOL.
        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => '2026-04-10',
            'end_date' => '2026-04-14',
            'reason' => 'Test',
            'status' => 'approved',
            'lwop_days' => 5,
        ]);
        foreach (['2026-04-10', '2026-04-11', '2026-04-12', '2026-04-13', '2026-04-14'] as $date) {
            LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => $date, 'is_cancelled' => false]);
        }

        $aggregate = app(LwopAggregationService::class)->computeForMonth($emp, 2026, 4);

        // 30 baseline - 5 filed LWOP = 25. The 1 AWOL day is reported separately
        // (awol_days) but does not reduce days_present/abs_wop_days -- only a
        // deliberate, on-record filed LWOP request does.
        $this->assertSame(25.0, $aggregate['days_present']);
        $this->assertSame(5.0, $aggregate['abs_wop_days']);
        $this->assertSame(1.0, $aggregate['awol_days']);
    }

    // ──────────────────────────────────────────────
    // Current AWOL streak & semester episode count
    // ──────────────────────────────────────────────

    public function test_current_streak_counts_consecutive_workdays_across_a_weekend(): void
    {
        $emp = $this->createEmployee();
        // Attended through end of March so the streak has a clear break before April.
        $this->seedFullAttendance($emp, '2026-03-01', '2026-03-31');

        // Apr 1 (Wed) through Apr 8 (Wed) = 6 workdays, skipping the Apr 4-5 weekend.
        // No Dtr rows and nothing covering any of them.
        $streak = app(LwopAggregationService::class)->computeCurrentAwolStreak($emp, Carbon::parse('2026-04-09'));

        $this->assertSame(6, $streak['streak']);
        $this->assertSame('2026-04-01', $streak['streak_started_on']);
        $this->assertFalse($streak['capped']);
    }

    public function test_attended_day_in_the_middle_breaks_the_streak(): void
    {
        $emp = $this->createEmployee();
        Dtr::create(['employee_id' => $emp->id, 'date' => '2026-04-02', 'is_absent' => false]);

        $streak = app(LwopAggregationService::class)->computeCurrentAwolStreak($emp, Carbon::parse('2026-04-09'));

        // Only Apr 3, 6, 7, 8 remain unbroken after the Apr 2 attendance.
        $this->assertSame(4, $streak['streak']);
    }
}
