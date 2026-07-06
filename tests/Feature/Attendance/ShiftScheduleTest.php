<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\OicAssignment;
use App\Models\Shift;
use App\Models\ShiftManagementGrant;
use App\Services\AttendanceMonitoringExportService;
use App\Services\DtrPunchResolver;
use App\Services\PersonnelLogImportService;
use App\Services\ShiftPunchGrouper;
use App\Support\WorkSchedule;
use Carbon\Carbon;
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
            'no_break' => true,
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
}
