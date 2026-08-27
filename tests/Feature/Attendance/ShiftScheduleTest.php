<?php

namespace Tests\Feature\Attendance;

use App\Console\Commands\SyncShiftAssignmentCache;
use App\Jobs\BulkShiftRecomputeJob;
use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\EmployeeShiftSchedule;
use App\Models\Eta;
use App\Models\Holiday;
use App\Models\HRAuditTrail;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\Locator;
use App\Models\OicAssignment;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftManagementGrant;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Services\AttendanceMonitoringExportService;
use App\Services\DtrPunchResolver;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftAssignmentService;
use App\Services\ShiftPunchGrouper;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Work-shift templates: night shifts cross midnight, their two-calendar-day
 * punches fold onto the start date, penalties score correctly, and only the
 * Time Keeper / HR Manager may manage shifts and assignments.
 */
class ShiftScheduleTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function dayShift(): WorkSchedule
    {
        return new WorkSchedule('08:00', '13:00', '17:00', '11:00', '14:00', false);
    }

    private function nightShift(): WorkSchedule
    {
        // time_in 22:00, break_out 02:00, break_in 02:30, time_out 06:00
        return new WorkSchedule('22:00', '02:30', '06:00', '02:00', '06:00', true);
    }

    private function nightShiftModel(): Shift
    {
        return Shift::create([
            'name' => 'Night',
            'time_in' => '22:00',
            'break_out' => '02:00',
            'break_in' => '02:30',
            'time_out' => '06:00',
            'crosses_midnight' => true,
            'is_active' => true,
        ]);
    }

    private function twentyFourHourShift(): WorkSchedule
    {
        // time_in 08:00, no break, time_out 08:00 the next day (a 24h guard duty).
        return new WorkSchedule('08:00', '08:00', '08:00', '08:00', '08:00', true, true);
    }

    private function twentyFourHourShiftModel(): Shift
    {
        return Shift::create([
            'name' => '24-Hour Duty',
            'time_in' => '08:00',
            'time_out' => '08:00',
            'break_out' => null,
            'break_in' => null,
            'crosses_midnight' => true,
            'is_active' => true,
        ]);
    }

    // ── Resolver ──────────────────────────────────────────────────────────────

    public function test_day_shift_scores_late_and_undertime(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-01 08:30:00', '2026-06-01 12:00:00', '2026-06-01 13:00:00', '2026-06-01 17:30:00'];

        $r = $resolver->resolve($punches, '2026-06-01', $this->dayShift());

        $this->assertSame(30, $r['late_minutes']);     // 08:30 vs 08:00
        $this->assertSame(0, $r['undertime_minutes']); // 17:30 is past 17:00
    }

    public function test_night_shift_perfect_attendance_scores_zero(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-10 22:00:00', '2026-06-11 02:00:00', '2026-06-11 02:30:00', '2026-06-11 06:00:00'];

        $r = $resolver->resolve($punches, '2026-06-10', $this->nightShift());

        $this->assertSame('22:00:00', $r['am_in']);
        $this->assertSame('06:00:00', $r['pm_out']);
        $this->assertSame(0, $r['late_minutes']);
        $this->assertSame(0, $r['undertime_minutes']);
    }

    public function test_night_shift_late_arrival_across_midnight(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-10 22:15:00', '2026-06-11 02:00:00', '2026-06-11 02:30:00', '2026-06-11 06:00:00'];

        $r = $resolver->resolve($punches, '2026-06-10', $this->nightShift());

        $this->assertSame(15, $r['late_minutes']);
        $this->assertSame(0, $r['undertime_minutes']);
    }

    public function test_night_shift_early_departure_across_midnight(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-10 22:00:00', '2026-06-11 02:00:00', '2026-06-11 02:30:00', '2026-06-11 05:30:00'];

        $r = $resolver->resolve($punches, '2026-06-10', $this->nightShift());

        $this->assertSame(0, $r['late_minutes']);
        $this->assertSame(30, $r['undertime_minutes']);
    }

    public function test_24_hour_shift_scores_late_arrival(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-01 08:10:00', '2026-06-02 08:00:00'];

        $r = $resolver->resolve($punches, '2026-06-01', $this->twentyFourHourShift());

        $this->assertSame('08:10:00', $r['am_in']);
        $this->assertSame('08:00:00', $r['pm_out']);
        $this->assertSame(10, $r['late_minutes']);
        $this->assertSame(0, $r['undertime_minutes']);
    }

    public function test_24_hour_shift_scores_early_departure(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-01 08:00:00', '2026-06-02 07:50:00'];

        $r = $resolver->resolve($punches, '2026-06-01', $this->twentyFourHourShift());

        $this->assertSame(0, $r['late_minutes']);
        $this->assertSame(10, $r['undertime_minutes']);
    }

    public function test_three_punch_day_anchors_end_of_shift_punch_to_pm_out(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 08:00:00', '2026-06-11 12:00:00', '2026-06-11 17:00:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertSame('08:00:00', $r['am_in']);
        $this->assertSame('12:00:00', $r['am_out']);
        $this->assertNull($r['pm_in']);
        $this->assertSame('17:00:00', $r['pm_out']);
    }

    public function test_three_punch_day_before_shift_end_reads_early_pm_return(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-30 08:00:00', '2026-06-30 12:00:00', '2026-06-30 12:45:00'];

        $r = $resolver->resolve($punches, '2026-06-30', $this->dayShift());

        $this->assertSame('08:00:00', $r['am_in']);
        $this->assertSame('12:00:00', $r['am_out']);
        $this->assertSame('12:45:00', $r['pm_in'], '12:45 sits nearer the 13:00 lunch return than the 11:00 break-out.');
        $this->assertNull($r['pm_out'], 'The departure punch is genuinely missing - never borrowed from pm_in.');
    }

    public function test_two_punch_day_anchors_end_of_shift_punch_to_pm_out(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 08:00:00', '2026-06-11 17:00:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertSame('08:00:00', $r['am_in']);
        $this->assertNull($r['am_out']);
        $this->assertNull($r['pm_in']);
        $this->assertSame('17:00:00', $r['pm_out']);
    }

    public function test_single_pm_punch_before_shift_end_maps_to_pm_in(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 14:00:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertNull($r['am_in']);
        $this->assertNull($r['am_out']);
        $this->assertSame('14:00:00', $r['pm_in']);
        $this->assertNull($r['pm_out']);
    }

    public function test_single_pm_punch_at_shift_end_maps_to_pm_out(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 17:30:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertNull($r['am_in']);
        $this->assertNull($r['am_out']);
        $this->assertNull($r['pm_in']);
        $this->assertSame('17:30:00', $r['pm_out']);
    }

    public function test_two_pm_punches_before_shift_end_map_to_pm_in_and_pm_out(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 13:05:00', '2026-06-11 15:00:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertNull($r['am_in']);
        $this->assertNull($r['am_out']);
        $this->assertSame('13:05:00', $r['pm_in']);
        $this->assertSame('15:00:00', $r['pm_out']);
    }

    public function test_two_pm_punches_with_last_at_shift_end_anchors_pm_out(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 14:00:00', '2026-06-11 17:15:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertNull($r['am_in']);
        $this->assertNull($r['am_out']);
        $this->assertSame('14:00:00', $r['pm_in']);
        $this->assertSame('17:15:00', $r['pm_out']);
    }

    /**
     * A PM-only day charges break-return lateness from lunchReturn: late is
     * computed from every matched IN event (spec: AM In and PM In), so a
     * 14:00 return against a 13:00 lunchReturn scores 60 minutes even with
     * the whole morning missing. The morning itself is a structural problem
     * (status half_day_pm / read-time coverage), not a lateness charge.
     */
    public function test_pm_only_day_charges_pm_in_lateness_from_lunch_return(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 14:00:00', '2026-06-11 17:15:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertSame(60, $r['late_minutes']);
        $this->assertSame(0, $r['undertime_minutes']);
        $this->assertSame('half_day_pm', $r['status']);
    }

    /**
     * A tardy am_out (11:50, between morningEnd 11:00 and lunchReturn 13:00)
     * followed by pm_in and pm_out must not be misread as a PM-only day - the
     * missing punch is am_in, not pm_in.
     */
    public function test_three_punch_day_with_tardy_am_out_infers_missing_am_in(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 11:50:00', '2026-06-11 13:00:00', '2026-06-11 17:00:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertNull($r['am_in']);
        $this->assertSame('11:50:00', $r['am_out']);
        $this->assertSame('13:00:00', $r['pm_in']);
        $this->assertSame('17:00:00', $r['pm_out']);
        $this->assertSame(0, $r['late_minutes']);
    }

    /** A late pm_in in the inferred-missing-am_in shape must still score break-return lateness. */
    public function test_three_punch_day_with_tardy_am_out_still_scores_late_pm_in(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 11:50:00', '2026-06-11 13:20:00', '2026-06-11 17:00:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertSame('11:50:00', $r['am_out']);
        $this->assertSame('13:20:00', $r['pm_in']);
        $this->assertSame(20, $r['late_minutes']);
    }

    /**
     * A punctual-ish am_out BEFORE morningEnd (10:55 vs. an 11:00 morningEnd)
     * must read as the break-out (5 minutes early) rather than a wildly late
     * arrival. Regression guard for the false multi-hour late charge this
     * shape used to produce (10:55 read as am_in scored 175 minutes late
     * against an 08:00 workStart).
     */
    public function test_three_punch_day_with_punctual_am_out_infers_missing_am_in(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 10:55:00', '2026-06-11 13:00:00', '2026-06-11 17:00:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertNull($r['am_in']);
        $this->assertSame('10:55:00', $r['am_out']);
        $this->assertSame('13:00:00', $r['pm_in']);
        $this->assertSame('17:00:00', $r['pm_out']);
        $this->assertSame(0, $r['late_minutes']);
    }

    /**
     * An ~08:00 arrival, an on-time-ish am_out, and a punch at/after shift
     * end: the arrival must stay am_in (it is at the scheduled start) and the
     * missing punch is pm_in - a 17:00 punch is a departure, never an
     * implausibly late lunch return.
     */
    public function test_three_punch_day_long_first_gap_does_not_get_misread_as_missing_am_in(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 08:00:00', '2026-06-11 12:00:00', '2026-06-11 17:00:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertSame('08:00:00', $r['am_in']);
        $this->assertSame('12:00:00', $r['am_out']);
        $this->assertNull($r['pm_in']);
        $this->assertSame('17:00:00', $r['pm_out']);
    }

    /**
     * Real production case (Manalo, Ma. Haidee, 2026-07-03): punches
     * [07:16, 12:29, 16:11] used to resolve positionally to
     * am_in/am_out/pm_in, scoring a bogus 191-minute "late return from lunch"
     * charge against a pm_in that was actually 3h42m after am_out. Ground
     * truth: 12:29 was a LATE LUNCH-OUT (verified), pm_in is what's missing,
     * and 16:11 is an early departure. This is what calibrates
     * attendance.matching.out_late_bias: at 0.33 the AM Out / PM In
     * switchover on this schedule sits at ~12:30, so 12:29 reads as the
     * break-out while a typical 12:45+ early return still reads as pm_in.
     */
    public function test_three_punch_day_with_long_second_gap_infers_missing_pm_in(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-07-03 07:16:00', '2026-07-03 12:29:00', '2026-07-03 16:11:00'];

        $r = $resolver->resolve($punches, '2026-07-03', $this->dayShift());

        $this->assertSame('07:16:00', $r['am_in']);
        $this->assertSame('12:29:00', $r['am_out']);
        $this->assertNull($r['pm_in']);
        $this->assertSame('16:11:00', $r['pm_out']);
        $this->assertSame(0, $r['late_minutes']);
        $this->assertSame(49, $r['undertime_minutes']);
    }

    /**
     * A mid-afternoon punch (15:30) with the departure missing reads as a
     * (very) late lunch return, not an early departure: 15:30 sits nearer
     * the 13:00 lunch return (late side of an IN event) than the 17:00
     * shift end, so pm_out stays genuinely NULL.
     */
    public function test_three_punch_day_mid_afternoon_punch_reads_as_late_pm_in(): void
    {
        $resolver = new DtrPunchResolver;
        $punches = ['2026-06-11 08:00:00', '2026-06-11 12:00:00', '2026-06-11 15:30:00'];

        $r = $resolver->resolve($punches, '2026-06-11', $this->dayShift());

        $this->assertSame('08:00:00', $r['am_in']);
        $this->assertSame('12:00:00', $r['am_out']);
        $this->assertSame('15:30:00', $r['pm_in']);
        $this->assertNull($r['pm_out']);
    }

    // ── Grouping ──────────────────────────────────────────────────────────────

    public function test_night_shift_punches_fold_onto_start_date(): void
    {
        $shift = $this->nightShiftModel();
        $user = $this->createEmployee(['shift_id' => $shift->id]);

        foreach (['2026-06-10 22:00:00', '2026-06-11 02:00:00', '2026-06-11 02:30:00', '2026-06-11 06:00:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo,
                'logdate' => $d, 'logtime' => $t, 'in_out' => 'IN',
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-10', $groups);
        $this->assertArrayNotHasKey('2026-06-11', $groups);
        $this->assertCount(4, $groups['2026-06-10']);
    }

    /**
     * A night shift's closing punch must fold back to its own start date
     * even when the following calendar day carries a wholly different
     * schedule - normal, expected day-to-day variation in this app (a rest
     * day, WFH, or a different shift). Without this, the closing punch gets
     * evaluated against tomorrow's own (non-crossing) resolution instead of
     * the shift it actually belongs to, and stays stranded on tomorrow's
     * date as its own incomplete row.
     */
    public function test_night_shift_closing_punch_folds_back_even_when_next_day_has_a_different_schedule(): void
    {
        $shift = $this->nightShiftModel();
        $user = $this->createEmployee(['shift_id' => $shift->id]);

        EmployeeShiftSchedule::create([
            'user_id' => $user->id,
            'date' => '2026-06-11',
            'shift_id' => null,
            'type' => 'standard',
            'created_by' => $user->id,
        ]);

        foreach (['2026-06-10 22:05:00', '2026-06-11 05:55:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo,
                'logdate' => $d, 'logtime' => $t, 'in_out' => 'IN',
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-10', $groups);
        $this->assertArrayNotHasKey('2026-06-11', $groups);
        $this->assertCount(2, $groups['2026-06-10']);
    }

    public function test_day_shift_punches_group_by_logdate(): void
    {
        $user = $this->createEmployee();   // no shift → standard day

        foreach (['2026-06-10 08:00:00', '2026-06-10 17:00:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo,
                'logdate' => $d, 'logtime' => $t, 'in_out' => 'IN',
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-10', $groups);
        $this->assertCount(2, $groups['2026-06-10']);
    }

    public function test_24_hour_shift_punches_fold_onto_start_date(): void
    {
        $shift = $this->twentyFourHourShiftModel();
        $user = $this->createEmployee(['shift_id' => $shift->id]);

        foreach (['2026-06-01 08:05:00', '2026-06-02 07:55:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo,
                'logdate' => $d, 'logtime' => $t, 'in_out' => 'IN',
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-01', $groups);
        $this->assertArrayNotHasKey('2026-06-02', $groups);
        $this->assertCount(2, $groups['2026-06-01']);
    }

    /** @return array{0: User, 1: Shift} an employee on an open-ended 24h crossing shift from $from */
    private function employeeOnTwentyFourHourShift(string $from): array
    {
        $shift = $this->twentyFourHourShiftModel();
        $user = $this->createEmployee();
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse($from), null, null, null, [0, 1, 2, 3, 4, 5, 6], true
        );

        return [$user, $shift];
    }

    private function markRestDay(User $user, string $date): void
    {
        EmployeeShiftSchedule::create([
            'user_id' => $user->id, 'date' => $date, 'shift_id' => null, 'type' => 'rest', 'created_by' => $user->id,
        ]);
    }

    /**
     * The user's exact real-world case: an on-day arrival whose checkout only
     * arrives after two consecutive configured rest days, not within any
     * fixed clock-hour grace period. Must still combine into one row for the
     * first on-day.
     */
    public function test_24_hour_shift_combines_arrival_and_checkout_across_two_rest_days(): void
    {
        [$user] = $this->employeeOnTwentyFourHourShift('2026-07-16');
        $this->markRestDay($user, '2026-07-17');
        $this->markRestDay($user, '2026-07-18');

        foreach (['2026-07-16 08:00:00', '2026-07-18 07:56:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => $d, 'logtime' => $t,
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-07-16', $groups);
        $this->assertCount(2, $groups['2026-07-16']);
        $this->assertArrayNotHasKey('2026-07-17', $groups);
        $this->assertArrayNotHasKey('2026-07-18', $groups);
    }

    /** A clean ~24h-apart pair with no rest-day gap - the fixed grace side alone must still combine it. */
    public function test_24_hour_shift_combines_a_clean_pair_via_grace_alone(): void
    {
        [$user] = $this->employeeOnTwentyFourHourShift('2026-06-01');

        foreach (['2026-06-01 08:07:00', '2026-06-02 08:11:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => $d, 'logtime' => $t,
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-01', $groups);
        $this->assertCount(2, $groups['2026-06-01']);
        $this->assertArrayNotHasKey('2026-06-02', $groups);
    }

    /** A gap wider than the next scheduled workday must stay a genuinely unclosed row, not phantom-fold. */
    public function test_24_hour_shift_leaves_a_genuine_gap_unclosed(): void
    {
        [$user] = $this->employeeOnTwentyFourHourShift('2026-06-01');
        $this->markRestDay($user, '2026-06-02');
        $this->markRestDay($user, '2026-06-03');

        AttendanceLog::create([
            'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => '2026-06-01', 'logtime' => '08:00:00',
        ]);
        // 2026-06-04 is the next on-day; this punch arrives an hour after its
        // own start, well past 2026-06-01's shift's eligibility window.
        AttendanceLog::create([
            'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => '2026-06-04', 'logtime' => '09:00:00',
        ]);

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-01', $groups);
        $this->assertCount(1, $groups['2026-06-01']);
        $this->assertArrayHasKey('2026-06-04', $groups);
        $this->assertCount(1, $groups['2026-06-04']);
        $this->assertArrayNotHasKey('2026-06-02', $groups);
        $this->assertArrayNotHasKey('2026-06-03', $groups);
    }

    /**
     * Regression for a real reported case: eligibleUntil() used to reach
     * EXACTLY to the next on-day's own scheduled start with zero margin. An
     * exactly-on-time arrival for that next on-day (equal to the stored
     * eligibleUntil, an inclusive `lte` tie) was wrongly absorbed into the
     * stale previous shift instead of opening its own - silently discarding
     * that day's own attendance (it never got a groups[] entry at all).
     */
    public function test_24_hour_shift_does_not_swallow_the_next_on_days_own_arrival_after_two_rest_days(): void
    {
        [$user] = $this->employeeOnTwentyFourHourShift('2026-08-10');
        $this->markRestDay($user, '2026-08-11');
        $this->markRestDay($user, '2026-08-12');

        foreach (['2026-08-10 08:05:00', '2026-08-11 08:02:00', '2026-08-13 08:00:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => $d, 'logtime' => $t,
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-08-10', $groups);
        $this->assertCount(2, $groups['2026-08-10']); // 08-10 08:05 open + 08-11 08:02 close
        $this->assertArrayNotHasKey('2026-08-11', $groups);
        $this->assertArrayNotHasKey('2026-08-12', $groups);

        $this->assertArrayHasKey('2026-08-13', $groups);
        $this->assertCount(1, $groups['2026-08-13']); // its OWN on-time arrival, not absorbed into 08-10
    }

    /**
     * The floor itself (early_in_hours before the next on-day's own start) is
     * inclusive on AttendanceMatcher's side - a punch exactly there must still
     * open its own new shift, not replay the same tie one boundary earlier.
     */
    public function test_24_hour_shift_treats_a_punch_exactly_at_the_early_in_floor_as_the_next_days_own_arrival(): void
    {
        [$user] = $this->employeeOnTwentyFourHourShift('2026-06-01');
        $this->markRestDay($user, '2026-06-02');
        $this->markRestDay($user, '2026-06-03');

        // 2026-06-04 is the next on-day; early_in_hours defaults to 4.0, so
        // 04:00 is the earliest instant AttendanceMatcher itself would treat
        // as 2026-06-04's own legitimate early arrival.
        foreach (['2026-06-01 08:00:00', '2026-06-04 04:00:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => $d, 'logtime' => $t,
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-01', $groups);
        $this->assertCount(1, $groups['2026-06-01']);
        $this->assertArrayHasKey('2026-06-04', $groups);
        $this->assertCount(1, $groups['2026-06-04']);
    }

    /** A stray mid-shift punch must not clobber the tracker before the real closing punch arrives. */
    public function test_24_hour_shift_absorbs_a_stray_mid_shift_punch_before_the_real_close(): void
    {
        [$user] = $this->employeeOnTwentyFourHourShift('2026-06-01');
        $this->markRestDay($user, '2026-06-02');

        foreach (['2026-06-01 08:00:00', '2026-06-01 20:00:00', '2026-06-02 07:56:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => $d, 'logtime' => $t,
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-01', $groups);
        $this->assertCount(3, $groups['2026-06-01']);
        $this->assertArrayNotHasKey('2026-06-02', $groups);
    }

    /** A large early departure (well before the 24h mark) must still fold onto the shift's start - not newly capped. */
    public function test_24_hour_shift_folds_a_large_early_departure_onto_start(): void
    {
        [$user] = $this->employeeOnTwentyFourHourShift('2026-06-01');
        $this->markRestDay($user, '2026-06-02');

        foreach (['2026-06-01 08:00:00', '2026-06-02 02:00:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => $d, 'logtime' => $t,
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-01', $groups);
        $this->assertCount(2, $groups['2026-06-01']);
        $this->assertArrayNotHasKey('2026-06-02', $groups);
    }

    /**
     * Documents the accepted, narrow limitation for back-to-back 24h shifts
     * with zero rest between them: a single punch physically cannot both
     * close one shift and open the next, so the middle day's own arrival is
     * absorbed into the previous day's bucket instead of getting its own.
     */
    public function test_back_to_back_24_hour_shifts_absorb_the_middle_arrival(): void
    {
        [$user] = $this->employeeOnTwentyFourHourShift('2026-06-01');

        foreach (['2026-06-01 08:00:00', '2026-06-02 08:00:00', '2026-06-03 08:00:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => $d, 'logtime' => $t,
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-01', $groups);
        $this->assertCount(2, $groups['2026-06-01']);
        $this->assertArrayNotHasKey('2026-06-02', $groups);
        $this->assertArrayHasKey('2026-06-03', $groups);
        $this->assertCount(1, $groups['2026-06-03']);
    }

    /**
     * Regression: WorkSchedule::shiftDateFor()'s boundary used to be computed
     * as a bare 'HH:MM' string, correct only when workStart + workEnd sums to
     * >= 24h (e.g. 08:00-17:00, where the boundary rolls into the next day's
     * early morning). A shift where that sum is UNDER 24h - e.g. 07:00-16:00 -
     * left the boundary late in the SAME evening (23:30), which made every
     * normal morning arrival compare as "before the boundary" and incorrectly
     * fold onto the previous day.
     */
    public function test_early_day_shift_punches_stay_on_their_own_logdate(): void
    {
        $shift = Shift::create([
            'name' => 'Early 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'crosses_midnight' => false, 'is_active' => true,
        ]);
        $user = $this->createEmployee(['shift_id' => $shift->id]);

        foreach (['2026-06-10 06:58:00', '2026-06-10 12:01:00', '2026-06-10 12:59:00', '2026-06-10 16:02:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo,
                'logdate' => $d, 'logtime' => $t, 'in_out' => 'IN',
            ]);
        }

        $groups = (new ShiftPunchGrouper)->group($user, AttendanceLog::where('user_id', $user->id)->get());

        $this->assertArrayHasKey('2026-06-10', $groups);
        $this->assertArrayNotHasKey('2026-06-09', $groups, 'A normal morning arrival must never fold onto the previous day.');
        $this->assertCount(4, $groups['2026-06-10']);
    }

    // ── WorkSchedule resolution ───────────────────────────────────────────────

    public function test_work_schedule_for_user_uses_assigned_shift(): void
    {
        WorkSchedule::flushGlobal();

        $night = $this->createEmployee(['shift_id' => $this->nightShiftModel()->id]);
        $standard = $this->createEmployee();

        $this->assertTrue(WorkSchedule::forUser($night)->crossesMidnight);
        $this->assertSame('22:00', WorkSchedule::forUser($night)->workStart);

        $this->assertFalse(WorkSchedule::forUser($standard)->crossesMidnight);
        $this->assertSame('08:00', WorkSchedule::forUser($standard)->workStart);
    }

    // ── Authorization + management ────────────────────────────────────────────

    public function test_employee_cannot_access_shift_screens(): void
    {
        $employee = $this->createEmployee();

        $this->actingAs($employee)->get(route('attendance.shifts'))->assertStatus(403);
        $this->actingAs($employee)->get(route('attendance.schedules'))->assertStatus(403);
    }

    public function test_time_keeper_can_open_shift_screens(): void
    {
        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->get(route('attendance.shifts'))->assertStatus(200);
        $this->actingAs($tk)->get(route('attendance.schedules'))->assertStatus(200);
    }

    public function test_time_keeper_can_create_night_shift_template(): void
    {
        $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.shifts.store'), [
                'name' => 'Graveyard',
                'time_in' => '22:00',
                'break_out' => '02:00',
                'break_in' => '02:30',
                'time_out' => '06:00',
            ])
            ->assertRedirect();

        $shift = Shift::where('name', 'Graveyard')->firstOrFail();
        $this->assertTrue($shift->crosses_midnight);
    }

    /**
     * Reproduces the real incident this guard was added for: a "VET -
     * Evening Shift" template kept the Create Shift form's stale
     * Standard-Day break defaults (12:00/13:00) after Time In/Time Out were
     * changed to an evening window (21:00-05:00), producing a break window
     * hours outside the shift's own span - which degenerated
     * AttendanceMatcher's slot windows and corrupted real DTR data.
     */
    public function test_shift_creation_rejects_break_window_outside_shift_span(): void
    {
        $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.shifts.store'), [
                'name' => 'VET - Evening Shift',
                'time_in' => '21:00',
                'break_out' => '12:00',
                'break_in' => '13:00',
                'time_out' => '05:00',
            ])
            ->assertSessionHasErrors('break_out');

        $this->assertDatabaseMissing('shifts', ['name' => 'VET - Evening Shift']);
    }

    public function test_shift_creation_accepts_break_window_correctly_ordered_within_a_crossing_shift(): void
    {
        $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.shifts.store'), [
                'name' => 'VET - Evening Shift',
                'time_in' => '21:00',
                'break_out' => '23:00',
                'break_in' => '23:30',
                'time_out' => '05:00',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('shifts', ['name' => 'VET - Evening Shift', 'crosses_midnight' => true]);
    }

    /**
     * A Field Work Pattern template represents a weekly (not daily) span -
     * Time In is Monday's check-in anchor, Time Out is Friday's check-out
     * anchor - so it has no break window or same-day ordering to require.
     */
    public function test_field_work_pair_shift_creation_does_not_require_break_times(): void
    {
        $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.shifts.store'), [
                'name' => 'Field Work',
                'time_in' => '08:00',
                'time_out' => '17:00',
                'is_field_work_pair' => '1',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $shift = Shift::where('name', 'Field Work')->firstOrFail();
        $this->assertTrue($shift->is_field_work_pair);
        $this->assertNull($shift->break_out);
        $this->assertNull($shift->break_in);
        $this->assertFalse($shift->crosses_midnight);
    }

    /**
     * The relaxed validation above is scoped to is_field_work_pair=1 only -
     * an ordinary template submission omitting break_out/break_in still
     * fails exactly as before.
     */
    public function test_ordinary_shift_creation_still_requires_break_times(): void
    {
        $this->actingAs($this->createTimeKeeper())
            ->post(route('attendance.shifts.store'), [
                'name' => 'Missing Breaks',
                'time_in' => '08:00',
                'time_out' => '17:00',
            ])
            ->assertSessionHasErrors(['break_out', 'break_in']);

        $this->assertDatabaseMissing('shifts', ['name' => 'Missing Breaks']);
    }

    public function test_time_keeper_can_assign_shift_to_employee(): void
    {
        $shift = $this->nightShiftModel();
        $employee = $this->createEmployee();

        $this->actingAs($this->createTimeKeeper())
            ->put(route('attendance.schedules.update', $employee), ['shift_id' => $shift->id])
            ->assertRedirect();

        $this->assertSame($shift->id, $employee->refresh()->shift_id);
        $this->assertDatabaseHas('shift_assignments', ['user_id' => $employee->id, 'shift_id' => $shift->id, 'effective_until' => null]);
    }

    public function test_time_keeper_can_assign_shift_with_an_effective_window(): void
    {
        $shift = $this->nightShiftModel();
        $employee = $this->createEmployee();

        $this->travelTo(Carbon::parse('2026-07-10'));

        $this->actingAs($this->createTimeKeeper())
            ->put(route('attendance.schedules.update', $employee), [
                'shift_id' => $shift->id,
                'effective_from' => '2026-08-01',
                'effective_until' => '2026-08-31',
            ])
            ->assertRedirect();

        // The window is in the future, so today's cache is untouched.
        $this->assertNull($employee->refresh()->shift_id);
        $this->assertDatabaseHas('shift_assignments', [
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-08-01',
            'effective_until' => '2026-08-31',
        ]);
    }

    public function test_add_shift_form_type_requires_both_effective_dates(): void
    {
        $shift = $this->nightShiftModel();
        $employee = $this->createEmployee();

        $this->actingAs($this->createTimeKeeper())
            ->put(route('attendance.schedules.update', $employee), [
                'form_type' => 'add',
                'shift_id' => $shift->id,
            ])
            ->assertSessionHasErrors(['effective_from', 'effective_until']);

        $this->assertDatabaseMissing('shift_assignments', ['user_id' => $employee->id]);
    }

    public function test_add_shift_form_type_succeeds_with_both_dates_supplied(): void
    {
        $shift = $this->nightShiftModel();
        $employee = $this->createEmployee();

        $this->actingAs($this->createTimeKeeper())
            ->put(route('attendance.schedules.update', $employee), [
                'form_type' => 'add',
                'shift_id' => $shift->id,
                'effective_from' => '2026-08-01',
                'effective_until' => '2026-08-31',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('shift_assignments', [
            'user_id' => $employee->id, 'shift_id' => $shift->id,
            'effective_from' => '2026-08-01', 'effective_until' => '2026-08-31',
        ]);
    }

    public function test_remove_form_type_does_not_require_effective_dates(): void
    {
        $shift = $this->nightShiftModel();
        $employee = $this->createEmployee();
        app(ShiftAssignmentService::class)->assign($employee, $shift->id, Carbon::today(), null, null);

        $this->actingAs($this->createTimeKeeper())
            ->put(route('attendance.schedules.update', $employee), [
                'form_type' => 'remove',
                'shift_id' => '',
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $this->assertNull($employee->refresh()->shift_id);
    }

    public function test_cannot_delete_shift_with_assigned_employees(): void
    {
        $shift = $this->nightShiftModel();
        $this->createEmployee(['shift_id' => $shift->id]);

        $this->actingAs($this->createTimeKeeper())
            ->delete(route('attendance.shifts.destroy', $shift))
            ->assertSessionHas('shift_error');

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
    }

    // ── Biometric / DTR exemption ─────────────────────────────────────────────

    public function test_time_keeper_can_toggle_exemption_and_it_clears_shift(): void
    {
        $employee = $this->createEmployee(['shift_id' => $this->nightShiftModel()->id]);

        $this->actingAs($this->createTimeKeeper())
            ->put(route('attendance.schedules.exempt', $employee))
            ->assertRedirect();

        $employee->refresh();
        $this->assertTrue($employee->dtr_exempt);
        $this->assertNull($employee->shift_id);

        // Toggling again removes the exemption.
        $this->actingAs($this->createTimeKeeper())
            ->put(route('attendance.schedules.exempt', $employee))
            ->assertRedirect();

        $this->assertFalse($employee->refresh()->dtr_exempt);
    }

    public function test_recompute_writes_no_dtr_for_exempt_employee(): void
    {
        $employee = $this->createEmployee(['dtr_exempt' => true]);

        foreach (['2026-06-10 08:00:00', '2026-06-10 17:00:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $employee->id, 'emp_no' => $employee->EmpNo,
                'logdate' => $d, 'logtime' => $t, 'in_out' => 'IN',
            ]);
        }

        app(PersonnelLogImportService::class)->recomputeDtr($employee, '2026-06-10', '2026-06-10');

        $this->assertDatabaseMissing('dtrs', ['employee_id' => $employee->id]);
    }

    public function test_exempt_employee_blocked_from_single_form48_download(): void
    {
        $employee = $this->createEmployee(['dtr_exempt' => true]);

        $this->actingAs($this->createTimeKeeper())
            ->get(route('attendance.dtr.download', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]))
            ->assertStatus(422);
    }

    public function test_schedules_index_hides_exempt_by_default(): void
    {
        $active = $this->createEmployee(['last_name' => 'Activeperson']);
        $exempt = $this->createEmployee(['last_name' => 'Exemptperson', 'dtr_exempt' => true]);
        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->get(route('attendance.schedules'))
            ->assertSee('Activeperson')
            ->assertDontSee('Exemptperson');

        $this->actingAs($tk)->get(route('attendance.schedules', ['show_exempt' => 1]))
            ->assertSee('Exemptperson')
            ->assertDontSee('Activeperson');
    }

    public function test_schedules_index_filters_by_employee_type(): void
    {
        $permanent = $this->createEmployee(['last_name' => 'Permanentperson', 'employee_type' => 'Permanent']);
        $casual = $this->createEmployee(['last_name' => 'Casualperson', 'employee_type' => 'Casual']);
        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->get(route('attendance.schedules', ['employee_type' => 'casual']))
            ->assertSee('Casualperson')
            ->assertDontSee('Permanentperson');

        $this->actingAs($tk)->get(route('attendance.schedules'))
            ->assertSee('Casualperson')
            ->assertSee('Permanentperson');
    }

    public function test_monitoring_matrix_keeps_exempt_employee_and_flags_it(): void
    {
        $active = $this->createEmployee(['last_name' => 'Activeperson']);
        $exempt = $this->createEmployee(['last_name' => 'Exemptperson', 'dtr_exempt' => true]);

        $departments = Department::where('Dept_id', $active->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)
            ->getRows($departments, (int) now()->month, (int) now()->year);

        // Exempt employee is NOT filtered out - both employees appear.
        $this->assertTrue($rows->contains(fn ($r) => str_contains($r['name'], 'Activeperson')));
        $this->assertTrue($rows->contains(fn ($r) => str_contains($r['name'], 'Exemptperson')));

        $exemptRow = $rows->firstWhere(fn ($r) => str_contains($r['name'], 'Exemptperson'));
        $activeRow = $rows->firstWhere(fn ($r) => str_contains($r['name'], 'Activeperson'));
        $this->assertTrue($exemptRow['is_exempt']);
        $this->assertFalse($activeRow['is_exempt']);
    }

    // ── Monitoring Matrix: "days absent w/ unofficial exit" (zero punches, nothing
    // on file to explain the day) ──────────────────────────────────────────────
    //
    // Every test below pins `date_hired` to the last day of the reported month
    // (or, for the weekend case, to the last day of a month ending on a Sunday) so
    // the day-enumeration loop in AttendanceMonitoringExportService only ever
    // walks that single day - otherwise every other undocumented weekday in the
    // month would also be flagged, since the report has no other attendance data
    // for these throwaway test employees.

    private function unofficialExitRowFor(User $employee, int $month = 6, int $year = 2026): array
    {
        $departments = Department::where('Dept_id', $employee->Dept_id)->get();
        $rows = app(AttendanceMonitoringExportService::class)->getRows($departments, $month, $year);

        return $rows->firstWhere(fn ($r) => str_contains($r['name'], $employee->last_name));
    }

    public function test_unfiled_leave_counted_for_fully_absent_day_with_no_coverage(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        // Default test employee_type is 'Permanent' - a leave-accruing type, so a
        // fully blank day counts as Unfiled Leave, not Unofficial Exit.
        $employee = $this->createEmployee(['last_name' => 'Fullyabsent', 'date_hired' => '2026-06-30']);
        // No Dtr row at all for 2026-06-30 - matches real production data, since the
        // import pipeline never writes a row for a day with zero punches. A present
        // Dtr row earlier in the month proves biometric import is otherwise working,
        // so this test exercises plain per-day absence rather than the whole-month
        // "No DTR Data" safeguard (covered separately below).
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(1, $row['unfiled_count']);
        $this->assertFalse($row['unfiled_leave_no_data']);
        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertStringContainsString('30-Absent (Unfiled Leave)', $row['remarks']);
    }

    public function test_unofficial_exit_counted_for_blank_day_when_employee_type_does_not_accrue_leave(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        // Job Order staff are blocked from filing leave entirely and Elected
        // Officials don't accrue standard civil-service leave credits, so a blank
        // day for either type stays classified as Unofficial Exit.
        $employee = $this->createEmployee([
            'last_name' => 'Electedperson',
            'date_hired' => '2026-06-30',
            'employee_type' => 'Elected Officials',
        ]);
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(1, $row['unofficial_exit_count']);
        $this->assertFalse($row['unofficial_exit_no_data']);
        $this->assertSame(0, $row['unfiled_count']);
        $this->assertStringContainsString('30-Absent (Unofficial Exit)', $row['remarks']);
    }

    public function test_unofficial_exit_counted_for_blank_day_for_job_order_employee(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee([
            'last_name' => 'Joborderperson',
            'date_hired' => '2026-06-30',
            'employee_type' => 'Job Orders',
        ]);
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(1, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unfiled_leave_counted_for_blank_day_for_casual_employee(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        // Spot-check that the leave-accruing whitelist covers more than just the
        // default 'Permanent' type used by the other tests in this file.
        $employee = $this->createEmployee([
            'last_name' => 'Casualperson',
            'date_hired' => '2026-06-30',
            'employee_type' => 'Casual',
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(1, $row['unfiled_count']);
        $this->assertSame(0, $row['unofficial_exit_count']);
    }

    public function test_unofficial_exit_counted_for_partial_punch_missing_pm_logout(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Nologout', 'date_hired' => '2026-06-30']);
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-30',
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        // Punched in but never logged out counts as Unofficial Exit for every
        // employee type, alongside (not instead of) the existing phantom-undertime
        // charge for the same day.
        $this->assertSame(1, $row['unofficial_exit_count']);
        $this->assertSame(1, $row['undertime_count']);
        $this->assertSame(240, $row['total_minutes']); // 13:00 -> 17:00 shift end
        $this->assertStringContainsString('30-Unofficial Exit (No Time Out)', $row['remarks']);
    }

    public function test_unofficial_exit_counted_for_am_in_only_punch(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        // Real production shape: a single AM-In punch and nothing else all
        // day (no AM Out, no PM In, no PM Out) - previously fell through the
        // "any punch = presence" catch-all with zero flag anywhere.
        $employee = $this->createEmployee(['last_name' => 'Aminonly', 'date_hired' => '2026-06-30']);
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-30',
            'time_in_am' => '07:25:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(1, $row['unofficial_exit_count']);
        $this->assertStringContainsString('30-Unofficial Exit (Incomplete Punches)', $row['remarks']);
    }

    public function test_unofficial_exit_counted_for_no_break_schedule_with_only_am_in_punched(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        // No-break schedules only ever expect am_in/pm_out (no lunch break in
        // the middle) - punching in but never logging the single "time out"
        // must still flag, same as the 4-slot case.
        $shift = Shift::create([
            'name' => 'Guard Duty', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        $employee = $this->createEmployee(['last_name' => 'Nobreakaminonly', 'date_hired' => '2026-06-30']);
        EmployeeShiftSchedule::create([
            'user_id' => $employee->id, 'date' => '2026-06-30', 'shift_id' => $shift->id, 'no_break' => true,
        ]);
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-30',
            'time_in_am' => '08:00:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        // Only pm_out is missing from this schedule's required set (am_out/
        // pm_in were never real slots to begin with), so this reuses the
        // existing "No Time Out" label rather than "Incomplete Punches".
        $this->assertSame(1, $row['unofficial_exit_count']);
        $this->assertStringContainsString('30-Unofficial Exit (No Time Out)', $row['remarks']);
    }

    public function test_unofficial_exit_not_counted_for_missing_pm_logout_covered_by_dtr_excuse(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Excusednologout', 'date_hired' => '2026-06-30']);
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-30',
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);
        DtrExcuse::create([
            'user_id' => $employee->id,
            'date' => '2026-06-30',
            'excuse_type' => 'system_failure',
            'excuse_pm_out' => true,
            'reason' => 'Biometric device offline at end of shift',
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertStringNotContainsString('Unofficial Exit', $row['remarks']);
    }

    public function test_unofficial_exit_not_counted_when_covered_by_approved_leave(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Leavecovered', 'date_hired' => '2026-06-30']);
        $leave = LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'start_date' => '2026-06-30',
            'end_date' => '2026-06-30',
            'reason' => 'Test',
            'status' => 'approved',
        ]);
        LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => '2026-06-30', 'is_cancelled' => false]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_when_covered_by_approved_travel_order(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Travelcovered', 'date_hired' => '2026-06-30']);
        $travelOrderId = DB::table('travel_orders')->insertGetId([
            'travel_order_num' => '2026-999',
            'purpose' => 'Test travel',
            'destination' => 'Manila',
            'start_date' => '2026-06-30',
            'end_date' => '2026-06-30',
            'recommender' => $employee->id,
            'created_by' => $employee->id,
            'status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('travel_order_employees')->insert([
            'travel_order_id' => $travelOrderId,
            'emp_no' => $employee->EmpNo,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
        $this->assertStringContainsString('30-Travel Order No. 2026-999', $row['remarks']);
    }

    public function test_unofficial_exit_not_counted_when_covered_by_locator_all_day(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Locatorcovered', 'date_hired' => '2026-06-30']);
        Locator::create([
            'user_id' => $employee->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'detail' => 'Field assignment',
            'travel_date' => '2026-06-30',
            'intended_departure_time' => '07:00',
            'intended_arrival_time' => '17:30',
            'status' => 'approved',
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_when_covered_by_eta(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Etacovered', 'date_hired' => '2026-06-30']);
        Eta::create([
            'user_id' => $employee->id,
            'departure_date' => '2026-06-30',
            'arrival_date' => '2026-06-30',
            'destination' => 'City Hall',
            'purpose' => 'Meeting',
            'status' => 'approved',
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_when_covered_by_office_order(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Ordercovered', 'date_hired' => '2026-06-30']);
        $officeOrderId = DB::table('office_orders')->insertGetId([
            'office_order_num' => '2026-998',
            'subject' => 'Test Office Order',
            'issued_date' => '2026-06-30',
            'effective_date' => '2026-06-30',
            'status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('office_order_employees')->insert([
            'office_order_id' => $officeOrderId,
            'emp_no' => $employee->EmpNo,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_when_covered_by_full_day_dtr_excuse(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Excusedexit', 'date_hired' => '2026-06-30']);
        DtrExcuse::create([
            'user_id' => $employee->id,
            'date' => '2026-06-30',
            'excuse_type' => 'power_interruption',
            'is_full_day' => true,
            'reason' => 'Power outage all day',
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_for_dtr_exempt_employee(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Exemptperson', 'date_hired' => '2026-06-30', 'dtr_exempt' => true]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertTrue($row['is_exempt']);
        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_on_weekend(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        // 2026-05-31 is a Sunday.
        $employee = $this->createEmployee(['last_name' => 'Weekendperson', 'date_hired' => '2026-05-31']);

        $row = $this->unofficialExitRowFor($employee, 5, 2026);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_on_a_day_excluded_by_shifts_work_days_pattern(): void
    {
        $this->travelTo(Carbon::parse('2026-08-15 08:00:00'));

        $shift = Shift::create([
            'name' => 'With Work From Home',
            'time_in' => '08:00',
            'break_out' => '12:00',
            'break_in' => '13:00',
            'time_out' => '17:00',
        ]);

        // 2026-07-31 is a Friday and the last day of July - hiring exactly on it
        // narrows the classification loop to this single date.
        $employee = $this->createEmployee(['last_name' => 'Wfhperson', 'date_hired' => '2026-07-31']);
        app(ShiftAssignmentService::class)->assign(
            $employee, $shift->id, Carbon::parse('2026-07-31'), null, null, null, [1, 2, 3, 4] // Mon-Thu only - Friday is not a scheduled workday.
        );

        $row = $this->unofficialExitRowFor($employee, 7, 2026);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_on_holiday(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Holidayperson', 'date_hired' => '2026-06-30']);
        Holiday::create(['title' => 'Test Holiday', 'holiday_date' => '2026-06-30']);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_on_rest_day_override(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Restdayperson', 'date_hired' => '2026-06-30']);
        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => '2026-06-30',
            'shift_id' => null,
            'type' => 'rest',
            'created_by' => $employee->id,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_on_field_work_day(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Fieldworkperson', 'date_hired' => '2026-06-30']);
        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => '2026-06-30',
            'shift_id' => null,
            'type' => 'field_work',
            'created_by' => $employee->id,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_while_shift_still_in_progress(): void
    {
        $this->travelTo(Carbon::parse('2026-06-30 15:00:00')); // before the 17:00 shift end

        $employee = $this->createEmployee(['last_name' => 'Stillworking', 'date_hired' => '2026-06-30']);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unofficial_exit_not_counted_before_employee_was_hired(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        // Hired the month after the reported period - nothing in June should ever
        // be flagged, even though there's no attendance data at all for June.
        $employee = $this->createEmployee(['last_name' => 'Newhire', 'date_hired' => '2026-07-01']);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
    }

    public function test_unfiled_leave_flags_no_dtr_data_for_whole_month_gap(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        // No Dtr row anywhere in June - simulates a biometric device outage, a
        // persistent EmpNo mismatch, or a department not yet onboarded to biometrics.
        // Default employee_type is 'Permanent', so a blank day routes to Unfiled Leave.
        $employee = $this->createEmployee(['last_name' => 'Nodatagap', 'date_hired' => '2026-06-30']);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(1, $row['unfiled_count']);
        $this->assertTrue($row['unfiled_leave_no_data']);
        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertStringContainsString('No DTR data recorded this month', $row['remarks']);
        $this->assertStringNotContainsString('Absent (Unfiled Leave)', $row['remarks']);
    }

    public function test_unfiled_leave_no_data_flag_not_set_when_employee_has_other_punches_that_month(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Partialdata', 'date_hired' => '2026-06-30']);
        // A real punch record earlier in the month proves biometric import is working
        // for this employee - June 30 itself is still genuinely unexplained.
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(1, $row['unfiled_count']);
        $this->assertFalse($row['unfiled_leave_no_data']);
        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertStringContainsString('30-Absent (Unfiled Leave)', $row['remarks']);
    }

    public function test_unfiled_leave_no_data_flag_not_set_when_fully_covered_by_leave(): void
    {
        $this->travelTo(Carbon::parse('2026-07-15 08:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Fullycovered', 'date_hired' => '2026-06-30']);
        $leave = LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'start_date' => '2026-06-30',
            'end_date' => '2026-06-30',
            'reason' => 'Test',
            'status' => 'approved',
        ]);
        LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => '2026-06-30', 'is_cancelled' => false]);

        $row = $this->unofficialExitRowFor($employee);

        $this->assertSame(0, $row['unofficial_exit_count']);
        $this->assertSame(0, $row['unfiled_count']);
        $this->assertFalse($row['unofficial_exit_no_data']);
        $this->assertFalse($row['unfiled_leave_no_data']);
    }

    public function test_daily_time_records_imputes_undertime_for_missing_pm_out(): void
    {
        $employee = $this->createEmployee(['last_name' => 'Imputedut']);
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame(240, $row['undertime_minutes']); // 13:00 -> 17:00 shift end
        $this->assertTrue($row['is_undertime']);
        $this->assertTrue($row['is_pm_out_undertime']);
    }

    public function test_daily_time_records_shows_absent_row_for_uncovered_workday_with_no_punches(): void
    {
        // 2026-06-15 is a Monday (scheduled workday under the default Mon-Fri
        // global schedule); freeze "now" well past its shift end so the
        // uncovered-day pass's shiftEnded gate fires.
        $this->travelTo(Carbon::parse('2026-06-16 09:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Nopunches']);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row, 'A scheduled workday with zero punches must still appear in the DTR list.');
        $this->assertSame('Missing', $row['time_in_am']);
        $this->assertSame('Missing', $row['time_out_pm']);
        $this->assertStringContainsString('Absent', $row['status_badge']);
    }

    public function test_daily_time_records_shows_placeholder_row_for_uncovered_workday_whose_shift_has_not_ended_yet(): void
    {
        // Same scheduled workday as above, but "now" is still mid-shift on
        // that very date - it must not be flagged Absent before it's over,
        // but the date still gets a row so the month renders completely.
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Inprogress']);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row, 'Every date in the period must render a row, even a workday still in progress.');
        $this->assertSame('-', $row['time_in_am']);
        $this->assertStringNotContainsString('Absent', $row['status_badge']);
        $this->assertStringNotContainsString('Missing', $row['status_badge']);
    }

    public function test_daily_time_records_shows_rest_day_row_for_ordinary_weekend(): void
    {
        // 2026-06-13 is a Saturday under the default Mon-Fri global schedule
        // with no overrides - a non-workday, but the month should still
        // render a row for it instead of omitting the date entirely.
        $this->travelTo(Carbon::parse('2026-06-16 09:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Weekenddate']);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-13')->format('M d, Y (D)'));

        $this->assertNotNull($row, 'An ordinary weekend date must still render a row.');
        $this->assertStringContainsString('Rest Day', $row['status_badge']);
    }

    public function test_daily_time_records_shows_work_suspended_row_for_full_day_suspension_with_no_punches(): void
    {
        $this->travelTo(Carbon::parse('2026-06-16 09:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Suspendedday']);
        WorkSuspension::create([
            'suspension_date' => '2026-06-15',
            'suspension_time' => null, // null = full-day suspension
            'reason' => 'Typhoon',
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row, 'A full-day work suspension with zero punches must still appear in the DTR list.');
        $this->assertSame('WEATHER / TYPHOON', $row['time_in_am']);
        $this->assertStringContainsString('Weather / Typhoon', $row['status_badge']);
    }

    /**
     * Regression: the rest-day/field-work/WFH row-push loop's skip guard
     * checked dtrDates/leave/ETA/office order/travel order/excuse/locator
     * but never a declared WorkSuspension, so a field_work (or wfh) date
     * with zero punches always rendered "Field Work" even when the whole
     * company was suspended for that date - the suspension had no visible
     * effect at all. Fixed by deferring to the suspension-aware catch-all
     * loop whenever a non-frontline-exempt suspension covers the date.
     */
    public function test_daily_time_records_prioritizes_full_day_suspension_over_field_work_with_no_punches(): void
    {
        $this->travelTo(Carbon::parse('2026-06-16 09:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Fieldworkholiday']);

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => '2026-06-15',
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        WorkSuspension::create([
            'suspension_date' => '2026-06-15',
            'suspension_time' => null, // null = full-day suspension
            'reason' => 'Holiday',
            'type' => 'holiday',
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row, 'A suspended field_work date with zero punches must still appear in the DTR list.');
        $this->assertSame('HOLIDAY', $row['time_in_am']);
        $this->assertStringContainsString('Holiday', $row['status_badge']);
        $this->assertStringNotContainsString('Field Work', $row['status_badge']);
    }

    /**
     * A frontline-exempt employee on a field_work date must be untouched by
     * a declared suspension - isFrontlineExempt() short-circuits the new
     * guard, exactly like it does for every other suspension call site.
     */
    public function test_daily_time_records_field_work_unaffected_by_suspension_for_frontline_exempt_employee(): void
    {
        $this->travelTo(Carbon::parse('2026-06-16 09:00:00'));

        $employee = $this->createEmployee(['last_name' => 'Fieldworkfrontline', 'is_frontline' => true]);

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => '2026-06-15',
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        WorkSuspension::create([
            'suspension_date' => '2026-06-15',
            'suspension_time' => null,
            'reason' => 'Holiday',
            'type' => 'holiday',
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertStringContainsString('Field Work', $row['status_badge']);
    }

    /**
     * Regression: a field_work/wfh EmployeeShiftSchedule override used to be
     * consulted only for a date with NO dtrs row at all - the moment any real
     * punch data existed (even a single partial punch), the day fell into the
     * plain per-punch loop with no Field Work indication whatsoever. Fixed to
     * behave just like the existing ETA branch: real time for filled slots,
     * "Field Work" for the rest, zero late/undertime.
     */
    public function test_daily_time_records_shows_field_work_label_for_missing_slots_alongside_a_partial_punch(): void
    {
        $employee = $this->createEmployee(['last_name' => 'Fieldworker']);

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => '2026-06-15',
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in_am' => '08:00:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('08:00', $row['time_in_am']);
        $this->assertSame('Field Work', $row['time_out_am']);
        $this->assertSame('Field Work', $row['time_in_pm']);
        $this->assertSame('Field Work', $row['time_out_pm']);
        $this->assertSame(0, $row['late_minutes']);
        $this->assertSame(0, $row['undertime_minutes']);
        $this->assertStringContainsString('Field Work', $row['status_badge']);
    }

    /** Same partial-punch scenario as above, but for a wfh override. */
    public function test_daily_time_records_shows_wfh_label_for_missing_slots_alongside_a_partial_punch(): void
    {
        $employee = $this->createEmployee(['last_name' => 'Wfhworker']);

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => '2026-06-15',
            'shift_id' => null,
            'type' => 'wfh',
        ]);

        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in_am' => '08:00:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('08:00', $row['time_in_am']);
        $this->assertSame('Work From Home', $row['time_out_am']);
        $this->assertStringContainsString('Work From Home', $row['status_badge']);
    }

    /**
     * Regression guard: a fully-punched (all 4 slots) field-work day must show
     * the real times untouched with no "Field Work" label anywhere and no
     * special badge - it falls through to the normal status badge, exactly
     * like the ETA/Office Order/Travel Order branches already do at 4 punches.
     */
    public function test_daily_time_records_shows_real_punches_with_no_field_work_label_when_fully_punched(): void
    {
        $employee = $this->createEmployee(['last_name' => 'Fullypunched']);

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => '2026-06-15',
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'status' => 'present',
            'is_absent' => false,
        ]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row);
        $this->assertSame('08:00', $row['time_in_am']);
        $this->assertSame('12:00', $row['time_out_am']);
        $this->assertSame('13:00', $row['time_in_pm']);
        $this->assertSame('17:00', $row['time_out_pm']);
        $this->assertStringNotContainsString('Field Work', $row['status_badge']);
    }

    /**
     * Priority guard: Field Work/WFH is the lowest tier - an approved leave
     * covering the same date must still win, matching Form48ExportService's
     * own field_work/wfh priority (loses to leave/ETA/OO/excuse/locator).
     */
    public function test_daily_time_records_leave_takes_priority_over_field_work_override(): void
    {
        $employee = $this->createEmployee(['last_name' => 'Onleave']);

        EmployeeShiftSchedule::create([
            'user_id' => $employee->id,
            'date' => '2026-06-15',
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        // A dtrs row must exist for this date so the assertion actually
        // exercises the new field_work/wfh branch's priority guard inside the
        // main per-dtrs-row loop, rather than the separate pure-leave-only
        // synthetic row (which never touches EmployeeShiftSchedule at all).
        Dtr::create([
            'employee_id' => $employee->id,
            'date' => '2026-06-15',
            'time_in_am' => '08:00:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'is_absent' => false,
        ]);

        $leave = LeaveRequest::create([
            'user_id' => $employee->id,
            'leave_type' => 'VL',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-15',
            'reason' => 'Test',
            'status' => 'approved',
        ]);
        LeaveDate::create(['leave_request_id' => $leave->id, 'leave_date' => '2026-06-15', 'is_cancelled' => false]);

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]));

        $response->assertOk();
        $row = collect($response->json('data'))
            ->firstWhere('date', Carbon::parse('2026-06-15')->format('M d, Y (D)'));

        $this->assertNotNull($row);
        // The real 08:00 AM In punch wins over the leave code (a real
        // biometric punch always takes priority when present) - but the
        // Leave *branch* still wins over Field Work as the priority source,
        // which is what status_badge below actually verifies.
        $this->assertSame('08:00', $row['time_in_am']);
        $this->assertStringContainsString('On Leave', $row['status_badge']);
        $this->assertStringNotContainsString('Field Work', $row['status_badge']);
    }

    // ── Department Head / Administrative Officer: grant-gated, dept-scoped access ──

    private function makeDepartment(string $name): Department
    {
        return Department::create([
            'DeptCode' => strtoupper(str_replace(' ', '_', $name)),
            'Dept_name' => $name,
            'Designation' => $name,
        ]);
    }

    public function test_department_head_without_grant_is_forbidden_on_all_shift_screens(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($dh)->get(route('attendance.shifts'))->assertStatus(403);
        $this->actingAs($dh)->get(route('attendance.schedules'))->assertStatus(403);
        $this->actingAs($dh)->get(route('attendance.shift-schedule.index'))->assertStatus(403);
    }

    public function test_time_keeper_can_grant_and_revoke_shift_management_access(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->post(route('attendance.shift-access.grant', $deptA))->assertRedirect();
        $this->assertTrue($dh->refresh()->hasShiftManagementAccess());

        $this->actingAs($dh)->get(route('attendance.shifts'))->assertStatus(200);

        $this->actingAs($tk)->post(route('attendance.shift-access.revoke', $deptA))->assertRedirect();
        $this->assertFalse($dh->refresh()->hasShiftManagementAccess());

        $this->actingAs($dh)->get(route('attendance.shifts'))->assertStatus(403);
    }

    public function test_granted_department_head_can_view_but_not_manage_shift_templates(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $this->actingAs($dh)->get(route('attendance.shifts'))
            ->assertStatus(200)
            ->assertDontSee('New Shift Template');

        $this->actingAs($dh)->post(route('attendance.shifts.store'), [
            'name' => 'Should Not Save', 'time_in' => '08:00', 'time_out' => '17:00',
            'break_out' => '12:00', 'break_in' => '13:00',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('shifts', ['name' => 'Should Not Save']);
    }

    public function test_granted_department_head_sees_only_own_department_employees(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'InDeptA']);
        $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'last_name' => 'InDeptB']);

        $this->actingAs($dh)->get(route('attendance.schedules'))
            ->assertSee('InDeptA')
            ->assertDontSee('InDeptB');

        $this->actingAs($dh)->get(route('attendance.shift-schedule.index'))
            ->assertSee('InDeptA')
            ->assertDontSee('InDeptB');
    }

    public function test_granted_department_head_cannot_assign_shift_outside_own_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->nightShiftModel();
        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);

        $this->actingAs($dh)
            ->put(route('attendance.schedules.update', $outsider), ['shift_id' => $shift->id])
            ->assertStatus(403);

        $this->assertNull($outsider->refresh()->shift_id);

        $this->actingAs($dh)
            ->put(route('attendance.schedules.exempt', $outsider))
            ->assertStatus(403);

        $this->assertFalse($outsider->refresh()->dtr_exempt);
    }

    public function test_granted_department_head_cannot_submit_shift_schedule_outside_own_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->actingAs($dh)->post(route('attendance.shift-schedule.store'), [
            'user_id' => $outsider->id,
            'week_start' => $weekStart,
            'assignments' => [$weekStart => 'rest'],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('employee_shift_schedules', ['user_id' => $outsider->id]);

        $shift = $this->nightShiftModel();
        $this->actingAs($dh)->post(route('attendance.shift-schedule.generate-pattern'), [
            'user_id' => $outsider->id,
            'shift_id' => $shift->id,
            'on_days' => 1,
            'off_days' => 1,
            'start_date' => $weekStart,
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertStatus(403);

        $this->assertNull($outsider->refresh()->shift_id);
    }

    public function test_granted_department_head_can_manage_own_department_employee(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->nightShiftModel();
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($dh)
            ->put(route('attendance.schedules.update', $employee), ['shift_id' => $shift->id])
            ->assertRedirect();

        $this->assertSame($shift->id, $employee->refresh()->shift_id);
        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_assigned',
            'target_id' => $employee->id,
        ]);
    }

    public function test_granted_department_head_cannot_toggle_exemption_even_for_own_department_employee(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($dh)
            ->put(route('attendance.schedules.exempt', $employee))
            ->assertStatus(403);

        $this->assertFalse($employee->refresh()->dtr_exempt);
    }

    public function test_schedules_index_hides_exempt_toggle_from_granted_department_head(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        $tk = $this->createTimeKeeper();
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $tk->id]);

        $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        // Distinct from the always-present "View" filter option text (which also
        // reads "Exempt from DTR") - the button's title attribute is unique to it.
        $buttonTitle = 'Exempt this employee from biometric/DTR (clears their shift)';

        $this->actingAs($tk)->get(route('attendance.schedules'))
            ->assertSee($buttonTitle);

        $this->actingAs($dh)->get(route('attendance.schedules', ['dept_id' => $deptA->Dept_id]))
            ->assertDontSee($buttonTitle);
    }

    public function test_granted_administrative_officer_gets_scoped_access(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $ao = $this->createAdminOfficer(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'InDeptA']);
        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'last_name' => 'InDeptB']);

        $this->actingAs($ao)->get(route('attendance.schedules'))
            ->assertSee('InDeptA')
            ->assertDontSee('InDeptB');

        $this->actingAs($ao)
            ->put(route('attendance.schedules.update', $outsider), ['shift_id' => $this->nightShiftModel()->id])
            ->assertStatus(403);
    }

    public function test_oic_covered_department_without_its_own_grant_stays_inaccessible(): void
    {
        // Access is per-department: covering deptB via OIC does not unlock it
        // unless deptB itself has been granted, even though deptA (home) is.
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        OicAssignment::create([
            'user_id' => $dh->id,
            'dept_id' => $deptB->Dept_id,
            'role' => 'department head',
            'appointed_by' => $this->createHRManager()->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'InDeptA']);
        $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'last_name' => 'InDeptB']);

        $this->actingAs($dh)->get(route('attendance.schedules'))
            ->assertSee('InDeptA')
            ->assertDontSee('InDeptB');
    }

    public function test_oic_delegated_department_head_gets_access_once_covered_department_is_granted(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $deptC = $this->makeDepartment('Dept C');
        $tk = $this->createTimeKeeper();
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $tk->id]);
        ShiftManagementGrant::create(['dept_id' => $deptB->Dept_id, 'granted_by' => $tk->id]);

        OicAssignment::create([
            'user_id' => $dh->id,
            'dept_id' => $deptB->Dept_id,
            'role' => 'department head',
            'appointed_by' => $this->createHRManager()->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'InDeptA']);
        $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'last_name' => 'InDeptB']);
        $this->createEmployee(['Dept_id' => $deptC->Dept_id, 'last_name' => 'InDeptC']);

        $this->actingAs($dh)->get(route('attendance.schedules'))
            ->assertSee('InDeptA')
            ->assertSee('InDeptB')
            ->assertDontSee('InDeptC');
    }

    public function test_revoking_department_removes_access_even_though_officer_still_holds_the_role(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        $tk = $this->createTimeKeeper();
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $tk->id]);

        $this->actingAs($dh)->get(route('attendance.shifts'))->assertStatus(200);

        $this->actingAs($tk)->post(route('attendance.shift-access.revoke', $deptA))->assertRedirect();

        $this->actingAs($dh)->get(route('attendance.shifts'))->assertStatus(403);
    }

    public function test_only_time_keeper_or_hr_manager_can_grant_or_revoke_shift_access(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($dh)->get(route('attendance.shift-access.index'))->assertStatus(403);
        $this->actingAs($dh)->post(route('attendance.shift-access.grant', $deptA))->assertStatus(403);
    }

    // ── Shift templates scoped to specific departments ─────────────────────────

    private function scopedShiftModel(string $name, array $deptIds): Shift
    {
        $shift = Shift::create([
            'name' => $name,
            'time_in' => '08:00',
            'break_out' => '12:00',
            'break_in' => '13:00',
            'time_out' => '17:00',
            'crosses_midnight' => false,
            'is_active' => true,
            'is_global' => false,
        ]);
        $shift->departments()->attach($deptIds);

        return $shift;
    }

    public function test_granted_department_head_cannot_see_shift_scoped_to_other_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->scopedShiftModel('Dept B Only', [$deptB->Dept_id]);

        $this->actingAs($dh)->get(route('attendance.shifts'))->assertDontSee($shift->name);
        $this->actingAs($dh)->get(route('attendance.schedules'))->assertDontSee($shift->name);
        $this->actingAs($dh)->get(route('attendance.shift-schedule.index'))->assertDontSee($shift->name);
    }

    public function test_granted_department_head_sees_global_and_own_department_shift_templates(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $global = $this->nightShiftModel();
        $ownScoped = $this->scopedShiftModel('Dept A Only', [$deptA->Dept_id]);

        $this->actingAs($dh)->get(route('attendance.shifts'))
            ->assertSee($global->name)
            ->assertSee($ownScoped->name);
    }

    public function test_granted_department_head_cannot_assign_shift_scoped_to_other_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->scopedShiftModel('Dept B Only', [$deptB->Dept_id]);
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($dh)
            ->put(route('attendance.schedules.update', $employee), ['shift_id' => $shift->id])
            ->assertStatus(403);

        $this->assertNull($employee->refresh()->shift_id);
    }

    public function test_granted_department_head_cannot_submit_shift_schedule_entry_with_out_of_scope_shift(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->scopedShiftModel('Dept B Only', [$deptB->Dept_id]);
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->actingAs($dh)->post(route('attendance.shift-schedule.store'), [
            'user_id' => $employee->id,
            'week_start' => $weekStart,
            'assignments' => [$weekStart => (string) $shift->id],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('employee_shift_schedules', ['user_id' => $employee->id]);
    }

    public function test_granted_department_head_cannot_generate_pattern_with_out_of_scope_shift(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->scopedShiftModel('Dept B Only', [$deptB->Dept_id]);
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->actingAs($dh)->post(route('attendance.shift-schedule.generate-pattern'), [
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
            'on_days' => 1,
            'off_days' => 1,
            'start_date' => $weekStart,
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertStatus(403);

        $this->assertNull($employee->refresh()->shift_id);
    }

    public function test_generate_pattern_writes_an_open_ended_shift_assignment(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = $this->nightShiftModel();
        $employee = $this->createEmployee();
        $start = now()->startOfWeek(Carbon::MONDAY)->toDateString();

        $this->actingAs($tk)->post(route('attendance.shift-schedule.generate-pattern'), [
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
            'on_days' => 1,
            'off_days' => 1,
            'start_date' => $start,
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertRedirect();

        $this->assertSame($shift->id, $employee->refresh()->shift_id);
        $this->assertDatabaseHas('shift_assignments', [
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
            'effective_from' => $start,
            'effective_until' => null,
        ]);
    }

    public function test_generate_pattern_supersedes_a_stale_future_shift_assignment(): void
    {
        $tk = $this->createTimeKeeper();
        $staleShift = $this->nightShiftModel();
        $rotationShift = $this->twentyFourHourShiftModel();
        $employee = $this->createEmployee();

        $this->travelTo(Carbon::parse('2026-07-10'));

        // Employee already has a future-dated assignment from the Shift
        // Assignment screen that overlaps the rotation's date range.
        app(ShiftAssignmentService::class)->assign(
            $employee, $staleShift->id, Carbon::parse('2026-08-01'), null, null
        );

        $this->actingAs($tk)->post(route('attendance.shift-schedule.generate-pattern'), [
            'user_id' => $employee->id,
            'shift_id' => $rotationShift->id,
            'on_days' => 1,
            'off_days' => 0,
            'start_date' => '2026-08-10',
            'end_date' => '2026-08-12',
            'no_break' => true,
        ])->assertRedirect();

        // The stale row must have been truncated, not left to keep winning.
        $this->assertDatabaseHas('shift_assignments', [
            'user_id' => $employee->id,
            'shift_id' => $staleShift->id,
            'effective_until' => '2026-08-09',
        ]);

        $onDay = WorkSchedule::forUserOnDate($employee, Carbon::parse('2026-08-11'));
        $this->assertSame(
            '08:00', $onDay->workStart,
            'The rotation shift (08:00 start), not the stale future assignment (22:00 start), must resolve for an "on" day.'
        );
    }

    public function test_generate_pattern_recomputes_dtr_rows_dated_after_the_rotation_end_date(): void
    {
        $tk = $this->createTimeKeeper();
        $employee = $this->createEmployee();
        $shift = Shift::create([
            'name' => 'Rotation Day Shift',
            'time_in' => '08:00',
            'break_out' => '12:00',
            'break_in' => '13:00',
            'time_out' => '17:00',
            'crosses_midnight' => false,
            'is_active' => true,
        ]);

        // Punches dated well after the rotation form's own end_date - the
        // ShiftAssignment writeRotationForEmployee() writes is open-ended, so
        // it governs this date too, but the old bounded recomputeDtr($start,
        // $end) call never reached it, leaving this dtrs row perpetually
        // stale/nonexistent.
        $lateDate = '2026-08-20';
        foreach (['08:00:00', '12:00:00', '13:00:00', '17:00:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $employee->id,
                'emp_no' => $employee->EmpNo,
                'logdate' => $lateDate,
                'logtime' => $time,
                'in_out' => 'IN',
            ]);
        }

        $this->assertDatabaseMissing('dtrs', ['employee_id' => $employee->id, 'date' => $lateDate]);

        $this->actingAs($tk)->post(route('attendance.shift-schedule.generate-pattern'), [
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
            'on_days' => 1,
            'off_days' => 0,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-03',
        ])->assertRedirect();

        $this->assertDatabaseHas('dtrs', [
            'employee_id' => $employee->id,
            'date' => $lateDate,
            'time_in_am' => '08:00:00',
            'time_out_pm' => '17:00:00',
        ]);
    }

    /**
     * A shift with time_in == time_out (Shift::isFullDayCrossing()) needs
     * no_break=true or AttendanceMatcher misscores the departure punch (see
     * CLAUDE.md "Shift management"). Both entry points must reject the
     * misconfigured submission wholesale rather than silently writing a
     * rotation that will never fold punches into a single 24h row.
     */
    public function test_generate_pattern_rejects_a_full_day_shift_without_no_break(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = $this->twentyFourHourShiftModel();
        $employee = $this->createEmployee();

        $this->actingAs($tk)->post(route('attendance.shift-schedule.generate-pattern'), [
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
            'on_days' => 1,
            'off_days' => 2,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-08',
            // no_break intentionally omitted.
        ])->assertSessionHasErrors('no_break');

        $this->assertDatabaseMissing('shift_assignments', ['user_id' => $employee->id]);
        $this->assertDatabaseMissing('employee_shift_schedules', ['user_id' => $employee->id]);
    }

    public function test_generate_pattern_bulk_rejects_a_full_day_shift_without_no_break(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = $this->twentyFourHourShiftModel();
        $employee = $this->createEmployee();

        $this->actingAs($tk)->post(route('attendance.shift-schedule.generate-pattern-bulk'), [
            'user_ids' => [$employee->id],
            'shift_id' => $shift->id,
            'on_days' => 1,
            'off_days' => 2,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-08',
            'no_break' => false,
        ])->assertSessionHasErrors('no_break');

        $this->assertDatabaseMissing('shift_assignments', ['user_id' => $employee->id]);
    }

    /** An ordinary (non-24h) shift must never trip the full-day-crossing guard, no_break or not. */
    public function test_generate_pattern_allows_an_ordinary_shift_without_no_break(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = $this->nightShiftModel();
        $employee = $this->createEmployee();

        $this->actingAs($tk)->post(route('attendance.shift-schedule.generate-pattern'), [
            'user_id' => $employee->id,
            'shift_id' => $shift->id,
            'on_days' => 1,
            'off_days' => 2,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-08',
        ])->assertSessionDoesntHaveErrors('no_break');

        $this->assertDatabaseHas('shift_assignments', ['user_id' => $employee->id, 'shift_id' => $shift->id]);
    }

    public function test_time_keeper_bulk_generates_rotation_for_checked_employees_only(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = $this->twentyFourHourShiftModel();
        $checked1 = $this->createEmployee();
        $checked2 = $this->createEmployee();
        $unchecked = $this->createEmployee();

        $this->actingAs($tk)->post(route('attendance.shift-schedule.generate-pattern-bulk'), [
            'user_ids' => [$checked1->id, $checked2->id],
            'shift_id' => $shift->id,
            'on_days' => 2,
            'off_days' => 2,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-08',
            'no_break' => true,
        ])->assertRedirect();

        foreach ([$checked1, $checked2] as $emp) {
            $this->assertDatabaseHas('shift_assignments', [
                'user_id' => $emp->id,
                'shift_id' => $shift->id,
                'effective_from' => '2026-08-01',
                'effective_until' => null,
            ]);
            // Days 3-4 (2026-08-03, 2026-08-04) are the first off cycle (on_days=2, off_days=2).
            $this->assertDatabaseHas('employee_shift_schedules', [
                'user_id' => $emp->id, 'date' => '2026-08-03', 'shift_id' => null, 'type' => 'rest',
            ]);
            $this->assertDatabaseHas('hr_audit_trails', [
                'module' => 'shift_management',
                'action' => 'rotation_generated',
                'target_id' => $emp->id,
            ]);
        }

        $this->assertDatabaseMissing('shift_assignments', ['user_id' => $unchecked->id]);
        $this->assertDatabaseMissing('employee_shift_schedules', ['user_id' => $unchecked->id]);
    }

    public function test_generate_pattern_bulk_dispatches_recompute_job_for_checked_employees_only(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $shift = $this->twentyFourHourShiftModel();
        $checked1 = $this->createEmployee();
        $checked2 = $this->createEmployee();
        $unchecked = $this->createEmployee();

        $this->actingAs($tk)->post(route('attendance.shift-schedule.generate-pattern-bulk'), [
            'user_ids' => [$checked1->id, $checked2->id],
            'shift_id' => $shift->id,
            'on_days' => 2,
            'off_days' => 2,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-08',
            'no_break' => true,
        ])->assertRedirect();

        // The rotation's ShiftAssignment is open-ended, so recompute must
        // cover each employee's full attendance history (not just [start,
        // end]) - deferred to the same queued job bulkAssign()/bulkRemove()
        // already use for the identical underlying assign() mutation.
        Queue::assertPushed(BulkShiftRecomputeJob::class, function ($job) use ($checked1, $checked2, $unchecked) {
            return in_array($checked1->id, $job->userIds, true)
                && in_array($checked2->id, $job->userIds, true)
                && ! in_array($unchecked->id, $job->userIds, true);
        });
    }

    public function test_granted_department_head_bulk_generates_rotation_scoped_to_own_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->twentyFourHourShiftModel();
        $inDept = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);

        $this->actingAs($dh)->post(route('attendance.shift-schedule.generate-pattern-bulk'), [
            'user_ids' => [$inDept->id],
            'shift_id' => $shift->id,
            'on_days' => 1,
            'off_days' => 1,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-03',
            'no_break' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('shift_assignments', ['user_id' => $inDept->id, 'shift_id' => $shift->id]);

        // Attempting to include an outsider rejects the whole request - no partial writes.
        $this->actingAs($dh)->post(route('attendance.shift-schedule.generate-pattern-bulk'), [
            'user_ids' => [$inDept->id, $outsider->id],
            'shift_id' => $shift->id,
            'on_days' => 1,
            'off_days' => 1,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-03',
            'no_break' => true,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('shift_assignments', ['user_id' => $outsider->id]);
        $this->assertDatabaseMissing('shift_assignments', ['user_id' => $inDept->id, 'effective_from' => '2026-09-01']);
    }

    public function test_bulk_generate_rejects_shift_out_of_scope_for_any_selected_employee_before_writing(): void
    {
        // A Department Head covering two granted departments (home + OIC) can
        // pick employees from both in one bulk request - the shift here is
        // scoped to only one of those departments, so the request must be
        // rejected wholesale rather than silently skipping the bad employee.
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $tk = $this->createTimeKeeper();
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $tk->id]);
        ShiftManagementGrant::create(['dept_id' => $deptB->Dept_id, 'granted_by' => $tk->id]);

        OicAssignment::create([
            'user_id' => $dh->id,
            'dept_id' => $deptB->Dept_id,
            'role' => 'department head',
            'appointed_by' => $this->createHRManager()->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $outOfScopeShift = $this->scopedShiftModel('Dept B Only', [$deptB->Dept_id]);
        $inScope = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);
        $outOfScope = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($dh)->post(route('attendance.shift-schedule.generate-pattern-bulk'), [
            'user_ids' => [$inScope->id, $outOfScope->id],
            'shift_id' => $outOfScopeShift->id,
            'on_days' => 1,
            'off_days' => 1,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-03',
        ])->assertStatus(403);

        // Neither employee got a write, including the one whose department the shift IS scoped to.
        $this->assertDatabaseMissing('shift_assignments', ['user_id' => $inScope->id]);
        $this->assertDatabaseMissing('shift_assignments', ['user_id' => $outOfScope->id]);
    }

    public function test_time_keeper_sees_and_manages_all_shift_templates_regardless_of_scope(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $tk = $this->createTimeKeeper();

        $shift = $this->scopedShiftModel('Dept B Only', [$deptB->Dept_id]);
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($tk)->get(route('attendance.shifts'))->assertSee($shift->name);

        $this->actingAs($tk)
            ->put(route('attendance.schedules.update', $employee), ['shift_id' => $shift->id])
            ->assertRedirect();

        $this->assertSame($shift->id, $employee->refresh()->shift_id);
    }

    // ── Work Days pattern (now a ShiftAssignment property, not a Shift one) ──────

    public function test_shift_assignment_without_work_days_defaults_to_mon_fri(): void
    {
        $assignment = new ShiftAssignment(['work_days' => null]);

        $this->assertSame('Mon-Fri', $assignment->workDaysLabel());
        // 2026-04-07 is a Tuesday, 2026-04-04 is a Saturday.
        $this->assertTrue($assignment->worksOnDate(Carbon::parse('2026-04-07')));
        $this->assertFalse($assignment->worksOnDate(Carbon::parse('2026-04-04')));
    }

    public function test_shift_assignment_works_on_date_matches_its_work_days_pattern(): void
    {
        $assignment = new ShiftAssignment(['work_days' => [2, 4]]);

        // 2026-04-07 is a Tuesday, 2026-04-08 is a Wednesday.
        $this->assertTrue($assignment->worksOnDate(Carbon::parse('2026-04-07')));
        $this->assertFalse($assignment->worksOnDate(Carbon::parse('2026-04-08')));
        $this->assertSame('Tue, Thu', $assignment->workDaysLabel());
    }

    public function test_work_days_label_covers_mon_sat_and_every_day(): void
    {
        $monSat = new ShiftAssignment(['work_days' => [1, 2, 3, 4, 5, 6]]);
        $everyDay = new ShiftAssignment(['work_days' => [0, 1, 2, 3, 4, 5, 6]]);

        $this->assertSame('Mon-Sat', $monSat->workDaysLabel());
        $this->assertSame('Every day', $everyDay->workDaysLabel());
    }

    public function test_days_of_week_label_handles_null_and_custom_lists(): void
    {
        $this->assertNull(ShiftAssignment::daysOfWeekLabel(null));
        $this->assertSame('Mon-Fri', ShiftAssignment::daysOfWeekLabel([1, 2, 3, 4, 5]));
        $this->assertSame('Tue, Thu', ShiftAssignment::daysOfWeekLabel([2, 4]));
        $this->assertSame('Mon, Wed, Fri', ShiftAssignment::daysOfWeekLabel([1, 3, 5]));
    }

    public function test_work_schedule_is_workday_falls_back_to_weekday_when_no_shift_assigned(): void
    {
        $emp = $this->createEmployee();

        // 2026-04-07 is a Tuesday, 2026-04-04 is a Saturday.
        $this->assertTrue(WorkSchedule::isWorkday($emp, Carbon::parse('2026-04-07')));
        $this->assertFalse(WorkSchedule::isWorkday($emp, Carbon::parse('2026-04-04')));
    }

    /**
     * Regression guard: users.shift_id with no covering shift_assignments row
     * (the createEmployee(['shift_id' => ...]) test-only bypass, since that
     * helper uses forceCreate() and never writes a shift_assignments row)
     * must fall back to a plain Mon-Fri weekday, not consult a Shift-level
     * work_days pattern that no longer exists.
     */
    public function test_work_schedule_is_workday_falls_back_to_weekday_when_shift_id_has_no_covering_assignment(): void
    {
        $shift = $this->nightShiftModel();
        $emp = $this->createEmployee(['shift_id' => $shift->id]);

        // 2026-04-07 is a Tuesday, 2026-04-04 is a Saturday.
        $this->assertTrue(WorkSchedule::isWorkday($emp, Carbon::parse('2026-04-07')));
        $this->assertFalse(WorkSchedule::isWorkday($emp, Carbon::parse('2026-04-04')));
    }

    public function test_work_schedule_is_workday_uses_shift_pattern_when_assigned(): void
    {
        $shift = Shift::create([
            'name' => 'Mon-Sat', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        $emp = $this->createEmployee();
        app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-01-01'), null, null, null, [1, 2, 3, 4, 5, 6]
        );

        // 2026-04-04 is a Saturday, 2026-04-05 is a Sunday.
        $this->assertTrue(WorkSchedule::isWorkday($emp, Carbon::parse('2026-04-04')));
        $this->assertFalse(WorkSchedule::isWorkday($emp, Carbon::parse('2026-04-05')));
    }

    public function test_work_schedule_is_workday_explicit_rest_override_wins_over_shift_pattern(): void
    {
        $shift = Shift::create([
            'name' => 'Mon-Sat', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        $emp = $this->createEmployee(['shift_id' => $shift->id]);

        // 2026-04-04 is a Saturday - overridden to rest regardless of any shift pattern.
        EmployeeShiftSchedule::create([
            'user_id' => $emp->id, 'date' => '2026-04-04', 'shift_id' => null, 'type' => 'rest',
        ]);

        $this->assertFalse(WorkSchedule::isWorkday($emp, Carbon::parse('2026-04-04')));
    }

    public function test_work_schedule_is_workday_explicit_field_work_override_wins_over_off_day(): void
    {
        $shift = Shift::create([
            'name' => 'Mon-Fri', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        $emp = $this->createEmployee(['shift_id' => $shift->id]);

        // 2026-04-04 is a Saturday - overridden via field work regardless of any shift pattern.
        EmployeeShiftSchedule::create([
            'user_id' => $emp->id, 'date' => '2026-04-04', 'shift_id' => null, 'type' => 'field_work',
        ]);

        $this->assertTrue(WorkSchedule::isWorkday($emp, Carbon::parse('2026-04-04')));
    }

    // ── No Break (2-punch), now a per-assignment property ────────────────────────

    public function test_shift_assignment_no_break_flows_into_work_schedule_via_from_user_on_date(): void
    {
        $shift = Shift::create([
            'name' => 'Guard Duty', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        $noBreakEmp = $this->createEmployee();
        $fullBreakEmp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign($noBreakEmp, $shift->id, Carbon::parse('2026-01-01'), null, null, null, null, true);
        app(ShiftAssignmentService::class)->assign($fullBreakEmp, $shift->id, Carbon::parse('2026-01-01'), null, null, null, null, false);

        $this->assertTrue(WorkSchedule::forUserOnDate($noBreakEmp, Carbon::parse('2026-04-07'))->noBreak);
        $this->assertFalse(WorkSchedule::forUserOnDate($fullBreakEmp, Carbon::parse('2026-04-07'))->noBreak);
    }

    /**
     * A per-date EmployeeShiftSchedule override has its own no_break column,
     * independent of whatever the same shift's ongoing shift_assignments row
     * carries - it defaults to false when not explicitly set, same as any
     * other boolean column, rather than inheriting the assignment's value.
     */
    public function test_employee_shift_schedule_override_defaults_to_full_break_when_not_set(): void
    {
        $shift = Shift::create([
            'name' => 'Guard Duty', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        $emp = $this->createEmployee();
        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::parse('2026-01-01'), null, null, null, null, true);

        EmployeeShiftSchedule::create(['user_id' => $emp->id, 'date' => '2026-04-08', 'shift_id' => $shift->id]);

        $this->assertTrue(WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-04-07'))->noBreak, 'Normal assignment resolution keeps no_break = true.');
        $this->assertFalse(WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-04-08'))->noBreak, 'The override row never set no_break, so it defaults to false.');
    }

    public function test_employee_shift_schedule_override_no_break_flows_into_work_schedule(): void
    {
        $shift = Shift::create([
            'name' => 'Guard Duty', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00',
        ]);
        $emp = $this->createEmployee();

        EmployeeShiftSchedule::create([
            'user_id' => $emp->id, 'date' => '2026-04-08', 'shift_id' => $shift->id, 'no_break' => true,
        ]);

        $this->assertTrue(WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-04-08'))->noBreak);
    }

    // ── No Break checkbox on the week-grid and weekly-pattern forms ─────────

    public function test_store_week_schedule_with_no_break_writes_it_on_the_assigned_shift_day(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        $this->actingAs($tk)->post(route('attendance.shift-schedule.store'), [
            'user_id' => $emp->id,
            'week_start' => '2026-08-03',
            'assignments' => ['2026-08-03' => (string) $shift->id, '2026-08-04' => 'rest'],
            'no_break' => ['2026-08-03' => '1'],
        ])->assertRedirect();

        $this->assertDatabaseHas('employee_shift_schedules', [
            'user_id' => $emp->id, 'date' => '2026-08-03', 'shift_id' => $shift->id, 'no_break' => true,
        ]);
        $this->assertDatabaseHas('employee_shift_schedules', [
            'user_id' => $emp->id, 'date' => '2026-08-04', 'shift_id' => null, 'type' => 'rest', 'no_break' => false,
        ]);

        $emp->refresh();
        $this->assertTrue(WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-08-03'))->noBreak);
    }

    public function test_bulk_save_week_schedule_with_no_break_writes_it_for_every_checked_employee(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp1 = $this->createEmployee();
        $emp2 = $this->createEmployee();

        $this->actingAs($tk)->post(route('attendance.shift-schedule.store-bulk'), [
            'user_ids' => [$emp1->id, $emp2->id],
            'week_start' => '2026-08-03',
            'assignments' => ['2026-08-03' => (string) $shift->id],
            'no_break' => ['2026-08-03' => '1'],
        ])->assertRedirect();

        foreach ([$emp1, $emp2] as $emp) {
            $this->assertDatabaseHas('employee_shift_schedules', [
                'user_id' => $emp->id, 'date' => '2026-08-03', 'shift_id' => $shift->id, 'no_break' => true,
            ]);
        }
    }

    public function test_apply_weekly_pattern_with_no_break_writes_it_on_days_assigned_a_shift(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        // Monday (iso 1) gets the shift; the rest of the pattern is left blank/default.
        $this->actingAs($tk)->post(route('attendance.shift-schedule.apply-weekly-pattern'), [
            'user_id' => $emp->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-09',
            'pattern' => [1 => (string) $shift->id, 2 => '', 3 => '', 4 => '', 5 => '', 6 => 'rest', 7 => 'rest'],
            'no_break' => [1 => '1'],
        ])->assertRedirect();

        $this->assertDatabaseHas('employee_shift_schedules', [
            'user_id' => $emp->id, 'date' => '2026-08-03', 'shift_id' => $shift->id, 'no_break' => true,
        ]);
        $this->assertDatabaseHas('employee_shift_schedules', [
            'user_id' => $emp->id, 'date' => '2026-08-08', 'shift_id' => null, 'type' => 'rest', 'no_break' => false,
        ]);

        $emp->refresh();
        $this->assertTrue(WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-08-03'))->noBreak);
    }

    /**
     * Regression: before no_break became per-day, the week-grid's single
     * checkbox was never pre-filled from existing state and defaulted
     * unchecked on every page load - so any later resave of the week (even
     * one just touching an unrelated day) silently reset no_break back to
     * false for every shift-carrying day in it, since a real browser would
     * submit the checkbox's own (always-unchecked) current state. This is
     * exactly what corrupted real DTR data in production. The per-day
     * checkbox now pre-fills from that day's own currently saved state, so
     * a plain resubmission - which just resends whatever the page actually
     * shows - preserves it instead of silently discarding it.
     */
    public function test_week_grid_no_break_checkbox_prefills_and_survives_a_resave(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        // First save: shift assigned Monday, No Break checked for that day.
        $this->actingAs($tk)->post(route('attendance.shift-schedule.store'), [
            'user_id' => $emp->id,
            'week_start' => '2026-08-03',
            'assignments' => ['2026-08-03' => (string) $shift->id],
            'no_break' => ['2026-08-03' => '1'],
        ])->assertRedirect();

        $this->assertDatabaseHas('employee_shift_schedules', [
            'user_id' => $emp->id, 'date' => '2026-08-03', 'shift_id' => $shift->id, 'no_break' => true,
        ]);

        // The page must pre-fill that day's checkbox as checked from the
        // already-saved state - this is what a real browser would resubmit.
        $response = $this->actingAs($tk)->get(route('attendance.shift-schedule.index', [
            'employee_id' => $emp->id,
            'week_start' => '2026-08-03',
        ]));
        $response->assertSee('id="no-break-2026-08-03" checked', false);

        // Resaving the week - submitting exactly what the pre-filled page
        // shows, i.e. touching an unrelated day but leaving Monday's
        // checkbox checked - must not reset no_break back to false.
        $this->actingAs($tk)->post(route('attendance.shift-schedule.store'), [
            'user_id' => $emp->id,
            'week_start' => '2026-08-03',
            'assignments' => ['2026-08-03' => (string) $shift->id, '2026-08-04' => 'rest'],
            'no_break' => ['2026-08-03' => '1'],
        ])->assertRedirect();

        $this->assertDatabaseHas('employee_shift_schedules', [
            'user_id' => $emp->id, 'date' => '2026-08-03', 'shift_id' => $shift->id, 'no_break' => true,
        ]);
    }

    // ── Bulk shift assignment (checkbox-selected employees) ─────────────────

    public function test_time_keeper_bulk_assigns_shift_to_checked_employees_only(): void
    {
        Queue::fake();

        $deptA = $this->makeDepartment('Dept A');
        $tk = $this->createTimeKeeper();
        $shift = $this->nightShiftModel();

        $checked1 = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $checked2 = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $unchecked = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $shift->id,
            'user_ids' => [$checked1->id, $checked2->id],
            'effective_from' => Carbon::today()->toDateString(),
            'effective_until' => Carbon::today()->addYear()->toDateString(),
        ])->assertRedirect();

        $this->assertSame($shift->id, $checked1->refresh()->shift_id);
        $this->assertSame($shift->id, $checked2->refresh()->shift_id);
        $this->assertNull($unchecked->refresh()->shift_id, 'An unchecked employee must be untouched.');

        $this->assertDatabaseHas('shift_assignments', ['user_id' => $checked1->id, 'shift_id' => $shift->id, 'effective_until' => Carbon::today()->addYear()->toDateString()]);
        $this->assertDatabaseHas('shift_assignments', ['user_id' => $checked2->id, 'shift_id' => $shift->id, 'effective_until' => Carbon::today()->addYear()->toDateString()]);
        $this->assertDatabaseMissing('shift_assignments', ['user_id' => $unchecked->id]);

        Queue::assertPushed(BulkShiftRecomputeJob::class, function ($job) use ($checked1, $checked2, $unchecked) {
            return in_array($checked1->id, $job->userIds, true)
                && in_array($checked2->id, $job->userIds, true)
                && ! in_array($unchecked->id, $job->userIds, true);
        });

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_assigned',
            'target_id' => $checked1->id,
        ]);
        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_assigned',
            'target_id' => $checked2->id,
        ]);
    }

    public function test_bulk_assign_with_days_of_week_gives_one_employee_two_concurrent_shifts(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $mwfShift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $tthShift = Shift::create([
            'name' => 'TTH 8:30-5:30', 'time_in' => '08:30', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:30', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $mwfShift->id,
            'user_ids' => [$emp->id],
            'days_of_week' => [1, 3, 5],
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $tthShift->id,
            'user_ids' => [$emp->id],
            'days_of_week' => [2, 4],
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        $mwfRow = ShiftAssignment::where('user_id', $emp->id)->where('shift_id', $mwfShift->id)->firstOrFail();
        $this->assertSame([1, 3, 5], $mwfRow->days_of_week);
        $tthRow = ShiftAssignment::where('user_id', $emp->id)->where('shift_id', $tthShift->id)->firstOrFail();
        $this->assertSame([2, 4], $tthRow->days_of_week);

        $monday = WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-08-03'));
        $this->assertSame('07:00', $monday->workStart);
        $tuesday = WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-08-04'));
        $this->assertSame('08:30', $tuesday->workStart);

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_assigned',
            'target_id' => $emp->id,
        ]);
    }

    /**
     * Bulk-assign rollout for a "Field Work" shift: the documented two-
     * submission pattern (Monday in_only, then Friday out_only, same
     * employees, same template) produces exactly the two concurrent
     * ShiftAssignment rows WeeklyPunchPairReconciliationService expects.
     */
    public function test_bulk_assign_with_punch_requirement_gives_field_work_monday_friday_pair(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $fieldWorkShift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        // One submission: Work Days = Mon+Fri, top-level Punch Requirement
        // left at the default "both", with Monday/Friday overridden via the
        // per-day grid - EmployeeScheduleController::bulkAssign() now routes
        // through ShiftAssignmentService::assignGroupedByPunchRequirement(),
        // which splits this into the same two rows the old two-submission
        // flow used to require.
        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $fieldWorkShift->id,
            'user_ids' => [$emp->id],
            'work_days' => [1, 5],
            'punch_requirement' => 'both',
            'punch_requirement_by_day' => [1 => 'in_only', 5 => 'out_only'],
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        $rows = ShiftAssignment::where('user_id', $emp->id)->get();
        $this->assertCount(2, $rows);

        $mondayRow = $rows->firstWhere('days_of_week', [1]);
        $this->assertNotNull($mondayRow);
        $this->assertSame('in_only', $mondayRow->punch_requirement);

        $fridayRow = $rows->firstWhere('days_of_week', [5]);
        $this->assertNotNull($fridayRow);
        $this->assertSame('out_only', $fridayRow->punch_requirement);

        $monday = WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-08-03'));
        $this->assertSame('in_only', $monday->punchRequirement);
        $friday = WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-08-07'));
        $this->assertSame('out_only', $friday->punchRequirement);

        // The two groups differ in days_of_week/punch_requirement, so they
        // must be logged under two distinct batch_ids - sharing one would
        // violate ShiftLogController::buildLogPage()'s ANY_VALUE() invariant
        // that every row in a batch shares identical details.
        $auditRows = HRAuditTrail::where('module', 'shift_management')
            ->where('action', 'shift_assigned')
            ->where('target_id', $emp->id)
            ->get();
        $this->assertCount(2, $auditRows);
        $this->assertNotSame($auditRows[0]->batch_id, $auditRows[1]->batch_id);
        $this->assertNotNull($auditRows[0]->batch_id);
        $this->assertNotNull($auditRows[1]->batch_id);
    }

    /**
     * The common case (no per-day punch_requirement override) must still
     * collapse to exactly one ShiftAssignment row with days_of_week=null
     * (unrestricted) - the same shape assign() has always produced directly
     * - so introducing the grouped-assign path doesn't change ordinary
     * bulk-assign behavior for the vast majority of shifts that never touch
     * the per-day grid.
     */
    public function test_bulk_assign_without_punch_requirement_override_still_produces_a_single_unrestricted_row(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'Regular', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $shift->id,
            'user_ids' => [$emp->id],
            'work_days' => [1, 2, 3, 4, 5],
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        $rows = ShiftAssignment::where('user_id', $emp->id)->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->days_of_week);
        $this->assertSame('both', $rows->first()->punch_requirement);

        $auditRows = HRAuditTrail::where('module', 'shift_management')
            ->where('action', 'shift_assigned')
            ->where('target_id', $emp->id)
            ->get();
        $this->assertCount(1, $auditRows);
    }

    /**
     * A Field Work Pattern shift is self-configuring server-side: even when
     * the request submits a deliberately wrong Work Days shape (a plain
     * Mon-Fri selection, no per-day overrides at all - what a Time Keeper
     * would get by leaving every default alone, or what a bypassed/broken
     * client script would send), EmployeeScheduleController::bulkAssign()
     * still forces the fixed Monday-in/Friday-out split, since correctness
     * for this shift type can't depend on the request getting it right.
     */
    public function test_bulk_assign_field_work_pair_shift_ignores_submitted_work_days_and_forces_the_pattern(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $fieldWorkShift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'time_out' => '17:00',
            'is_active' => true, 'is_field_work_pair' => true,
        ]);
        $emp = $this->createEmployee();

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $fieldWorkShift->id,
            'user_ids' => [$emp->id],
            'work_days' => [1, 2, 3, 4, 5],
            'punch_requirement' => 'both',
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        $rows = ShiftAssignment::where('user_id', $emp->id)->get();
        $this->assertCount(2, $rows);

        $mondayRow = $rows->firstWhere('days_of_week', [1]);
        $this->assertNotNull($mondayRow);
        $this->assertSame('in_only', $mondayRow->punch_requirement);

        $fridayRow = $rows->firstWhere('days_of_week', [5]);
        $this->assertNotNull($fridayRow);
        $this->assertSame('out_only', $fridayRow->punch_requirement);
    }

    /**
     * bulkAssign() must tell BulkShiftRecomputeJob to also eagerly reconcile
     * (reconcileSince = the submitted effective_from) whenever the resulting
     * groups include in_only/out_only - closing the same gap the "+ Add
     * Shift" single-employee path closes via reconcileEagerlyIfNeeded().
     * An ordinary (non-Field-Work-Pair) bulk assignment must leave
     * reconcileSince null, since it never needs this call at all.
     */
    public function test_bulk_assign_field_work_pair_shift_dispatches_job_with_reconcile_since(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $fieldWorkShift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'time_out' => '17:00',
            'is_active' => true, 'is_field_work_pair' => true,
        ]);
        $emp = $this->createEmployee();

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $fieldWorkShift->id,
            'user_ids' => [$emp->id],
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        Queue::assertPushed(BulkShiftRecomputeJob::class, fn ($job) => $job->reconcileSince === '2026-07-01');
    }

    public function test_bulk_assign_ordinary_shift_dispatches_job_with_no_reconcile_since(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'Ordinary', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $shift->id,
            'user_ids' => [$emp->id],
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        Queue::assertPushed(BulkShiftRecomputeJob::class, fn ($job) => $job->reconcileSince === null);
    }

    /**
     * Same guarantee via the per-employee "+ Add Shift" form (update()'s
     * "add" form_type), with no Work Days/punch-requirement input submitted
     * at all - the simplest possible way a Time Keeper would actually use it.
     */
    public function test_add_shift_field_work_pair_shift_needs_no_configuration(): void
    {
        $tk = $this->createTimeKeeper();
        $fieldWorkShift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'time_out' => '17:00',
            'is_active' => true, 'is_field_work_pair' => true,
        ]);
        $emp = $this->createEmployee();

        $this->actingAs($tk)->put(route('attendance.schedules.update', $emp), [
            'form_type' => 'add',
            'shift_id' => $fieldWorkShift->id,
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        $rows = ShiftAssignment::where('user_id', $emp->id)->get();
        $this->assertCount(2, $rows);
        $this->assertNotNull($rows->firstWhere('days_of_week', [1]));
        $this->assertSame('out_only', $rows->firstWhere('days_of_week', [5])->punch_requirement);
    }

    /**
     * Tue/Wed/Thu of a Field Work Pair week are a WorkSchedule::isWorkday()
     * false day-of-week gap, same as any other assignment gap - but the DTR
     * view must not collapse it into the generic "Rest Day" badge (which
     * wrongly implies a day off) the way it does for an ordinary gap. See
     * WorkSchedule::isFieldWorkPairGapDay().
     */
    public function test_dtr_view_labels_field_work_pair_gap_days_no_punch_required_not_rest_day(): void
    {
        $this->travelTo(Carbon::parse('2026-07-31 09:00:00'));

        $shift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'time_out' => '17:00',
            'is_active' => true, 'is_field_work_pair' => true,
        ]);
        $employee = $this->createEmployee(['last_name' => 'Fieldworker']);

        app(ShiftAssignmentService::class)->assignGroupedByPunchRequirement(
            $employee, $shift->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-12-31'), null,
            null, [1, 5], false, 'both', [1 => 'in_only', 5 => 'out_only']
        );

        $response = $this->actingAs($this->createTimeKeeper())
            ->getJson(route('attendance.dtr.data', [
                'employee_id' => $employee->id,
                'dtr_type' => 'monthly',
                'month' => '2026-07',
            ]));

        $response->assertOk();
        $rows = collect($response->json('data'));

        // 2026-07-06 is a Monday, 2026-07-07/08/09 are the following Tue/Wed/Thu.
        foreach (['2026-07-07', '2026-07-08', '2026-07-09'] as $dateStr) {
            $row = $rows->firstWhere('date', Carbon::parse($dateStr)->format('M d, Y (D)'));
            $this->assertNotNull($row, "$dateStr should have a row.");
            $this->assertStringContainsString('No Punch Required', $row['status_badge']);
            $this->assertStringNotContainsString('Rest Day', $row['status_badge']);
        }

        // Monday is a real workday with no punches - must not be mistaken for the gap state.
        $monday = $rows->firstWhere('date', Carbon::parse('2026-07-06')->format('M d, Y (D)'));
        $this->assertNotNull($monday);
        $this->assertStringNotContainsString('No Punch Required', $monday['status_badge']);
        $this->assertStringNotContainsString('Rest Day', $monday['status_badge']);
    }

    /**
     * assignGroupedByPunchRequirement() at the service level: a uniform
     * punch_requirement map (or none at all) must collapse to exactly one
     * assign() call/row - the regression guard for the common case not
     * changing shape - while a genuinely mixed map splits into one
     * day-restricted row per distinct value.
     */
    public function test_assign_grouped_by_punch_requirement_collapses_when_uniform_and_splits_when_mixed(): void
    {
        $shift = Shift::create([
            'name' => 'Field Work', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $service = app(ShiftAssignmentService::class);

        $uniformEmp = $this->createEmployee();
        $uniformRows = $service->assignGroupedByPunchRequirement(
            $uniformEmp, $shift->id, Carbon::parse('2026-07-01'), null, null,
            null, [1, 2, 3, 4, 5], false, 'both', []
        );
        $this->assertCount(1, $uniformRows);
        $stored = ShiftAssignment::where('user_id', $uniformEmp->id)->get();
        $this->assertCount(1, $stored);
        $this->assertNull($stored->first()->days_of_week, 'Unrestricted row shape must be preserved when nothing actually varies.');
        $this->assertSame('both', $stored->first()->punch_requirement);

        $mixedEmp = $this->createEmployee();
        $mixedRows = $service->assignGroupedByPunchRequirement(
            $mixedEmp, $shift->id, Carbon::parse('2026-07-01'), null, null,
            null, [1, 5], false, 'both', [1 => 'in_only', 5 => 'out_only']
        );
        $this->assertCount(2, $mixedRows);
        $mixedStored = ShiftAssignment::where('user_id', $mixedEmp->id)->get();
        $this->assertCount(2, $mixedStored);
        $mondayRow = $mixedStored->firstWhere('days_of_week', [1]);
        $this->assertSame('in_only', $mondayRow->punch_requirement);
        $fridayRow = $mixedStored->firstWhere('days_of_week', [5]);
        $this->assertSame('out_only', $fridayRow->punch_requirement);
    }

    /**
     * Regression for a configuration trap: days_of_week=[Mon,Wed,Fri] on a
     * row means the row only GOVERNS Mon/Wed/Fri (ShiftAssignment::appliesOnDate()),
     * so a broader work_days=[Mon..Fri] submitted alongside it was previously
     * stored as-is even though Tue/Thu could never actually be worked under
     * this row - WorkSchedule::isWorkday() silently treated them as rest days
     * regardless of what work_days said. ShiftAssignmentService::assign() now
     * forces work_days to equal days_of_week whenever the latter is set, so
     * the stored data can never lie about what will actually be worked.
     */
    public function test_assign_forces_work_days_to_match_days_of_week_when_restricted(): void
    {
        $shift = Shift::create([
            'name' => 'Day 8-5', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        $row = app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-08-03'), null, null,
            [1, 3, 5], // days_of_week: Mon/Wed/Fri only
            [1, 2, 3, 4, 5], // work_days submitted as the full Mon-Fri week
        );

        $this->assertSame([1, 3, 5], $row->days_of_week);
        $this->assertSame([1, 3, 5], $row->work_days, 'work_days must be forced to match the narrower days_of_week, not stored as the broader submitted value.');
    }

    /**
     * Regression: HTML checkboxes always submit values as strings over real
     * HTTP ("1", not 1). ShiftAssignment::appliesOnDate() used to compare
     * against Carbon::dayOfWeek (an int) with strict in_array(..., true),
     * which silently never matched anything submitted through the actual UI
     * - the assignment was created but never actually applied on any day.
     */
    public function test_add_shift_with_string_typed_days_of_week_still_resolves_correctly(): void
    {
        $this->travelTo(Carbon::parse('2026-07-20'));

        $tk = $this->createTimeKeeper();
        $mwfShift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        // Deliberately string-typed, exactly as a real browser form submits.
        $this->actingAs($tk)->put(route('attendance.schedules.update', $emp), [
            'shift_id' => (string) $mwfShift->id,
            'days_of_week' => ['1', '3', '5'],
        ])->assertRedirect();

        $row = ShiftAssignment::where('user_id', $emp->id)->firstOrFail();
        $this->assertSame([1, 3, 5], $row->days_of_week, 'Must be normalized to ints regardless of the request payload type.');

        WorkSchedule::flushShiftAssignmentMemo();
        $monday = WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-08-03'));
        $this->assertSame('07:00', $monday->workStart, 'A string-typed day scope must still resolve on its scoped weekday.');
        $this->assertTrue(WorkSchedule::isWorkday($emp, Carbon::parse('2026-08-03')));
    }

    public function test_schedules_index_shows_both_shifts_for_an_employee_with_a_concurrent_pair(): void
    {
        $tk = $this->createTimeKeeper();
        $mwfShift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $tthShift = Shift::create([
            'name' => 'TTH 8:30-5:30', 'time_in' => '08:30', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:30', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'Concurrent']);
        $single = $this->createEmployee(['last_name' => 'Single']);

        app(ShiftAssignmentService::class)->assign($emp, $mwfShift->id, Carbon::today(), null, $tk->id, [1, 3, 5]);
        app(ShiftAssignmentService::class)->assign($emp, $tthShift->id, Carbon::today(), null, $tk->id, [2, 4]);
        app(ShiftAssignmentService::class)->assign($single, $mwfShift->id, Carbon::today(), null, $tk->id);

        $response = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'Concurrent']));
        $response->assertSee('MWF 7-4');
        $response->assertSee('TTH 8:30-5:30');
        $response->assertSee('Mon, Wed, Fri');
        $response->assertSee('Tue, Thu');

        // An employee with only a single plain assignment must still show it
        // in the list - the list is the one source of truth for "what shift
        // templates does this employee have," regardless of count.
        $singleResponse = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'Single']));
        $singleResponse->assertSee('<ul class="sched-shift-list">', false);
        $singleResponse->assertSee('MWF 7-4 - Mon-Fri', false);
    }

    /**
     * Regression: the schedules index used to only load assignments
     * effective TODAY (ShiftAssignment::effectiveOn()), so a shift added with
     * a future start date - or the second half of a day-scoped combo added a
     * moment later - would silently be missing from the list even though it
     * was really there. It must show every not-yet-expired assignment
     * (current or upcoming), each labeled with its effective/expiration
     * dates.
     */
    public function test_schedules_index_shows_a_future_dated_assignment_with_its_date_range(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'Future Shift', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $tk->id
        );

        $response = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => $emp->last_name]));
        $response->assertSee('Future Shift');
        $response->assertSee('Sep 1, 2026 – Sep 30, 2026');
    }

    public function test_removing_one_shift_leaves_other_concurrent_assignment_intact(): void
    {
        $tk = $this->createTimeKeeper();
        $mwfShift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $tthShift = Shift::create([
            'name' => 'TTH 8:30-5:30', 'time_in' => '08:30', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:30', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign($emp, $mwfShift->id, Carbon::today(), null, $tk->id, [1, 3, 5]);
        app(ShiftAssignmentService::class)->assign($emp, $tthShift->id, Carbon::today(), null, $tk->id, [2, 4]);

        // "Remove" submits shift_id='' scoped to exactly the removed row's
        // days - same mechanism the × button in the UI uses.
        $this->actingAs($tk)->put(route('attendance.schedules.update', $emp), [
            'shift_id' => '',
            'days_of_week' => [2, 4],
        ])->assertRedirect();

        $rows = ShiftAssignment::forUser($emp->id)->effectiveOn(Carbon::today())->get();
        $this->assertCount(2, $rows, 'The MWF row stays; the removed TTH row becomes an explicit Standard Day row for Tue/Thu.');
        $this->assertTrue($rows->contains(fn ($r) => $r->shift_id === $mwfShift->id && $r->days_of_week === [1, 3, 5]));
        $this->assertTrue($rows->contains(fn ($r) => $r->shift_id === null && $r->days_of_week === [2, 4]));
    }

    /**
     * Regression: removing an employee's ONE AND ONLY assignment used to
     * leave behind a day-scoped "Standard Day" row (since the × button
     * always copied that row's own days_of_week into the removal request),
     * so the list never actually simplified back to the plain "Standard Day
     * (default)" state - it just showed a dated Standard Day entry instead.
     * The remove form must omit days_of_week when there's nothing else to
     * disambiguate against, fully clearing the assignment.
     */
    public function test_removing_the_only_assignment_fully_clears_to_standard_day(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'Solo']);

        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::today(), null, $tk->id, [1, 3, 5]);

        // The rendered × button's form must NOT carry days_of_week, since
        // this is the employee's only assignment.
        $page = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'Solo']));
        $formStart = strpos($page->getContent(), 'sched-remove-shift-form');
        $formChunk = substr($page->getContent(), $formStart, 600);
        $this->assertStringNotContainsString('days_of_week', $formChunk);

        // Submit exactly what that form submits (no days_of_week).
        $this->actingAs($tk)->put(route('attendance.schedules.update', $emp), [
            'form_type' => 'remove',
            'shift_id' => '',
        ])->assertRedirect();

        $rows = ShiftAssignment::forUser($emp->id)->effectiveOn(Carbon::today())->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows->first()->shift_id);
        $this->assertNull($rows->first()->days_of_week, 'Must be a plain, unscoped Standard Day row, not scoped to the removed days.');

        $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'Solo']))
            ->assertSee('Standard Day (default)')
            ->assertDontSee('<ul class="sched-shift-list">', false);
    }

    public function test_update_flash_message_names_the_submitted_shift_not_the_cached_value(): void
    {
        $tk = $this->createTimeKeeper();
        $tthShift = Shift::create([
            'name' => 'TTH 8:30-5:30', 'time_in' => '08:30', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:30', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        // Freeze on a Monday: users.shift_id won't reflect a Tue/Thu-scoped
        // assignment today, but the flash message must still name it.
        $this->travelTo(Carbon::parse('2026-08-03'));

        $response = $this->actingAs($tk)->put(route('attendance.schedules.update', $emp), [
            'shift_id' => $tthShift->id,
            'days_of_week' => [2, 4],
        ]);

        $response->assertRedirect();
        $this->assertNull($emp->refresh()->shift_id, 'Cache correctly stays null - Monday is not one of the scoped days.');
        $response->assertSessionHas('schedule_status', function ($message) {
            return str_contains($message, 'TTH 8:30-5:30') && str_contains($message, 'Tue, Thu');
        });
    }

    /**
     * A fully-expired shift_assignments row (effective_until in the past)
     * used to disappear from the Shift Assignment screen entirely - no way
     * to view or correct it. It must now show under a "History" section,
     * separate from the still-in-effect list.
     */
    public function test_schedules_index_shows_an_expired_assignment_under_history(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'Expired']);

        app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-10'), $tk->id, [1, 3, 5]
        );

        $this->travelTo(Carbon::parse('2026-07-12'));

        $response = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'Expired']));

        // Not in the current-shifts list - the employee has no not-yet-expired row.
        $response->assertSee('Standard Day (default)');
        $response->assertDontSee('<ul class="sched-shift-list">', false);

        // Shown under History instead.
        $response->assertSee('History (1)');
        $response->assertSee('CCC Shift 1 - Mon, Wed, Fri', false);
        $response->assertSee('Jul 1, 2026 – Jul 10, 2026', false);
    }

    /**
     * A future-dated row fully swallowed by a later assign() call is never
     * deleted (see ShiftAssignmentTest::test_swallowing_a_future_scheduled_row_never_deletes_it),
     * it's truncated into a permanently unmatchable, inverted date range
     * instead. The History panel must not show that raw backwards range -
     * it reads as a data-entry bug ("from Jul 18 to Jul 12") rather than
     * what actually happened.
     */
    public function test_schedules_index_shows_a_swallowed_future_row_as_superseded_not_a_backwards_range(): void
    {
        $tk = $this->createTimeKeeper();
        $shiftB = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $shiftC = Shift::create([
            'name' => 'CCC Shift 2', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'Swallowed']);

        app(ShiftAssignmentService::class)->assign($emp, $shiftB->id, Carbon::parse('2026-07-18'), null, $tk->id, [6]);
        app(ShiftAssignmentService::class)->assign($emp, $shiftC->id, Carbon::parse('2026-07-13'), null, $tk->id);

        $swallowed = ShiftAssignment::where('user_id', $emp->id)->where('shift_id', $shiftB->id)->firstOrFail();
        $this->assertTrue($swallowed->isSuperseded());

        $this->travelTo(Carbon::parse('2026-07-13'));

        $response = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'Swallowed']));

        $response->assertSee('superseded before it took effect');
        $response->assertDontSee('Jul 18, 2026 – Jul 12, 2026', false);
    }

    /**
     * A ShiftAssignment row only reflects assignment history - a per-date
     * EmployeeShiftSchedule override (Rest Day, Field Work, forced Standard
     * Day, or a one-off shift) on the Shift Schedule week-grid silently wins
     * over it for that exact date. The Shift Assignment screen must warn
     * when a row's range contains one of these, instead of showing the row
     * as if it will simply apply.
     */
    public function test_schedules_index_flags_a_row_overridden_by_a_rest_day_on_shift_schedule(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'Overridden']);

        app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-07-18'), Carbon::parse('2026-07-18'), $tk->id, [6]
        );
        EmployeeShiftSchedule::create([
            'user_id' => $emp->id, 'date' => '2026-07-18', 'shift_id' => null, 'type' => 'rest', 'created_by' => $tk->id,
        ]);

        $this->travelTo(Carbon::parse('2026-07-13'));

        $response = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'Overridden']));

        $response->assertSee('CCC Shift 1 - Sat', false);
        $response->assertSee('overridden on Jul 18, 2026', false);
        // Blade's {{ }} HTML-escapes the "&" between query params, so check
        // for both params rather than the raw route() string verbatim.
        $response->assertSee('attendance/shift-schedule?employee_id='.$emp->id, false);
        $response->assertSee('week_start=2026-07-13', false);
    }

    /**
     * The same row with no conflicting override on file must not show the
     * warning at all - it's opt-in noise, not a permanent fixture.
     */
    public function test_schedules_index_does_not_flag_a_row_with_no_override(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'Clean']);

        app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-07-18'), Carbon::parse('2026-07-18'), $tk->id, [6]
        );

        $this->travelTo(Carbon::parse('2026-07-13'));

        $response = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'Clean']));

        $response->assertSee('CCC Shift 1 - Sat', false);
        $response->assertDontSee('overridden on', false);
    }

    /**
     * Consecutive overridden dates must collapse into one range ("Jul 13,
     * 2026 – Jul 17, 2026") instead of naming all 5 dates individually -
     * spelling each one out just repeats the row's own date-range label
     * with no added information.
     */
    public function test_schedules_index_collapses_consecutive_overridden_dates_into_a_range(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'Standard Day Shift', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'Ranged']);

        app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-07-13'), Carbon::parse('2026-07-17'), $tk->id
        );
        foreach (['2026-07-13', '2026-07-14', '2026-07-15', '2026-07-16'] as $date) {
            EmployeeShiftSchedule::create([
                'user_id' => $emp->id, 'date' => $date, 'shift_id' => null, 'type' => 'field_work', 'created_by' => $tk->id,
            ]);
        }
        // A non-consecutive extra override elsewhere in the row's range must
        // form its own separate range rather than merging into the first.
        EmployeeShiftSchedule::create([
            'user_id' => $emp->id, 'date' => '2026-07-17', 'shift_id' => null, 'type' => 'standard', 'created_by' => $tk->id,
        ]);

        $this->travelTo(Carbon::parse('2026-07-13'));

        $response = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'Ranged']));

        $response->assertSee('overridden on Jul 13, 2026 – Jul 17, 2026', false);
        $response->assertDontSee('Jul 13, 2026, Jul 14, 2026', false);
    }

    /**
     * An employee can accumulate many expired rows over a long tenure - the
     * inline History preview must stay capped (not render an unbounded list
     * on every page load) and link out to a dedicated, paginated page for
     * the rest.
     */
    public function test_schedules_index_history_is_capped_with_a_link_to_full_history(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'ManyHistory']);

        // 7 non-overlapping, fully-expired assignments (cap is 5).
        for ($i = 0; $i < 7; $i++) {
            $from = Carbon::parse('2026-01-01')->addWeeks($i * 2);
            $until = $from->copy()->addDays(6);
            app(ShiftAssignmentService::class)->assign($emp, $shift->id, $from, $until, $tk->id);
        }

        $this->travelTo(Carbon::parse('2026-07-12'));

        $response = $this->actingAs($tk)->get(route('attendance.schedules', ['search' => 'ManyHistory']));
        $response->assertSee('History (showing 5 of 7)');
        $response->assertSee(route('attendance.schedules.history', $emp), false);

        // The full history page shows all 7, paginated.
        $historyResponse = $this->actingAs($tk)->get(route('attendance.schedules.history', $emp));
        $historyResponse->assertOk();
        $this->assertCount(7, ShiftAssignment::forUser($emp->id)->get());
    }

    /**
     * Editing a row (form_type=edit, same effective_from, corrected fields)
     * must replace it outright via ShiftAssignmentService::assign()'s
     * existing same-start-date rule, not append a second overlapping row.
     */
    public function test_editing_an_expired_assignment_replaces_it_with_the_correction(): void
    {
        $tk = $this->createTimeKeeper();
        $wrongShift = Shift::create([
            'name' => 'Wrong Shift', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $correctShift = Shift::create([
            'name' => 'Correct Shift', 'time_in' => '08:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign(
            $emp, $wrongShift->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-10'), $tk->id, [1, 3, 5]
        );

        $this->travelTo(Carbon::parse('2026-07-12'));

        $this->actingAs($tk)->put(route('attendance.schedules.update', $emp), [
            'form_type' => 'edit',
            'shift_id' => $correctShift->id,
            'days_of_week' => [1, 3, 5],
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-07-10',
        ])->assertRedirect();

        $rows = ShiftAssignment::forUser($emp->id)->get();
        $this->assertCount(1, $rows, 'Same start date must replace the row outright, not leave a second one behind.');
        $this->assertSame($correctShift->id, $rows->first()->shift_id);
        $this->assertSame([1, 3, 5], $rows->first()->days_of_week);
    }

    /** Editing one row of a concurrent day-scoped pair must not disturb the other. */
    public function test_editing_one_row_of_a_concurrent_pair_leaves_the_other_untouched(): void
    {
        $tk = $this->createTimeKeeper();
        $mwfShift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $tthShift = Shift::create([
            'name' => 'TTH 8:30-5:30', 'time_in' => '08:30', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:30', 'is_active' => true,
        ]);
        $correctedMwfShift = Shift::create([
            'name' => 'Corrected MWF', 'time_in' => '06:00', 'break_out' => '11:00',
            'break_in' => '12:00', 'time_out' => '15:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign($emp, $mwfShift->id, Carbon::parse('2026-07-01'), null, $tk->id, [1, 3, 5]);
        app(ShiftAssignmentService::class)->assign($emp, $tthShift->id, Carbon::parse('2026-07-01'), null, $tk->id, [2, 4]);

        $this->actingAs($tk)->put(route('attendance.schedules.update', $emp), [
            'form_type' => 'edit',
            'shift_id' => $correctedMwfShift->id,
            'days_of_week' => [1, 3, 5],
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ])->assertRedirect();

        $rows = ShiftAssignment::forUser($emp->id)->effectiveOn(Carbon::today())->get();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains(fn ($r) => $r->shift_id === $correctedMwfShift->id && $r->days_of_week === [1, 3, 5]));
        $this->assertTrue($rows->contains(fn ($r) => $r->shift_id === $tthShift->id && $r->days_of_week === [2, 4]), 'The TTH row must be untouched by editing the MWF row.');
    }

    /** The audit trail (and Shift Change Log) must distinguish a correction from a fresh assignment. */
    public function test_editing_an_assignment_logs_a_distinct_corrected_action(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::parse('2026-07-01'), null, $tk->id);

        $response = $this->actingAs($tk)->put(route('attendance.schedules.update', $emp), [
            'form_type' => 'edit',
            'shift_id' => $shift->id,
            'effective_from' => '2026-07-01',
            'effective_until' => '2026-12-31',
        ]);

        $response->assertSessionHas('schedule_status', fn ($m) => str_contains($m, 'corrected'));

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_assignment_corrected',
            'target_type' => 'user',
            'target_id' => $emp->id,
        ]);

        $logResponse = $this->actingAs($tk)->get(route('attendance.shift-logs'));
        $logResponse->assertSee('Shift Corrected');
        $logResponse->assertSee('Corrected to CCC Shift 1', false);
    }

    /** Editing inherits the same department-scoped authorization as the rest of update(). */
    public function test_granted_department_head_cannot_edit_assignment_outside_own_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->nightShiftModel();
        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);

        $this->actingAs($dh)->put(route('attendance.schedules.update', $outsider), [
            'form_type' => 'edit',
            'shift_id' => $shift->id,
            'effective_from' => Carbon::today()->toDateString(),
            'effective_until' => '',
        ])->assertStatus(403);
    }

    /** The full-history page inherits the same department-scoped authorization as update(). */
    public function test_granted_department_head_cannot_view_history_outside_own_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);

        $this->actingAs($dh)->get(route('attendance.schedules.history', $outsider))
            ->assertStatus(403);
    }

    /**
     * The resolved-schedule calendar (ResolvedScheduleService) must show the
     * ShiftAssignment source and its hours on a plain day with no per-date
     * override on file - the common case.
     */
    public function test_resolved_schedule_shows_assignment_source_and_hours_on_a_plain_day(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'Resolved']);

        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::parse('2026-07-01'), null, $tk->id);

        $response = $this->actingAs($tk)->get(route('attendance.schedules.resolved', ['user' => $emp, 'month' => 7, 'year' => 2026]));

        $response->assertOk();
        $response->assertSee('CCC Shift 1');
        $response->assertSee('07:00-16:00', false);
        $response->assertSee('Assignment');
        $response->assertDontSee('Assignment says', false);
    }

    public function test_resolved_schedule_shows_no_break_tag_when_assignment_is_no_break(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'TMO Team 1 - 1', 'time_in' => '05:00', 'break_out' => '09:00',
            'break_in' => '10:00', 'time_out' => '14:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'NoBreak']);

        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::parse('2026-07-01'), null, $tk->id, null, null, true);

        $response = $this->actingAs($tk)->get(route('attendance.schedules.resolved', ['user' => $emp, 'month' => 7, 'year' => 2026]));

        $response->assertOk();
        $response->assertSee('TMO Team 1 - 1');
        $response->assertSee('05:00-14:00', false);
        $response->assertSee('No Break (2-punch)');
    }

    public function test_resolved_schedule_hides_no_break_tag_when_assignment_has_a_break(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'FullBreak']);

        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::parse('2026-07-01'), null, $tk->id, null, null, false);

        $response = $this->actingAs($tk)->get(route('attendance.schedules.resolved', ['user' => $emp, 'month' => 7, 'year' => 2026]));

        $response->assertOk();
        $response->assertDontSee('No Break (2-punch)');
    }

    /**
     * A day with an EmployeeShiftSchedule override AND a ShiftAssignment row
     * covering it must show the override's outcome as the label, tagged
     * "Override", plus a warning naming the shadowed assignment - the exact
     * scenario reported for employee "CCC Shift 1 - Sat" being silently
     * overridden by a Rest Day on the Shift Schedule page.
     */
    public function test_resolved_schedule_flags_a_shadowed_assignment(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee(['last_name' => 'Shadowed']);

        app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-07-18'), Carbon::parse('2026-07-18'), $tk->id, [6]
        );
        EmployeeShiftSchedule::create([
            'user_id' => $emp->id, 'date' => '2026-07-18', 'shift_id' => null, 'type' => 'rest', 'created_by' => $tk->id,
        ]);

        $response = $this->actingAs($tk)->get(route('attendance.schedules.resolved', ['user' => $emp, 'month' => 7, 'year' => 2026]));

        $response->assertOk();
        $response->assertSee('Rest Day');
        $response->assertSee('Override');
        $response->assertSee('Assignment says CCC Shift 1', false);
    }

    /**
     * A department head/administrative officer without shift management
     * access to the employee's department must not be able to view their
     * resolved schedule.
     */
    public function test_granted_department_head_cannot_view_resolved_schedule_outside_own_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);

        $this->actingAs($dh)->get(route('attendance.schedules.resolved', $outsider))
            ->assertStatus(403);
    }

    public function test_shift_schedule_page_shows_both_shifts_for_an_employee_with_a_concurrent_pair(): void
    {
        $tk = $this->createTimeKeeper();
        $mwfShift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $tthShift = Shift::create([
            'name' => 'TTH 8:30-5:30', 'time_in' => '08:30', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:30', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign($emp, $mwfShift->id, Carbon::today(), null, $tk->id, [1, 3, 5]);
        app(ShiftAssignmentService::class)->assign($emp, $tthShift->id, Carbon::today(), null, $tk->id, [2, 4]);

        $this->actingAs($tk)
            ->get(route('attendance.shift-schedule.index', ['employee_id' => $emp->id]))
            ->assertSee('MWF 7-4')
            ->assertSee('TTH 8:30-5:30')
            ->assertSee('Mon, Wed, Fri')
            ->assertSee('Tue, Thu');
    }

    public function test_shift_schedule_page_shows_a_future_dated_assignment_with_its_date_range(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'Future Shift', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign(
            $emp, $shift->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $tk->id
        );

        $this->actingAs($tk)
            ->get(route('attendance.shift-schedule.index', ['employee_id' => $emp->id]))
            ->assertSee('Future Shift')
            ->assertSee('Sep 1, 2026 – Sep 30, 2026');
    }

    /**
     * Regression: the week grid's per-date select only ever showed the
     * literal word "Default" when there was no EmployeeShiftSchedule
     * override for that date - even though the employee's Shift Assignment
     * (e.g. an MWF + TTH day-scoped combo) resolves to a different real
     * shift on different days. The select must actually pre-select the real
     * resolved shift's own option (not just describe it in the "Default"
     * option's label) so the assigned shift shows as the dropdown's value.
     */
    public function test_week_grid_default_reflects_the_actual_shift_assignment_per_day(): void
    {
        $tk = $this->createTimeKeeper();
        $mwfShift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $tthShift = Shift::create([
            'name' => 'TTH 8:30-5:30', 'time_in' => '08:30', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:30', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign($emp, $mwfShift->id, Carbon::parse('2026-08-01'), null, $tk->id, [1, 3, 5]);
        app(ShiftAssignmentService::class)->assign($emp, $tthShift->id, Carbon::parse('2026-08-01'), null, $tk->id, [2, 4]);

        // Week of Mon 2026-08-03 - Sun 2026-08-09. Mon/Wed/Fri -> MWF shift,
        // Tue/Thu -> TTH shift, Sat/Sun -> no covering day, so a rest day.
        $response = $this->actingAs($tk)->get(route('attendance.shift-schedule.index', [
            'employee_id' => $emp->id,
            'week_start' => '2026-08-03',
        ]));

        // The "Default" option's own label still describes what it resolves to...
        $response->assertSee('Default (MWF 7-4)', false);
        $response->assertSee('Default (TTH 8:30-5:30)', false);
        $response->assertSee('Default (Rest day)', false);

        // ...but the select itself pre-selects the real resolved shift as its value.
        $response->assertSee("<option value=\"{$mwfShift->id}\" data-no-break=\"0\" selected>MWF 7-4</option>", false);
        $response->assertSee("<option value=\"{$tthShift->id}\" data-no-break=\"0\" selected>TTH 8:30-5:30</option>", false);
        $response->assertSee('<option value="rest"        data-no-break="0" selected>Rest Day / Off</option>', false);
    }

    /**
     * Regression: the week grid's default-resolution used the
     * "not-yet-expired as of today" $activeAssignments collection (built
     * for the page's "current shifts" summary list) to look up what a given
     * day defaults to. That collection excludes any row whose
     * effective_until has already passed relative to today - so viewing a
     * PAST week that a now-expired assignment genuinely covered fell back to
     * "Standard Day" instead of the actual assigned shift.
     */
    public function test_week_grid_default_reflects_a_past_week_covered_by_a_since_expired_assignment(): void
    {
        $tk = $this->createTimeKeeper();
        $mwfShift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $tthShift = Shift::create([
            'name' => 'TTH 8:30-5:30', 'time_in' => '08:30', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:30', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign(
            $emp, $mwfShift->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-10'), $tk->id, [1, 3, 5]
        );
        app(ShiftAssignmentService::class)->assign(
            $emp, $tthShift->id, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-10'), $tk->id, [2, 4]
        );

        // "Today" is after both rows' effective_until, so they're excluded
        // from the page's "not-yet-expired" $activeAssignments - but the
        // week being viewed (Mon 7/6 - Fri 7/10) is fully inside the range
        // they actually covered.
        $this->travelTo(Carbon::parse('2026-07-12'));

        $response = $this->actingAs($tk)->get(route('attendance.shift-schedule.index', [
            'employee_id' => $emp->id,
            'week_start' => '2026-07-06',
        ]));

        $response->assertSee("<option value=\"{$mwfShift->id}\" data-no-break=\"0\" selected>MWF 7-4</option>", false);
        $response->assertSee("<option value=\"{$tthShift->id}\" data-no-break=\"0\" selected>TTH 8:30-5:30</option>", false);
        $response->assertDontSee('<option value="standard"    data-no-break="0" selected>Standard Day</option>', false);
    }

    public function test_week_grid_offers_a_standard_day_option_alongside_the_resolved_default(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::parse('2026-08-01'), null, $tk->id);

        $response = $this->actingAs($tk)->get(route('attendance.shift-schedule.index', [
            'employee_id' => $emp->id,
            'week_start' => '2026-08-03',
        ]));

        $response->assertSee('Default (CCC Shift 1)', false);
        $response->assertSee('<option value="standard"', false);
        $response->assertSee('>Standard Day<', false);
        // The assigned shift itself is what's actually pre-selected in the dropdown.
        $response->assertSee("<option value=\"{$shift->id}\" data-no-break=\"0\" selected>CCC Shift 1</option>", false);
    }

    public function test_standard_day_override_forces_global_schedule_and_counts_as_a_workday(): void
    {
        $tk = $this->createTimeKeeper();
        $shift = Shift::create([
            'name' => 'CCC Shift 1', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        app(ShiftAssignmentService::class)->assign($emp, $shift->id, Carbon::parse('2026-08-01'), null, $tk->id);

        $this->actingAs($tk)->post(route('attendance.shift-schedule.store'), [
            'user_id' => $emp->id,
            'week_start' => '2026-08-03',
            'assignments' => ['2026-08-03' => 'standard'],
        ])->assertRedirect();

        $this->assertDatabaseHas('employee_shift_schedules', [
            'user_id' => $emp->id,
            'date' => '2026-08-03',
            'shift_id' => null,
            'type' => 'standard',
        ]);

        $emp->refresh();
        $this->assertTrue(WorkSchedule::isWorkday($emp, Carbon::parse('2026-08-03')));
        $this->assertFalse(WorkSchedule::isRestDay($emp, Carbon::parse('2026-08-03')));

        $resolved = WorkSchedule::forUserOnDate($emp, Carbon::parse('2026-08-03'));
        $global = WorkSchedule::global();
        $this->assertEquals($global->workStart, $resolved->workStart);
        $this->assertEquals($global->workEnd, $resolved->workEnd);

        // The week grid reflects the forced override in the dropdown's selected value.
        $response = $this->actingAs($tk)->get(route('attendance.shift-schedule.index', [
            'employee_id' => $emp->id,
            'week_start' => '2026-08-03',
        ]));
        $response->assertSee('<option value="standard"    data-no-break="0" selected>Standard Day</option>', false);
    }

    // ── Bulk week schedule save (checkbox-selected employees) ───────────────

    public function test_time_keeper_bulk_saves_week_schedule_for_checked_employees_only(): void
    {
        $tk = $this->createTimeKeeper();
        $checked1 = $this->createEmployee();
        $checked2 = $this->createEmployee();
        $unchecked = $this->createEmployee();

        // Punches on the target date so we can prove recompute actually ran
        // per checked employee (no Dtr row exists until recomputeDtr writes one).
        foreach ([$checked1, $checked2] as $emp) {
            foreach (['08:00:00', '17:00:00'] as $time) {
                AttendanceLog::create([
                    'user_id' => $emp->id, 'emp_no' => $emp->EmpNo,
                    'logdate' => '2026-08-03', 'logtime' => $time, 'in_out' => 'IN',
                ]);
            }
        }

        $this->assertDatabaseMissing('dtrs', ['employee_id' => $checked1->id, 'date' => '2026-08-03']);

        $this->actingAs($tk)->post(route('attendance.shift-schedule.store-bulk'), [
            'user_ids' => [$checked1->id, $checked2->id],
            'week_start' => '2026-08-03',
            'assignments' => ['2026-08-03' => 'standard'],
        ])->assertRedirect();

        foreach ([$checked1, $checked2] as $emp) {
            $this->assertDatabaseHas('employee_shift_schedules', [
                'user_id' => $emp->id, 'date' => '2026-08-03', 'shift_id' => null, 'type' => 'standard',
            ]);
            $this->assertDatabaseHas('dtrs', ['employee_id' => $emp->id, 'date' => '2026-08-03']);
            $this->assertDatabaseHas('hr_audit_trails', [
                'module' => 'shift_management',
                'action' => 'shift_schedule_updated',
                'target_id' => $emp->id,
            ]);
        }

        $this->assertDatabaseMissing('employee_shift_schedules', ['user_id' => $unchecked->id]);
        $this->assertDatabaseMissing('dtrs', ['employee_id' => $unchecked->id]);
    }

    public function test_granted_department_head_cannot_bulk_save_week_schedule_outside_own_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $inDept = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);

        // Mixing in an out-of-scope employee must reject the whole request - no partial writes.
        $this->actingAs($dh)->post(route('attendance.shift-schedule.store-bulk'), [
            'user_ids' => [$inDept->id, $outsider->id],
            'week_start' => '2026-08-03',
            'assignments' => ['2026-08-03' => 'rest'],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('employee_shift_schedules', ['user_id' => $inDept->id]);
        $this->assertDatabaseMissing('employee_shift_schedules', ['user_id' => $outsider->id]);
    }

    public function test_bulk_save_week_schedule_rejects_shift_out_of_scope_for_any_selected_employee_before_writing(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $tk = $this->createTimeKeeper();
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $tk->id]);
        ShiftManagementGrant::create(['dept_id' => $deptB->Dept_id, 'granted_by' => $tk->id]);

        OicAssignment::create([
            'user_id' => $dh->id,
            'dept_id' => $deptB->Dept_id,
            'role' => 'department head',
            'appointed_by' => $this->createHRManager()->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $outOfScopeShift = $this->scopedShiftModel('Dept B Only', [$deptB->Dept_id]);
        $inScope = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);
        $outOfScope = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($dh)->post(route('attendance.shift-schedule.store-bulk'), [
            'user_ids' => [$inScope->id, $outOfScope->id],
            'week_start' => '2026-08-03',
            'assignments' => ['2026-08-03' => (string) $outOfScopeShift->id],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('employee_shift_schedules', ['user_id' => $inScope->id]);
        $this->assertDatabaseMissing('employee_shift_schedules', ['user_id' => $outOfScope->id]);
    }

    public function test_bulk_assign_with_no_shift_selected_reverts_checked_employees_to_standard_day(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $shift = $this->nightShiftModel();
        $emp = $this->createEmployee(['shift_id' => $shift->id]);

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => '',
            'user_ids' => [$emp->id],
            'effective_from' => Carbon::today()->toDateString(),
            'effective_until' => Carbon::today()->addYear()->toDateString(),
        ])->assertRedirect();

        $this->assertNull($emp->refresh()->shift_id);
        $this->assertDatabaseHas('shift_assignments', ['user_id' => $emp->id, 'shift_id' => null, 'effective_until' => Carbon::today()->addYear()->toDateString()]);
    }

    public function test_bulk_assign_with_effective_dates_creates_dated_assignment_and_does_not_touch_cache_early(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $shift = $this->nightShiftModel();
        $emp = $this->createEmployee();

        $this->travelTo(Carbon::parse('2026-07-10'));

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $shift->id,
            'user_ids' => [$emp->id],
            'effective_from' => '2026-08-01',
            'effective_until' => '2026-08-31',
        ])->assertRedirect();

        $this->assertNull($emp->refresh()->shift_id, 'A future-dated bulk assignment must not affect today\'s cache.');
        $this->assertDatabaseHas('shift_assignments', [
            'user_id' => $emp->id,
            'shift_id' => $shift->id,
            'effective_from' => '2026-08-01',
            'effective_until' => '2026-08-31',
        ]);
    }

    public function test_bulk_assign_with_effective_until_falls_back_to_standard_day_after_expiry_once_synced(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $standing = $this->nightShiftModel();
        $temporary = Shift::create([
            'name' => 'Work From Home', 'time_in' => '09:00', 'time_out' => '18:00', 'is_active' => true,
        ]);
        $emp = $this->createEmployee();

        // Give the employee a real, open-ended standing assignment beforehand,
        // to prove the temporary window doesn't resume it afterward.
        app(ShiftAssignmentService::class)->assign($emp, $standing->id, Carbon::parse('2000-01-01'), null, null);

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $temporary->id,
            'user_ids' => [$emp->id],
            'effective_from' => '2026-08-01',
            'effective_until' => '2026-08-31',
        ])->assertRedirect();

        $this->travelTo(Carbon::parse('2026-09-01'));
        $this->artisan(SyncShiftAssignmentCache::class)->assertExitCode(0);

        $this->assertNull($emp->refresh()->shift_id, 'After the window closes the employee falls back to Standard Day, not their prior standing shift.');
        $this->assertNull(
            ShiftAssignment::forUser($emp->id)->effectiveOn(Carbon::parse('2026-09-01'))->first()
        );
    }

    public function test_bulk_assign_with_no_employees_checked_fails_validation(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();
        $shift = $this->nightShiftModel();

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $shift->id,
            'user_ids' => [],
        ])->assertSessionHasErrors('user_ids');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('hr_audit_trails', ['action' => 'shift_assigned']);
    }

    public function test_granted_department_head_bulk_assign_is_scoped_to_own_department(): void
    {
        Queue::fake();

        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->nightShiftModel();
        $inDept = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);

        $this->actingAs($dh)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $shift->id,
            'user_ids' => [$inDept->id],
            'effective_from' => Carbon::today()->toDateString(),
            'effective_until' => Carbon::today()->addYear()->toDateString(),
        ])->assertRedirect();

        $this->assertSame($shift->id, $inDept->refresh()->shift_id);

        // Attempting to check an employee outside the granted department is rejected outright.
        $this->actingAs($dh)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $shift->id,
            'user_ids' => [$outsider->id],
            'effective_from' => Carbon::today()->toDateString(),
            'effective_until' => Carbon::today()->addYear()->toDateString(),
        ])->assertStatus(403);

        $this->assertNull($outsider->refresh()->shift_id);
    }

    public function test_granted_department_head_cannot_bulk_assign_shift_scoped_to_other_department(): void
    {
        Queue::fake();

        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $outOfScopeShift = $this->scopedShiftModel('Dept B Only', [$deptB->Dept_id]);
        $emp = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($dh)->put(route('attendance.schedules.bulk-assign'), [
            'assign_shift_id' => $outOfScopeShift->id,
            'user_ids' => [$emp->id],
            'effective_from' => Carbon::today()->toDateString(),
            'effective_until' => Carbon::today()->addYear()->toDateString(),
        ])->assertStatus(403);

        $this->assertNull($emp->refresh()->shift_id);
        Queue::assertNothingPushed();
    }

    public function test_time_keeper_bulk_removes_shift_from_checked_employees_only(): void
    {
        Queue::fake();

        $deptA = $this->makeDepartment('Dept A');
        $tk = $this->createTimeKeeper();
        $shift = $this->nightShiftModel();

        $checked1 = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'shift_id' => $shift->id]);
        $checked2 = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'shift_id' => $shift->id]);
        $unchecked = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'shift_id' => $shift->id]);

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-remove'), [
            'user_ids' => [$checked1->id, $checked2->id],
        ])->assertRedirect();

        $this->assertNull($checked1->refresh()->shift_id);
        $this->assertNull($checked2->refresh()->shift_id);
        $this->assertSame($shift->id, $unchecked->refresh()->shift_id, 'An unchecked employee must be untouched.');

        $this->assertDatabaseHas('shift_assignments', ['user_id' => $checked1->id, 'shift_id' => null, 'effective_from' => Carbon::today()->toDateString(), 'effective_until' => null]);
        $this->assertDatabaseHas('shift_assignments', ['user_id' => $checked2->id, 'shift_id' => null, 'effective_from' => Carbon::today()->toDateString(), 'effective_until' => null]);

        Queue::assertPushed(BulkShiftRecomputeJob::class, function ($job) use ($checked1, $checked2, $unchecked) {
            return in_array($checked1->id, $job->userIds, true)
                && in_array($checked2->id, $job->userIds, true)
                && ! in_array($unchecked->id, $job->userIds, true);
        });

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_removed',
            'target_id' => $checked1->id,
        ]);
        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_removed',
            'target_id' => $checked2->id,
        ]);
    }

    public function test_bulk_remove_with_no_employees_checked_fails_validation(): void
    {
        Queue::fake();

        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->put(route('attendance.schedules.bulk-remove'), [
            'user_ids' => [],
        ])->assertSessionHasErrors('user_ids');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('hr_audit_trails', ['action' => 'shift_removed']);
    }

    public function test_granted_department_head_bulk_remove_is_scoped_to_own_department(): void
    {
        Queue::fake();

        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $dh = $this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]);
        ShiftManagementGrant::create(['dept_id' => $deptA->Dept_id, 'granted_by' => $this->createTimeKeeper()->id]);

        $shift = $this->nightShiftModel();
        $inDept = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'shift_id' => $shift->id]);
        $outsider = $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'shift_id' => $shift->id]);

        $this->actingAs($dh)->put(route('attendance.schedules.bulk-remove'), [
            'user_ids' => [$inDept->id],
        ])->assertRedirect();

        $this->assertNull($inDept->refresh()->shift_id);

        // Attempting to check an employee outside the granted department is rejected outright.
        $this->actingAs($dh)->put(route('attendance.schedules.bulk-remove'), [
            'user_ids' => [$outsider->id],
        ])->assertStatus(403);

        $this->assertSame($shift->id, $outsider->refresh()->shift_id);
    }
}
