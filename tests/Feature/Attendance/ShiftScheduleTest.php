<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Shift;
use App\Services\DtrPunchResolver;
use App\Services\ShiftPunchGrouper;
use App\Support\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_time_keeper_can_assign_shift_to_employee(): void
    {
        $shift = $this->nightShiftModel();
        $employee = $this->createEmployee();

        $this->actingAs($this->createTimeKeeper())
            ->put(route('attendance.schedules.update', $employee), ['shift_id' => $shift->id])
            ->assertRedirect();

        $this->assertSame($shift->id, $employee->refresh()->shift_id);
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
}
