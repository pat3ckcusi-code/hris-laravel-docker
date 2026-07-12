<?php

namespace Tests\Feature\Attendance;

use App\Console\Commands\SyncShiftAssignmentCache;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\ShiftAssignmentService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class ShiftAssignmentTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function shiftModel(string $name): Shift
    {
        return Shift::create([
            'name' => $name,
            'time_in' => '08:00',
            'break_out' => '12:00',
            'break_in' => '13:00',
            'time_out' => '17:00',
            'is_active' => true,
        ]);
    }

    private function service(): ShiftAssignmentService
    {
        return app(ShiftAssignmentService::class);
    }

    // ── Truncation-on-create ──────────────────────────────────────────────────

    public function test_bounded_window_inside_open_ended_assignment_falls_back_to_standard_day_afterward(): void
    {
        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('A');
        $shiftB = $this->shiftModel('B');

        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2000-01-01'), null, null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null);

        $rows = ShiftAssignment::forUser($employee->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows, 'No resumption row should be created - once the window closes, nothing covers that date.');

        $this->assertSame($shiftA->id, $rows[0]->shift_id);
        $this->assertSame('2026-07-31', $rows[0]->effective_until->toDateString());

        $this->assertSame($shiftB->id, $rows[1]->shift_id);
        $this->assertSame('2026-08-01', $rows[1]->effective_from->toDateString());
        $this->assertSame('2026-08-31', $rows[1]->effective_until->toDateString());

        $this->assertNull(
            ShiftAssignment::forUser($employee->id)->effectiveOn(Carbon::parse('2026-09-01'))->first(),
            'Once the bounded window closes, no row covers the date - falls back to Standard Day.'
        );
    }

    public function test_new_assignment_after_a_future_rows_own_end_leaves_it_untouched(): void
    {
        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('A');
        $shiftB = $this->shiftModel('B');

        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-09-01'), null, null);

        $rows = ShiftAssignment::forUser($employee->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame($shiftA->id, $rows[0]->shift_id);
        $this->assertSame('2026-08-01', $rows[0]->effective_from->toDateString());
        $this->assertSame('2026-08-31', $rows[0]->effective_until->toDateString());
        $this->assertSame($shiftB->id, $rows[1]->shift_id);
    }

    public function test_back_to_back_adjacent_ranges_leave_prior_row_untouched(): void
    {
        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('A');
        $shiftB = $this->shiftModel('B');

        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), null);

        $rows = ShiftAssignment::forUser($employee->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-08-31', $rows[0]->effective_until->toDateString());
        $this->assertSame('2026-09-01', $rows[1]->effective_from->toDateString());
    }

    public function test_swallowing_a_future_scheduled_row_never_deletes_it(): void
    {
        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('A');
        $shiftB = $this->shiftModel('B');
        $shiftC = $this->shiftModel('C');

        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2000-01-01'), null, null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null);
        $this->service()->assign($employee, $shiftC->id, Carbon::parse('2026-07-15'), Carbon::parse('2026-09-30'), null);

        $rows = ShiftAssignment::forUser($employee->id)->orderBy('effective_from')->get();

        $swallowed = $rows->firstWhere('shift_id', $shiftB->id);
        $this->assertNotNull($swallowed, 'The swallowed row must remain in the table, never deleted.');
        $this->assertTrue(
            $swallowed->effective_until->lt($swallowed->effective_from),
            'A fully-swallowed future row becomes an inverted, permanently unmatchable range.'
        );

        // Resolution stays correct end-to-end even though the history table
        // accumulates a superseded fragment from the earlier assign() call.
        $this->assertSame($shiftA->id, ShiftAssignment::forUser($employee->id)->effectiveOn(Carbon::parse('2026-07-10'))->first()->shift_id);

        $resolved = ShiftAssignment::forUser($employee->id)->effectiveOn(Carbon::parse('2026-08-15'))->first();
        $this->assertSame($shiftC->id, $resolved->shift_id);

        // Once C's window closes, nothing resumes - falls back to Standard Day.
        $this->assertNull(ShiftAssignment::forUser($employee->id)->effectiveOn(Carbon::parse('2026-10-01'))->first());
    }

    public function test_open_ended_reassignment_truncates_prior_without_creating_a_resumption_row(): void
    {
        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('A');
        $shiftB = $this->shiftModel('B');

        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2000-01-01'), null, null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-07-01'), null, null);

        $rows = ShiftAssignment::forUser($employee->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows, 'An open-ended new assignment must not synthesize a resumption row.');
        $this->assertSame('2026-06-30', $rows[0]->effective_until->toDateString());
        $this->assertNull($rows[1]->effective_until);
    }

    // ── WorkSchedule resolution ───────────────────────────────────────────────

    public function test_forUserOnDate_resolves_future_dated_assignment_only_once_it_starts(): void
    {
        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('Day');
        $shiftB = $this->shiftModel('Night');
        $shiftB->update(['time_in' => '22:00', 'time_out' => '06:00', 'crosses_midnight' => true]);

        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2000-01-01'), null, null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null);

        $before = WorkSchedule::forUserOnDate($employee, Carbon::parse('2026-07-31'));
        $this->assertSame('08:00', $before->workStart);

        $within = WorkSchedule::forUserOnDate($employee, Carbon::parse('2026-08-15'));
        $this->assertSame('22:00', $within->workStart);
        $this->assertTrue($within->crossesMidnight);
    }

    public function test_forUserOnDate_falls_back_to_standard_day_once_the_window_closes(): void
    {
        WorkSchedule::flushGlobal();
        // Anchor "today" outside both assignment windows below, so users.shift_id's
        // cache sync (which runs at assign()-time, based on whatever "today" is then)
        // doesn't accidentally pick up shift A and make the assertion coincidental.
        $this->travelTo(Carbon::parse('2026-09-15'));

        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('A');
        $shiftA->update(['time_in' => '10:00', 'time_out' => '19:00']);
        $shiftB = $this->shiftModel('B');
        $shiftB->update(['time_in' => '09:00', 'time_out' => '18:00']);

        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2000-01-01'), null, null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null);

        $after = WorkSchedule::forUserOnDate($employee, Carbon::parse('2026-09-15'));
        $this->assertSame(
            WorkSchedule::global()->workStart,
            $after->workStart,
            'Once the bounded window closes, the employee falls back to Standard Day, not shift A.'
        );
        $this->assertNotSame('10:00', $after->workStart);
    }

    // ── users.shift_id cache sync ─────────────────────────────────────────────

    public function test_sync_cache_command_flips_shift_id_forward_when_a_future_assignment_starts(): void
    {
        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('A');
        $shiftB = $this->shiftModel('B');

        $this->travelTo(Carbon::parse('2026-07-10'));
        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2000-01-01'), null, null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-08-01'), null, null);

        $this->assertSame($shiftA->id, $employee->refresh()->shift_id, 'Future-dated assignment must not affect today\'s cache yet.');

        $this->travelTo(Carbon::parse('2026-08-01'));
        $this->artisan(SyncShiftAssignmentCache::class)->assertExitCode(0);

        $this->assertSame($shiftB->id, $employee->refresh()->shift_id);
    }

    public function test_sync_cache_command_clears_shift_id_to_standard_day_after_expiry(): void
    {
        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('A');
        $shiftB = $this->shiftModel('B');

        $this->travelTo(Carbon::parse('2026-07-10'));
        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2000-01-01'), null, null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null);

        $this->travelTo(Carbon::parse('2026-09-01'));
        $this->artisan(SyncShiftAssignmentCache::class)->assertExitCode(0);

        $this->assertNull($employee->refresh()->shift_id, 'Nothing resumes after expiry - the employee falls back to Standard Day.');
    }

    public function test_sync_cache_command_skips_dtr_exempt_employees(): void
    {
        $employee = $this->createEmployee(['dtr_exempt' => true]);
        $shiftA = $this->shiftModel('A');

        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2000-01-01'), null, null);
        // syncCache() inside assign() already skips exempt users, so shift_id stays null.
        $this->assertNull($employee->refresh()->shift_id);

        $this->artisan(SyncShiftAssignmentCache::class)->assertExitCode(0);

        $this->assertNull($employee->refresh()->shift_id);
    }

    // ── Shift template deletion guard ─────────────────────────────────────────

    public function test_shift_destroy_is_blocked_by_a_future_only_assignment(): void
    {
        $employee = $this->createEmployee();
        $shift = $this->shiftModel('Future Only');

        // Scheduled for the future: never reflected in users.shift_id today.
        $this->service()->assign($employee, $shift->id, Carbon::parse('2026-08-01'), null, null);
        $this->assertNull($employee->refresh()->shift_id);

        $this->actingAs($this->createTimeKeeper())
            ->delete(route('attendance.shifts.destroy', $shift))
            ->assertSessionHas('shift_error');

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
    }

    // ── Same-day correction ────────────────────────────────────────────────────

    public function test_reassigning_on_the_same_start_date_replaces_the_row_without_a_unique_conflict(): void
    {
        $employee = $this->createEmployee();
        $shiftA = $this->shiftModel('A');
        $shiftB = $this->shiftModel('B');

        $this->service()->assign($employee, $shiftA->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null);
        $this->service()->assign($employee, $shiftB->id, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), null);

        $rows = ShiftAssignment::forUser($employee->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($shiftB->id, $rows->first()->shift_id);
    }

    // ── Day-of-week scoped concurrent assignments ─────────────────────────────

    public function test_concurrent_mwf_and_tth_assignments_resolve_correct_shift_per_weekday(): void
    {
        $employee = $this->createEmployee();
        $mwfShift = Shift::create([
            'name' => 'MWF 7-4', 'time_in' => '07:00', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '16:00', 'is_active' => true,
        ]);
        $tthShift = Shift::create([
            'name' => 'TTH 8:30-5:30', 'time_in' => '08:30', 'break_out' => '12:00',
            'break_in' => '13:00', 'time_out' => '17:30', 'is_active' => true,
        ]);

        // Mon=1, Wed=3, Fri=5 / Tue=2, Thu=4 (Carbon's dayOfWeek numbering).
        $this->service()->assign($employee, $mwfShift->id, Carbon::parse('2026-08-01'), null, null, [1, 3, 5]);
        $this->service()->assign($employee, $tthShift->id, Carbon::parse('2026-08-01'), null, null, [2, 4]);

        $rows = ShiftAssignment::forUser($employee->id)->get();
        $this->assertCount(2, $rows, 'Disjoint day-of-week scopes must not truncate each other.');

        $monday = WorkSchedule::forUserOnDate($employee, Carbon::parse('2026-08-03'));
        $this->assertSame('07:00', $monday->workStart);
        $this->assertSame('16:00', $monday->workEnd);

        $tuesday = WorkSchedule::forUserOnDate($employee, Carbon::parse('2026-08-04'));
        $this->assertSame('08:30', $tuesday->workStart);
        $this->assertSame('17:30', $tuesday->workEnd);
    }

    public function test_day_not_covered_by_any_scoped_assignment_is_a_non_workday(): void
    {
        $employee = $this->createEmployee();
        $mwfShift = $this->shiftModel('MWF');
        $tthShift = $this->shiftModel('TTH');

        $this->service()->assign($employee, $mwfShift->id, Carbon::parse('2026-08-01'), null, null, [1, 3, 5]);
        $this->service()->assign($employee, $tthShift->id, Carbon::parse('2026-08-01'), null, null, [2, 4]);

        // Saturday (2026-08-08) is covered by neither scope - must not fall
        // back to the global Standard Day shift.
        $this->assertFalse(WorkSchedule::isWorkday($employee, Carbon::parse('2026-08-08')));
    }

    public function test_new_all_days_assignment_truncates_an_existing_mwf_and_tth_pair(): void
    {
        $employee = $this->createEmployee();
        $mwfShift = $this->shiftModel('MWF');
        $tthShift = $this->shiftModel('TTH');
        $allDaysShift = $this->shiftModel('All Days');

        $this->service()->assign($employee, $mwfShift->id, Carbon::parse('2026-08-01'), null, null, [1, 3, 5]);
        $this->service()->assign($employee, $tthShift->id, Carbon::parse('2026-08-01'), null, null, [2, 4]);
        $this->service()->assign($employee, $allDaysShift->id, Carbon::parse('2026-09-01'), null, null);

        $rows = ShiftAssignment::forUser($employee->id)->orderBy('effective_from')->get();
        $this->assertCount(3, $rows);
        $this->assertSame('2026-08-31', $rows[0]->effective_until->toDateString());
        $this->assertSame('2026-08-31', $rows[1]->effective_until->toDateString());
        $this->assertSame($allDaysShift->id, $rows[2]->shift_id);
        $this->assertNull($rows[2]->days_of_week);
    }

    public function test_sync_cache_command_flips_shift_id_between_mwf_and_tth_by_day(): void
    {
        $employee = $this->createEmployee();
        $mwfShift = $this->shiftModel('MWF');
        $tthShift = $this->shiftModel('TTH');

        $this->travelTo(Carbon::parse('2026-08-03')); // Monday
        $this->service()->assign($employee, $mwfShift->id, Carbon::parse('2026-08-01'), null, null, [1, 3, 5]);
        $this->service()->assign($employee, $tthShift->id, Carbon::parse('2026-08-01'), null, null, [2, 4]);

        $this->assertSame($mwfShift->id, $employee->refresh()->shift_id, 'assign() syncs the cache immediately for the day it runs on.');

        $this->travelTo(Carbon::parse('2026-08-04')); // Tuesday
        $this->artisan(SyncShiftAssignmentCache::class)->assertExitCode(0);

        $this->assertSame($tthShift->id, $employee->refresh()->shift_id);
    }
}
