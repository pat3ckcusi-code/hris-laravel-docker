<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\EmployeeShiftSchedule;
use App\Models\Shift;
use App\Services\Attendance\AttendanceStatusResolver;
use App\Services\Attendance\MatchResult;
use App\Services\DtrPunchResolver;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftAssignmentService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * The computation layer on top of AttendanceMatcher: late only from IN
 * events, undertime only from OUT events, hours worked only from complete
 * in/out pairs, and the status resolver's full presence-combination table -
 * plus end-to-end persistence of the new fields through
 * PersonnelLogImportService.
 */
class AttendanceComputationTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private const DATE = '2026-06-11';

    private function specDay(bool $noBreak = false, bool $isStandardDay = true): WorkSchedule
    {
        // workStart 08:00, lunchReturn 13:00, workEnd 17:00, morningEnd 12:00
        return new WorkSchedule('08:00', '13:00', '17:00', '12:00', '14:00', false, $noBreak, $isStandardDay);
    }

    /** @param  list<string>  $times  H:i on the shift date */
    private function resolve(array $times, ?WorkSchedule $schedule = null): array
    {
        $punches = array_map(fn (string $t) => self::DATE." $t:00", $times);

        return (new DtrPunchResolver)->resolve($punches, self::DATE, $schedule ?? $this->specDay());
    }

    /** @param  array<string, string|null>  $slots  slot key => H:i or null */
    private function matchResult(array $slots): MatchResult
    {
        $matched = [];
        foreach (['am_in', 'am_out', 'pm_in', 'pm_out'] as $slot) {
            $time = $slots[$slot] ?? null;
            $matched[$slot] = $time === null ? null : Carbon::parse(self::DATE." $time:00");
        }

        return new MatchResult($matched, []);
    }

    // ── Late (IN events only) ─────────────────────────────────────────────────

    public function test_late_is_charged_from_am_in(): void
    {
        $r = $this->resolve(['08:30', '12:00', '13:00', '17:00']);

        $this->assertSame(30, $r['late_minutes']);
        $this->assertSame('late', $r['status']);
    }

    public function test_late_is_charged_from_pm_in(): void
    {
        $r = $this->resolve(['08:00', '12:00', '13:10', '17:00']);

        $this->assertSame(10, $r['late_minutes']);
    }

    public function test_late_sums_both_in_events_and_outranks_undertime_in_status(): void
    {
        $r = $this->resolve(['08:10', '12:00', '13:05', '16:50']);

        $this->assertSame(15, $r['late_minutes']);
        $this->assertSame(10, $r['undertime_minutes']);
        $this->assertSame('late', $r['status']);
    }

    public function test_missing_in_logs_do_not_become_late(): void
    {
        // am_in missing: no arrival lateness is invented for it.
        $r = $this->resolve(['12:02', '13:01', '17:00']);

        $this->assertNull($r['am_in']);
        $this->assertSame(1, $r['late_minutes'], 'Only the 13:01 return is a minute late.');
    }

    // ── Undertime (OUT events only) ───────────────────────────────────────────

    public function test_undertime_is_charged_from_an_early_am_out(): void
    {
        $r = $this->resolve(['08:00', '11:45', '13:00', '17:00']);

        $this->assertSame(15, $r['undertime_minutes']);
        $this->assertSame('undertime', $r['status']);
    }

    public function test_undertime_is_charged_from_an_early_pm_out(): void
    {
        $r = $this->resolve(['08:00', '12:00', '13:00', '16:30']);

        $this->assertSame(30, $r['undertime_minutes']);
    }

    public function test_undertime_sums_both_out_events(): void
    {
        $r = $this->resolve(['08:00', '11:50', '13:00', '16:40']);

        $this->assertSame(30, $r['undertime_minutes']);
        $this->assertSame(0, $r['late_minutes']);
    }

    public function test_missing_out_logs_do_not_become_undertime(): void
    {
        $r = $this->resolve(['08:00', '12:00', '13:00']);

        $this->assertNull($r['pm_out']);
        $this->assertSame(0, $r['undertime_minutes']);
        $this->assertSame('missing_out', $r['status']);
    }

    // ── Overtime (a dedicated OT In / OT Out punch pair, Standard Day only) ───

    public function test_overtime_is_charged_between_ot_in_and_ot_out_punches(): void
    {
        $r = $this->resolve(['08:00', '12:00', '13:00', '17:00', '18:00', '22:00']);

        $this->assertSame('17:00:00', $r['pm_out']);
        $this->assertSame('18:00:00', $r['time_in_ot']);
        $this->assertSame('22:00:00', $r['time_out_ot']);
        $this->assertSame(240, $r['overtime_minutes']);
    }

    public function test_a_lone_late_punch_with_no_prior_pm_out_is_still_pm_out_not_overtime(): void
    {
        // Only 4 punches total - the last one simply late. It must resolve as
        // PM Out, not as an orphaned OT In.
        $r = $this->resolve(['08:00', '12:00', '13:00', '20:00']);

        $this->assertSame('20:00:00', $r['pm_out']);
        $this->assertNull($r['time_in_ot']);
        $this->assertNull($r['time_out_ot']);
        $this->assertSame(0, $r['overtime_minutes']);
    }

    public function test_a_lone_extra_punch_after_a_genuine_pm_out_is_an_open_ot_in(): void
    {
        $r = $this->resolve(['08:00', '12:00', '13:00', '17:00', '18:00']);

        $this->assertSame('17:00:00', $r['pm_out']);
        $this->assertSame('18:00:00', $r['time_in_ot']);
        $this->assertNull($r['time_out_ot']);
        $this->assertSame(0, $r['overtime_minutes'], 'An incomplete OT pair is never counted.');
    }

    public function test_overtime_never_applies_to_a_non_standard_shift(): void
    {
        $r = $this->resolve(
            ['08:00', '12:00', '13:00', '17:00', '18:00', '22:00'],
            $this->specDay(isStandardDay: false)
        );

        $this->assertSame('17:00:00', $r['pm_out']);
        $this->assertNull($r['time_in_ot']);
        $this->assertNull($r['time_out_ot']);
        $this->assertSame(0, $r['overtime_minutes']);
    }

    public function test_no_break_shift_overtime_pair_still_matches(): void
    {
        $r = $this->resolve(['08:00', '17:00', '18:00', '22:00'], $this->specDay(noBreak: true));

        $this->assertSame('17:00:00', $r['pm_out']);
        $this->assertSame('18:00:00', $r['time_in_ot']);
        $this->assertSame('22:00:00', $r['time_out_ot']);
        $this->assertSame(240, $r['overtime_minutes']);
    }

    // ── Hours worked (complete in/out pairs only) ─────────────────────────────

    public function test_hours_worked_sums_both_half_day_spans(): void
    {
        $r = $this->resolve(['07:55', '12:03', '12:57', '17:02']);

        $this->assertSame(493, $r['worked_minutes']); // 4h08 + 4h05
        $this->assertSame(8.22, $r['hours_worked']);
    }

    public function test_half_day_counts_only_its_complete_pair(): void
    {
        $r = $this->resolve(['07:55', '12:03']);

        $this->assertSame(248, $r['worked_minutes']);
        $this->assertSame(4.13, $r['hours_worked']);
        $this->assertSame('half_day_am', $r['status']);
    }

    public function test_a_lone_punch_earns_no_hours(): void
    {
        $r = $this->resolve(['08:00', '12:00', '13:00']);

        $this->assertSame(240, $r['worked_minutes'], 'Only the complete AM pair counts - PM has no out.');
    }

    public function test_no_break_shift_hours_span_the_single_pair(): void
    {
        $r = $this->resolve(['08:00', '17:00'], $this->specDay(noBreak: true));

        $this->assertSame(540, $r['worked_minutes']);
        $this->assertSame(9.0, $r['hours_worked']);
        $this->assertSame('present', $r['status']);
    }

    public function test_24_hour_shift_hours_span_the_full_day(): void
    {
        $schedule = new WorkSchedule('08:00', '08:00', '08:00', '08:00', '08:00', true, true);
        $punches = [self::DATE.' 08:00:00', '2026-06-12 08:00:00'];

        $r = (new DtrPunchResolver)->resolve($punches, self::DATE, $schedule);

        $this->assertSame(1440, $r['worked_minutes']);
        $this->assertSame(24.0, $r['hours_worked']);
    }

    // ── Status resolution: every presence combination ─────────────────────────

    public function test_status_for_every_full_break_presence_combination(): void
    {
        $resolver = new AttendanceStatusResolver;
        $times = ['am_in' => '08:00', 'am_out' => '12:00', 'pm_in' => '13:00', 'pm_out' => '17:00'];

        $expected = [
            //  a  b  c  d  (am_in, am_out, pm_in, pm_out present?)
            '1111' => AttendanceStatus::Present,
            '1100' => AttendanceStatus::HalfDayAm,
            '0011' => AttendanceStatus::HalfDayPm,
            '0111' => AttendanceStatus::MissingIn,
            '1101' => AttendanceStatus::MissingIn,
            '0100' => AttendanceStatus::MissingIn,
            '0001' => AttendanceStatus::MissingIn,
            '0101' => AttendanceStatus::MissingIn,
            '1011' => AttendanceStatus::MissingOut,
            '1110' => AttendanceStatus::MissingOut,
            '1000' => AttendanceStatus::MissingOut,
            '0010' => AttendanceStatus::MissingOut,
            '1010' => AttendanceStatus::MissingOut,
            '1001' => AttendanceStatus::Incomplete,
            '0110' => AttendanceStatus::Incomplete,
            '0000' => AttendanceStatus::Incomplete,
        ];

        foreach ($expected as $combo => $status) {
            $combo = (string) $combo; // PHP coerces keys like '1111' to int
            $slots = [];
            foreach (array_keys($times) as $i => $slot) {
                $slots[$slot] = $combo[$i] === '1' ? $times[$slot] : null;
            }

            $this->assertSame(
                $status,
                $resolver->resolve($this->matchResult($slots), 0, 0, noBreak: false),
                "presence combination $combo"
            );
        }
    }

    public function test_status_for_no_break_combinations(): void
    {
        $resolver = new AttendanceStatusResolver;

        $cases = [
            [['am_in' => '08:00', 'pm_out' => '17:00'], AttendanceStatus::Present],
            [['am_in' => '08:00'], AttendanceStatus::MissingOut],
            [['pm_out' => '17:00'], AttendanceStatus::MissingIn],
            [[], AttendanceStatus::Incomplete],
        ];

        foreach ($cases as [$slots, $status]) {
            $this->assertSame($status, $resolver->resolve($this->matchResult($slots), 0, 0, noBreak: true));
        }
    }

    public function test_complete_day_status_reflects_the_penalty_totals(): void
    {
        $resolver = new AttendanceStatusResolver;
        $complete = $this->matchResult(['am_in' => '08:00', 'am_out' => '12:00', 'pm_in' => '13:00', 'pm_out' => '17:00']);

        $this->assertSame(AttendanceStatus::Late, $resolver->resolve($complete, 5, 0, false));
        $this->assertSame(AttendanceStatus::Late, $resolver->resolve($complete, 5, 10, false), 'Late outranks undertime.');
        $this->assertSame(AttendanceStatus::Undertime, $resolver->resolve($complete, 0, 10, false));
    }

    // ── Contract & persistence ────────────────────────────────────────────────

    public function test_resolve_keeps_the_original_keys_and_adds_the_new_ones(): void
    {
        $r = $this->resolve(['08:00', '12:00', '13:00', '17:00']);

        $this->assertSame(
            ['am_in', 'am_out', 'pm_in', 'pm_out', 'time_in_ot', 'time_out_ot',
                'late_minutes', 'undertime_minutes', 'overtime_minutes',
                'worked_minutes', 'hours_worked', 'status', 'unmatched'],
            array_keys($r)
        );
        $this->assertSame([], $r['unmatched']);
    }

    public function test_import_persists_hours_status_and_unmatched_logs(): void
    {
        $user = $this->createEmployee(); // standard day: 08:00 / 11:00 / 13:00 / 17:00

        // 09:30 is a stray re-scan no event claims; pm_out never registered.
        foreach (['08:30:00', '09:30:00', '12:01:00', '13:03:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $user->id,
                'emp_no' => $user->EmpNo,
                'logdate' => self::DATE,
                'logtime' => $time,
            ]);
        }

        app(PersonnelLogImportService::class)->recomputeDtr($user, self::DATE, self::DATE);

        $dtr = Dtr::where('employee_id', $user->id)->whereDate('date', self::DATE)->firstOrFail();

        $this->assertSame('08:30:00', $dtr->time_in_am);
        $this->assertSame('12:01:00', $dtr->time_out_am);
        $this->assertSame('13:03:00', $dtr->time_in_pm);
        $this->assertNull($dtr->time_out_pm);
        $this->assertSame(33, $dtr->late_minutes);       // 30 arrival + 3 lunch return
        $this->assertSame(0, $dtr->undertime_minutes);
        $this->assertSame(3.52, $dtr->hours_worked);     // the 08:30-12:01 AM pair only
        $this->assertSame('missing_out', $dtr->status);
        $this->assertSame(['09:30:00'], $dtr->unmatched_logs);
        $this->assertFalse($dtr->is_absent, 'Automatic imports never write is_absent=true.');
        $this->assertSame('biometric', $dtr->source);
    }

    public function test_import_persists_overtime_minutes(): void
    {
        $user = $this->createEmployee(); // standard day: 08:00 / 11:00 / 13:00 / 17:00

        // A regular on-time PM Out plus a dedicated OT In / OT Out pair.
        foreach (['08:00:00', '11:00:00', '13:00:00', '17:00:00', '18:00:00', '22:00:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $user->id,
                'emp_no' => $user->EmpNo,
                'logdate' => self::DATE,
                'logtime' => $time,
            ]);
        }

        app(PersonnelLogImportService::class)->recomputeDtr($user, self::DATE, self::DATE);

        $dtr = Dtr::where('employee_id', $user->id)->whereDate('date', self::DATE)->firstOrFail();

        $this->assertSame('17:00:00', $dtr->time_out_pm);
        $this->assertSame('18:00:00', $dtr->time_in_ot);
        $this->assertSame('22:00:00', $dtr->time_out_ot);
        $this->assertSame(240, $dtr->overtime_minutes);
    }

    public function test_import_never_persists_a_read_time_only_status(): void
    {
        $user = $this->createEmployee();

        AttendanceLog::create([
            'user_id' => $user->id,
            'emp_no' => $user->EmpNo,
            'logdate' => self::DATE,
            'logtime' => '08:00:00',
        ]);

        app(PersonnelLogImportService::class)->recomputeDtr($user, self::DATE, self::DATE);

        $dtr = Dtr::where('employee_id', $user->id)->whereDate('date', self::DATE)->firstOrFail();

        $this->assertNotSame('absent', $dtr->status);
        $this->assertSame('missing_out', $dtr->status);
    }

    public function test_recompute_deletes_a_biometric_dtr_row_orphaned_by_a_shift_change(): void
    {
        $user = $this->createEmployee();
        $shift = Shift::create([
            'name' => 'Night Shift',
            'time_in' => '22:00',
            'break_out' => '02:00',
            'break_in' => '02:30',
            'time_out' => '06:00',
            'crosses_midnight' => true,
            'is_active' => true,
        ]);
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse('2026-06-10'), null, null, null, [0, 1, 2, 3, 4, 5, 6], false
        );

        // A post-midnight departure punch, well inside the night shift's real
        // off-period boundary, so it folds back onto the PREVIOUS day's shift-date.
        AttendanceLog::create([
            'user_id' => $user->id,
            'emp_no' => $user->EmpNo,
            'logdate' => '2026-06-12',
            'logtime' => '05:55:00',
        ]);

        app(PersonnelLogImportService::class)->recomputeDtr($user, '2026-06-11', '2026-06-12');

        $this->assertDatabaseHas('dtrs', [
            'employee_id' => $user->id, 'date' => '2026-06-11', 'time_out_pm' => '05:55:00',
        ]);
        $this->assertDatabaseMissing('dtrs', ['employee_id' => $user->id, 'date' => '2026-06-12']);

        // The shift template changes to a plain non-crossing day shift - the
        // same punch now belongs to its own logdate directly.
        $shift->update([
            'time_in' => '08:00', 'break_out' => '12:00', 'break_in' => '13:00',
            'time_out' => '17:00', 'crosses_midnight' => false,
        ]);

        app(PersonnelLogImportService::class)->recomputeDtr($user, '2026-06-11', '2026-06-12');

        $this->assertDatabaseHas('dtrs', [
            'employee_id' => $user->id, 'date' => '2026-06-12', 'time_in_am' => '05:55:00',
        ]);
        $this->assertDatabaseMissing(
            'dtrs',
            ['employee_id' => $user->id, 'date' => '2026-06-11']
        );
    }

    /**
     * End-to-end regression for a real reported case: a 24-on/24-off rotation
     * where the closing punch lands after two consecutive configured rest
     * days. ShiftPunchGrouper already buckets both punches together (see
     * ShiftScheduleTest's grouper-level coverage), but AttendanceMatcher's own
     * pm_out window/scoring must also accept a punch this many days later, or
     * the correctly-grouped pair still resolves as two incomplete halves
     * instead of one combined present day.
     */
    public function test_import_combines_a_24_hour_shift_spanning_two_rest_days_into_one_row(): void
    {
        $shift = Shift::create([
            'name' => '24-Hour Duty', 'time_in' => '08:00', 'time_out' => '08:00',
            'break_out' => null, 'break_in' => null, 'crosses_midnight' => true, 'is_active' => true,
        ]);
        $user = $this->createEmployee();
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse('2026-07-16'), null, null, null, [0, 1, 2, 3, 4, 5, 6], true
        );
        foreach (['2026-07-17', '2026-07-18'] as $date) {
            EmployeeShiftSchedule::create([
                'user_id' => $user->id, 'date' => $date, 'shift_id' => null, 'type' => 'rest', 'created_by' => $user->id,
            ]);
        }

        foreach (['2026-07-16 08:00:00', '2026-07-18 07:56:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => $d, 'logtime' => $t,
            ]);
        }

        app(PersonnelLogImportService::class)->recomputeDtr($user, '2026-07-16', '2026-07-18');

        $dtr = Dtr::where('employee_id', $user->id)->whereDate('date', '2026-07-16')->firstOrFail();

        $this->assertSame('08:00:00', $dtr->time_in_am);
        $this->assertSame('07:56:00', $dtr->time_out_pm);
        $this->assertSame(47.93, $dtr->hours_worked);
        $this->assertSame('present', $dtr->status);
        $this->assertDatabaseMissing('dtrs', ['employee_id' => $user->id, 'date' => '2026-07-17']);
        $this->assertDatabaseMissing('dtrs', ['employee_id' => $user->id, 'date' => '2026-07-18']);
    }
}
