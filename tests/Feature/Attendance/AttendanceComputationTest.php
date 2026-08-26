<?php

namespace Tests\Feature\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\EmployeeShiftSchedule;
use App\Models\Shift;
use App\Models\User;
use App\Services\Attendance\AttendanceStatusResolver;
use App\Services\Attendance\MatchResult;
use App\Services\DtrPunchResolver;
use App\Services\Form48ExportService;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftAssignmentService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
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

    /**
     * Assigns a real 08:00/12:00/13:00/17:00 Shift (matching specDay() and the
     * real reported case's actual shift config) so WorkSchedule::forUserOnDate()
     * - the schedule source Form48ExportService actually resolves against -
     * agrees with it, rather than relying on createEmployee()'s own default
     * (unassigned) schedule.
     */
    private function assignEightToTwelveThirteenToFiveShift(User $user): void
    {
        $shift = Shift::create([
            'name' => 'Eight To Five',
            'time_in' => '08:00', 'break_out' => '12:00', 'break_in' => '13:00', 'time_out' => '17:00',
            'crosses_midnight' => false, 'is_active' => true,
        ]);
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse(self::DATE)->subDay(), null, null, null, [0, 1, 2, 3, 4, 5, 6], false
        );
    }

    /** No-break counterpart of assignEightToTwelveThirteenToFiveShift(): a real 08:00/17:00, 2-punch Shift. */
    private function assignEightToFiveNoBreakShift(User $user): void
    {
        $shift = Shift::create([
            'name' => 'Eight To Five No Break',
            'time_in' => '08:00', 'break_out' => null, 'break_in' => null, 'time_out' => '17:00',
            'crosses_midnight' => false, 'is_active' => true,
        ]);
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse(self::DATE)->subDay(), null, null, null, [0, 1, 2, 3, 4, 5, 6], true
        );
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

    public function test_status_for_in_only_punch_requirement(): void
    {
        $resolver = new AttendanceStatusResolver;

        $this->assertSame(
            AttendanceStatus::Present,
            $resolver->resolve($this->matchResult(['am_in' => '08:00']), 0, 0, noBreak: false, punchRequirement: 'in_only')
        );
        $this->assertSame(
            AttendanceStatus::Late,
            $resolver->resolve($this->matchResult(['am_in' => '08:30']), 30, 0, noBreak: false, punchRequirement: 'in_only')
        );
        // No punch at all is defensive-only (a punchless day never reaches
        // the resolver in practice - no dtrs row gets written for it).
        $this->assertSame(
            AttendanceStatus::Incomplete,
            $resolver->resolve($this->matchResult([]), 0, 0, noBreak: false, punchRequirement: 'in_only')
        );
        // A stray pm_out (never expected under in_only) doesn't change the outcome.
        $this->assertSame(
            AttendanceStatus::Present,
            $resolver->resolve($this->matchResult(['am_in' => '08:00', 'pm_out' => '17:00']), 0, 0, noBreak: false, punchRequirement: 'in_only')
        );
    }

    public function test_status_for_out_only_punch_requirement(): void
    {
        $resolver = new AttendanceStatusResolver;

        $this->assertSame(
            AttendanceStatus::Present,
            $resolver->resolve($this->matchResult(['pm_out' => '17:00']), 0, 0, noBreak: false, punchRequirement: 'out_only')
        );
        $this->assertSame(
            AttendanceStatus::Undertime,
            $resolver->resolve($this->matchResult(['pm_out' => '16:30']), 0, 30, noBreak: false, punchRequirement: 'out_only')
        );
        $this->assertSame(
            AttendanceStatus::Incomplete,
            $resolver->resolve($this->matchResult([]), 0, 0, noBreak: false, punchRequirement: 'out_only')
        );
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

    /**
     * End-to-end regression for a real reported bug: a 1-on/2-off rotation
     * where ShiftPunchGrouper's eligibility window used to reach EXACTLY to
     * the next on-day's own scheduled start, so its on-time arrival was
     * absorbed into the previous (stale) shift instead of opening its own -
     * and that day got ZERO dtrs row, which DtrController's uncovered-day
     * pass then renders as Absent even though the employee genuinely punched.
     */
    public function test_import_gives_the_next_on_days_own_arrival_its_own_row_after_two_rest_days(): void
    {
        $shift = Shift::create([
            'name' => '24-Hour Duty', 'time_in' => '08:00', 'time_out' => '08:00',
            'break_out' => null, 'break_in' => null, 'crosses_midnight' => true, 'is_active' => true,
        ]);
        $user = $this->createEmployee();
        app(ShiftAssignmentService::class)->assign(
            $user, $shift->id, Carbon::parse('2026-08-10'), null, null, null, [0, 1, 2, 3, 4, 5, 6], true
        );
        foreach (['2026-08-11', '2026-08-12'] as $date) {
            EmployeeShiftSchedule::create([
                'user_id' => $user->id, 'date' => $date, 'shift_id' => null, 'type' => 'rest', 'created_by' => $user->id,
            ]);
        }

        foreach (['2026-08-10 08:05:00', '2026-08-11 08:02:00', '2026-08-13 08:00:00'] as $dt) {
            [$d, $t] = explode(' ', $dt);
            AttendanceLog::create([
                'user_id' => $user->id, 'emp_no' => $user->EmpNo, 'logdate' => $d, 'logtime' => $t,
            ]);
        }

        app(PersonnelLogImportService::class)->recomputeDtr($user, '2026-08-10', '2026-08-13');

        $openingRow = Dtr::where('employee_id', $user->id)->whereDate('date', '2026-08-10')->firstOrFail();
        $this->assertSame('08:05:00', $openingRow->time_in_am);
        $this->assertSame('08:02:00', $openingRow->time_out_pm);
        $this->assertSame('late', $openingRow->status); // 5 min after the 08:00 scheduled start

        $nextOnDayRow = Dtr::where('employee_id', $user->id)->whereDate('date', '2026-08-13')->first();
        $this->assertNotNull($nextOnDayRow, '2026-08-13 must have its own dtrs row, not render as Absent.');
        $this->assertSame('08:00:00', $nextOnDayRow->time_in_am);

        $this->assertDatabaseMissing('dtrs', ['employee_id' => $user->id, 'date' => '2026-08-11']);
        $this->assertDatabaseMissing('dtrs', ['employee_id' => $user->id, 'date' => '2026-08-12']);
    }

    /**
     * Regression for a real reported export failure ("Undefined array key
     * 17"): Form48ExportService::buildRecords()'s attendance_logs fallback
     * (used for a day with raw punches but no dtrs row yet) must not crash
     * just because that day has no DtrExcuse row - which is true for the
     * overwhelming majority of days.
     */
    public function test_form48_export_fallback_does_not_crash_for_a_day_with_no_dtr_and_no_excuse_row(): void
    {
        $user = $this->createEmployee(); // standard day: 08:00 / 11:00 / 13:00 / 17:00

        AttendanceLog::create([
            'user_id' => $user->id,
            'emp_no' => $user->EmpNo,
            'logdate' => '2026-07-17',
            'logtime' => '08:00:00',
        ]);

        $records = app(Form48ExportService::class)->buildRecords($user->id, '2026-07-01', '2026-07-31');

        $this->assertArrayHasKey(17, $records);
        $this->assertSame('2026-07-17', $records[17]['date']);
    }

    /**
     * Regression for a real reported case (GUIANG, LIZAFE): a missing AM-in
     * punch with AM-out present proves the employee was there that morning,
     * but the real late_minutes calculators never charge lateness without an
     * actual AM-in punch, so the stored dtrs value stays 0. DtrController's
     * DTR page already fills this gap with DtrPunchResolver::imputedLateMinutes()
     * (the full workStart->morningEnd block - 08:00-12:00 = 240 minutes for
     * the standard day schedule). Form48ExportService::buildRecords() must
     * apply the same fallback for its DTR-row-driven path, or the exported
     * spreadsheet silently disagrees with what the DTR page shows.
     */
    public function test_form48_export_imputes_late_minutes_for_a_missing_am_in_dtr_row(): void
    {
        $user = $this->createEmployee();
        $this->assignEightToTwelveThirteenToFiveShift($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => '12:00:00',
            'time_in_pm' => '13:00:00',
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'status' => 'missing_in',
            'source' => 'biometric',
        ]);

        $day = (int) Carbon::parse(self::DATE)->day;
        $records = app(Form48ExportService::class)->buildRecords($user->id, self::DATE, self::DATE);

        $this->assertSame(240, $records[$day]['tardiness']);
        $this->assertSame(0, $records[$day]['undertime']);
    }

    /** Same missing-AM-in scenario, but via the attendance_logs fallback path (no dtrs row at all). */
    public function test_form48_export_imputes_late_minutes_for_a_missing_am_in_via_the_log_fallback(): void
    {
        $user = $this->createEmployee();
        $this->assignEightToTwelveThirteenToFiveShift($user);

        foreach (['12:00:00', '13:00:00', '17:00:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $user->id,
                'emp_no' => $user->EmpNo,
                'logdate' => self::DATE,
                'logtime' => $time,
            ]);
        }

        $day = (int) Carbon::parse(self::DATE)->day;
        $records = app(Form48ExportService::class)->buildRecords($user->id, self::DATE, self::DATE);

        $this->assertSame(240, $records[$day]['tardiness']);
        $this->assertSame(0, $records[$day]['undertime']);
    }

    /** A normal complete, on-time day must not have anything imputed on top of it. */
    public function test_form48_export_does_not_impute_anything_on_a_complete_on_time_day(): void
    {
        $user = $this->createEmployee();
        $this->assignEightToTwelveThirteenToFiveShift($user);

        foreach (['08:00:00', '12:00:00', '13:00:00', '17:00:00'] as $time) {
            AttendanceLog::create([
                'user_id' => $user->id,
                'emp_no' => $user->EmpNo,
                'logdate' => self::DATE,
                'logtime' => $time,
            ]);
        }

        $day = (int) Carbon::parse(self::DATE)->day;
        $records = app(Form48ExportService::class)->buildRecords($user->id, self::DATE, self::DATE);

        $this->assertSame(0, $records[$day]['tardiness']);
        $this->assertSame(0, $records[$day]['undertime']);
    }

    /**
     * A no-break (2-punch: am_in + pm_out only) shift has no am_out/pm_in
     * columns at all, so the with-break-only imputation logic could never
     * fire for it - PM Out proving the day happened is the only possible
     * sibling proof for a missing AM In, and the imputed block is the full
     * workStart->workEnd span since there's no half-day midpoint to anchor to.
     */
    public function test_imputed_late_minutes_covers_a_missing_am_in_on_a_no_break_shift(): void
    {
        $mins = (new DtrPunchResolver)->imputedLateMinutes(
            null, null, null, '17:00:00', self::DATE, $this->specDay(noBreak: true)
        );

        $this->assertSame(540, $mins); // 08:00 -> 17:00
    }

    public function test_imputed_undertime_minutes_stays_zero_before_a_no_break_shift_ends(): void
    {
        Carbon::setTestNow(self::DATE.' 16:00:00');

        $mins = (new DtrPunchResolver)->imputedUndertimeMinutes(
            '08:00:00', null, null, null, self::DATE, $this->specDay(noBreak: true)
        );

        Carbon::setTestNow();

        $this->assertSame(0, $mins);
    }

    public function test_imputed_undertime_minutes_covers_a_missing_pm_out_on_a_no_break_shift_after_it_ends(): void
    {
        Carbon::setTestNow(self::DATE.' 18:00:00');

        $mins = (new DtrPunchResolver)->imputedUndertimeMinutes(
            '08:00:00', null, null, null, self::DATE, $this->specDay(noBreak: true)
        );

        Carbon::setTestNow();

        $this->assertSame(540, $mins); // 08:00 -> 17:00
    }

    /**
     * A Field Work Shift's in_only/out_only schedule expects exactly one
     * punch for the date - there is no sibling-proves-presence concept to
     * impute against, even if noBreak also happens to be true on the row.
     * Its own absence logic belongs entirely to
     * WeeklyPunchPairReconciliationService.
     */
    public function test_no_break_imputation_never_applies_to_a_field_work_pair_schedule(): void
    {
        $inOnly = new WorkSchedule('08:00', '13:00', '17:00', '12:00', '14:00', false, true, false, 'in_only');
        $outOnly = new WorkSchedule('08:00', '13:00', '17:00', '12:00', '14:00', false, true, false, 'out_only');

        $late = (new DtrPunchResolver)->imputedLateMinutes(null, null, null, '17:00:00', self::DATE, $inOnly);

        Carbon::setTestNow(self::DATE.' 18:00:00');
        $undertime = (new DtrPunchResolver)->imputedUndertimeMinutes('08:00:00', null, null, null, self::DATE, $outOnly);
        Carbon::setTestNow();

        $this->assertSame(0, $late);
        $this->assertSame(0, $undertime);
    }

    /** End-to-end: Form48ExportService must apply the same no-break imputation via its real call-site wiring. */
    public function test_form48_export_imputes_late_minutes_for_a_missing_am_in_on_a_no_break_shift(): void
    {
        $user = $this->createEmployee();
        $this->assignEightToFiveNoBreakShift($user);

        Carbon::setTestNow(self::DATE.' 18:00:00');

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => null,
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'status' => 'missing_in',
            'source' => 'biometric',
        ]);

        $day = (int) Carbon::parse(self::DATE)->day;
        $records = app(Form48ExportService::class)->buildRecords($user->id, self::DATE, self::DATE);

        Carbon::setTestNow();

        $this->assertSame(540, $records[$day]['tardiness']);
        $this->assertSame(0, $records[$day]['undertime']);
    }

    /**
     * Regression for a real reported case (BULAMBOT, FRANIE): PM In missing
     * while PM Out is present (proving the day ended) is the mirror of the
     * existing AM-in-missing case - an uncertain START of a segment, charged
     * as late for the full fixed lunchReturn->workEnd block, not undertime.
     */
    public function test_imputed_late_minutes_covers_a_missing_pm_in_with_pm_out_present(): void
    {
        $mins = (new DtrPunchResolver)->imputedLateMinutes(
            '07:56:00', '12:02:00', null, '17:00:00', self::DATE, $this->specDay()
        );

        $this->assertSame(240, $mins); // 13:00 -> 17:00
    }

    public function test_imputed_undertime_minutes_stays_zero_for_a_missing_am_out_before_morning_end(): void
    {
        Carbon::setTestNow(self::DATE.' 10:00:00');

        $mins = (new DtrPunchResolver)->imputedUndertimeMinutes(
            '08:00:00', null, null, null, self::DATE, $this->specDay()
        );

        Carbon::setTestNow();

        $this->assertSame(0, $mins);
    }

    /** Mirror of the existing PM-out-missing case: an uncertain END of the AM segment, charged as undertime. */
    public function test_imputed_undertime_minutes_covers_a_missing_am_out_after_morning_end(): void
    {
        Carbon::setTestNow(self::DATE.' 12:30:00');

        $mins = (new DtrPunchResolver)->imputedUndertimeMinutes(
            '08:00:00', null, null, null, self::DATE, $this->specDay()
        );

        Carbon::setTestNow();

        $this->assertSame(240, $mins); // 08:00 -> 12:00 (specDay's morningEnd)
    }

    /**
     * The "Incomplete Logs" shape (am_in + pm_out present, am_out + pm_in
     * missing) now imputes both components independently and additively -
     * confirms neither new check interferes with the other.
     */
    public function test_imputed_late_and_undertime_both_apply_independently_for_opposite_corner_gaps(): void
    {
        Carbon::setTestNow(self::DATE.' 18:00:00');

        $late = (new DtrPunchResolver)->imputedLateMinutes(
            '07:27:00', null, null, '16:02:00', self::DATE, $this->specDay()
        );
        $undertime = (new DtrPunchResolver)->imputedUndertimeMinutes(
            '07:27:00', null, null, '16:02:00', self::DATE, $this->specDay()
        );

        Carbon::setTestNow();

        $this->assertSame(240, $late);      // lunchReturn(13:00) -> workEnd(17:00), PM-in missing
        $this->assertSame(240, $undertime); // workStart(08:00) -> morningEnd(12:00), AM-out missing
    }

    /**
     * The new AM-out/PM-in checks must be guarded on punchRequirement itself,
     * not just on which columns happen to be null - an out_only day
     * genuinely populates time_out_pm while time_in_pm stays structurally
     * null, which would otherwise false-positive the new PM-in-missing check.
     */
    public function test_new_imputation_shapes_never_apply_to_a_field_work_pair_schedule(): void
    {
        $inOnly = new WorkSchedule('08:00', '13:00', '17:00', '12:00', '14:00', false, false, false, 'in_only');
        $outOnly = new WorkSchedule('08:00', '13:00', '17:00', '12:00', '14:00', false, false, false, 'out_only');

        Carbon::setTestNow(self::DATE.' 18:00:00');

        // in_only: am_in present, am_out genuinely absent - must not impute undertime.
        $undertime = (new DtrPunchResolver)->imputedUndertimeMinutes(
            '08:00:00', null, null, null, self::DATE, $inOnly
        );
        // out_only: pm_out present, pm_in structurally always null - must not impute late.
        $late = (new DtrPunchResolver)->imputedLateMinutes(
            null, null, null, '17:00:00', self::DATE, $outOnly
        );

        Carbon::setTestNow();

        $this->assertSame(0, $undertime);
        $this->assertSame(0, $late);
    }

    /** End-to-end: Form48ExportService must apply the missing-PM-in imputation via its real call-site wiring. */
    public function test_form48_export_imputes_late_minutes_for_a_missing_pm_in_dtr_row(): void
    {
        $user = $this->createEmployee();
        $this->assignEightToTwelveThirteenToFiveShift($user);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:00:00',
            'time_out_am' => '12:00:00',
            'time_in_pm' => null,
            'time_out_pm' => '17:00:00',
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'status' => 'missing_in',
            'source' => 'biometric',
        ]);

        $day = (int) Carbon::parse(self::DATE)->day;
        $records = app(Form48ExportService::class)->buildRecords($user->id, self::DATE, self::DATE);

        $this->assertSame(240, $records[$day]['tardiness']);
        $this->assertSame(0, $records[$day]['undertime']);
    }

    /**
     * Regression for a real reported bug: the Form 48 download showed "Field
     * Work" for a date that actually had a real biometric log, discarding the
     * punch times entirely. fillDailyRows() applied the fieldWorkMap/wfhMap
     * label unconditionally whenever an EmployeeShiftSchedule override existed
     * for the date, never checking whether real punch data ($rec) was present -
     * unlike the ETA/Office Order/Excuse branches right next to it, and unlike
     * DtrController::data(), where a dtrs row always wins outright over a
     * field-work/wfh override. Real punch data must now win here too.
     */
    public function test_form48_export_shows_real_punches_over_field_work_label_when_both_exist(): void
    {
        $user = $this->createEmployee();
        $this->assignEightToTwelveThirteenToFiveShift($user);

        EmployeeShiftSchedule::create([
            'user_id' => $user->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'field_work',
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
            'status' => 'present',
            'source' => 'biometric',
        ]);

        [$sheet, $day, $row, $service] = $this->fillForm48Sheet($user, self::DATE, self::DATE, [
            'fieldWorkMap' => app(Form48ExportService::class)->buildFieldWorkMap($user->id, self::DATE, self::DATE),
        ]);

        $this->assertSame('08:00', $sheet->getCell('C'.$row)->getValue());
        $this->assertSame('12:00', $sheet->getCell('D'.$row)->getValue());
        $this->assertSame('13:00', $sheet->getCell('E'.$row)->getValue());
        $this->assertSame('17:00', $sheet->getCell('F'.$row)->getValue());
        $this->assertNotSame('Field Work', $sheet->getCell('C'.$row)->getValue());
    }

    /** Same scenario as above, but for a Work-From-Home override. */
    public function test_form48_export_shows_real_punches_over_wfh_label_when_both_exist(): void
    {
        $user = $this->createEmployee();
        $this->assignEightToTwelveThirteenToFiveShift($user);

        EmployeeShiftSchedule::create([
            'user_id' => $user->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'wfh',
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
            'status' => 'present',
            'source' => 'biometric',
        ]);

        [$sheet, $day, $row] = $this->fillForm48Sheet($user, self::DATE, self::DATE, [
            'wfhMap' => app(Form48ExportService::class)->buildWfhMap($user->id, self::DATE, self::DATE),
        ]);

        $this->assertSame('08:00', $sheet->getCell('C'.$row)->getValue());
        $this->assertNotSame('Work From Home', $sheet->getCell('C'.$row)->getValue());
    }

    /**
     * Regression guard: a genuinely punch-free field-work day (no dtrs row,
     * no attendance_logs) must still render the "Field Work" label - the fix
     * above must not remove this legitimate case.
     */
    public function test_form48_export_still_shows_field_work_label_when_there_are_no_punches(): void
    {
        $user = $this->createEmployee();
        $this->assignEightToTwelveThirteenToFiveShift($user);

        EmployeeShiftSchedule::create([
            'user_id' => $user->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        [$sheet, $day, $row] = $this->fillForm48Sheet($user, self::DATE, self::DATE, [
            'fieldWorkMap' => app(Form48ExportService::class)->buildFieldWorkMap($user->id, self::DATE, self::DATE),
        ]);

        $this->assertSame('Field Work', $sheet->getCell('C'.$row)->getValue());
    }

    /**
     * Regression for the follow-up report: with only the "! $rec" gate, a
     * field-work day with SOME but not all 4 punches lost the "Field Work"
     * label entirely (fell straight through to a blank normal write) instead
     * of showing the real punch(es) plus a "Field Work" label for the
     * still-missing slots - merged into one cell, mirroring the Office
     * Order branch's sequential partial-punch merge pattern.
     */
    public function test_form48_export_shows_field_work_label_for_missing_slots_alongside_a_partial_punch(): void
    {
        $user = $this->createEmployee();
        $this->assignEightToTwelveThirteenToFiveShift($user);

        EmployeeShiftSchedule::create([
            'user_id' => $user->id,
            'date' => self::DATE,
            'shift_id' => null,
            'type' => 'field_work',
        ]);

        Dtr::create([
            'employee_id' => $user->id,
            'date' => self::DATE,
            'time_in_am' => '08:00:00',
            'time_out_am' => null,
            'time_in_pm' => null,
            'time_out_pm' => null,
            'late_minutes' => 0,
            'undertime_minutes' => 0,
            'status' => 'missing_out',
            'source' => 'biometric',
        ]);

        [$sheet, $day, $row] = $this->fillForm48Sheet($user, self::DATE, self::DATE, [
            'fieldWorkMap' => app(Form48ExportService::class)->buildFieldWorkMap($user->id, self::DATE, self::DATE),
        ]);

        // am_in is real and stands alone; am_out->pm_out (D:F) are merged into one "Field Work" cell.
        $this->assertSame('08:00', $sheet->getCell('C'.$row)->getValue());
        $this->assertSame('Field Work', $sheet->getCell('D'.$row)->getValue());
        $this->assertContains('D'.$row.':F'.$row, $sheet->getMergeCells());
    }

    /**
     * @param  array<string, array<int, mixed>>  $maps  keyed by fill()'s map param names
     *                                                  (leaveMap/etaMap/locatorMap/restDayMap/
     *                                                  fieldWorkMap/excuseMap/officeOrderMap/wfhMap)
     * @return array{0: Worksheet, 1: int, 2: int, 3: Form48ExportService}
     */
    private function fillForm48Sheet(User $user, string $from, string $to, array $maps = []): array
    {
        $service = app(Form48ExportService::class);
        $records = $service->buildRecords($user->id, $from, $to);

        $spreadsheet = IOFactory::load(storage_path('app/templates/form48.xls'));
        $sheet = $spreadsheet->getActiveSheet();

        $service->fill(
            $sheet,
            $records,
            $user,
            'June 2026',
            $from,
            $maps['leaveMap'] ?? [],
            $maps['etaMap'] ?? [],
            $maps['locatorMap'] ?? [],
            $maps['restDayMap'] ?? [],
            $maps['fieldWorkMap'] ?? [],
            $maps['excuseMap'] ?? [],
            $maps['officeOrderMap'] ?? [],
            $maps['wfhMap'] ?? [],
        );

        $day = (int) Carbon::parse($from)->day;
        $row = 11 + $day;

        return [$sheet, $day, $row, $service];
    }
}
