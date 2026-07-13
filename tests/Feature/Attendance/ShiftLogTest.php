<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Time Keeper / HR Manager company-wide Shift Logs page: a full chronological
 * log of every shift-related change. Tardiness/undertime attendance
 * monitoring is also available to Time Keeper/HR Manager via their own
 * cross-department Monitoring Matrix, separate from the Administrative
 * Officer's dept-scoped one.
 */
class ShiftLogTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makeDepartment(string $name): Department
    {
        return Department::create([
            'DeptCode' => strtoupper(str_replace(' ', '_', $name)),
            'Dept_name' => $name,
            'Designation' => $name,
        ]);
    }

    public function test_time_keeper_can_view_shift_logs_across_all_departments(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $tk = $this->createTimeKeeper();
        $employeeA = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'InDeptA']);
        $employeeB = $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'last_name' => 'InDeptB']);

        $this->actingAs($tk)->put(route('attendance.schedules.exempt', $employeeA))->assertRedirect();
        $this->actingAs($tk)->put(route('attendance.schedules.exempt', $employeeB))->assertRedirect();

        $this->actingAs($tk)
            ->get(route('attendance.shift-logs'))
            ->assertStatus(200)
            ->assertSee('InDeptA')
            ->assertSee('InDeptB');
    }

    /**
     * Regression: seeded/demo role accounts (see UsersTableSeeder) only ever
     * set `name`, never first_name/last_name - the Actor column used to
     * render blank for these accounts instead of falling back to `name`.
     */
    public function test_actor_column_falls_back_to_name_when_first_and_last_name_are_blank(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $tk = $this->createTimeKeeper([
            'first_name' => null,
            'last_name' => null,
            'name' => 'Time Keeper User',
        ]);
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'NameOnlyActor']);

        $this->actingAs($tk)->put(route('attendance.schedules.exempt', $employee))->assertRedirect();

        $this->actingAs($tk)
            ->get(route('attendance.shift-logs'))
            ->assertStatus(200)
            ->assertSee('Time Keeper User');
    }

    public function test_employee_and_department_head_cannot_access_shift_logs(): void
    {
        $deptA = $this->makeDepartment('Dept A');

        $this->actingAs($this->createEmployee())
            ->get(route('attendance.shift-logs'))
            ->assertStatus(403);

        $this->actingAs($this->createDepartmentHead(['Dept_id' => $deptA->Dept_id]))
            ->get(route('attendance.shift-logs'))
            ->assertStatus(403);

        $this->actingAs($this->createAdminOfficer(['Dept_id' => $deptA->Dept_id]))
            ->get(route('attendance.shift-logs'))
            ->assertStatus(403);
    }

    public function test_shift_assignment_is_logged_and_shown(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $tk = $this->createTimeKeeper();
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'Roster']);
        $shift = Shift::create([
            'name' => 'Day Shift',
            'time_in' => '08:00',
            'break_out' => '12:00',
            'break_in' => '13:00',
            'time_out' => '17:00',
        ]);

        $this->actingAs($tk)
            ->put(route('attendance.schedules.update', $employee), ['shift_id' => $shift->id])
            ->assertRedirect();

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_assigned',
            'target_id' => $employee->id,
        ]);

        $tkName = trim("{$tk->first_name} {$tk->last_name}");

        $this->actingAs($tk)
            ->get(route('attendance.shift-logs'))
            ->assertSee('Roster')
            ->assertSee('Day Shift')
            ->assertSee($tkName);
    }

    public function test_shift_assignment_with_days_of_week_shows_day_scope_in_log(): void
    {
        // Freeze on a day outside the assigned scope (Sat) so this can never
        // pass by coincidence of which real-world day the suite happens to
        // run on - the log must name the submitted shift regardless of
        // whether it's cached as "today's" shift.
        $this->travelTo(Carbon::parse('2026-07-11'));

        $deptA = $this->makeDepartment('Dept A');
        $tk = $this->createTimeKeeper();
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'Roster']);
        $shift = Shift::create([
            'name' => 'MWF 7-4',
            'time_in' => '07:00',
            'break_out' => '12:00',
            'break_in' => '13:00',
            'time_out' => '16:00',
        ]);

        $this->actingAs($tk)
            ->put(route('attendance.schedules.update', $employee), [
                'shift_id' => $shift->id,
                'days_of_week' => [1, 3, 5],
            ])
            ->assertRedirect();

        $this->actingAs($tk)
            ->get(route('attendance.shift-logs'))
            ->assertSee('MWF 7-4')
            ->assertSee('Mon, Wed, Fri');
    }

    public function test_exemption_toggle_is_logged(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $tk = $this->createTimeKeeper();
        $employee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($tk)
            ->put(route('attendance.schedules.exempt', $employee))
            ->assertRedirect();

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'dtr_exemption_toggled',
            'target_id' => $employee->id,
        ]);
    }

    public function test_shift_template_crud_is_logged(): void
    {
        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->post(route('attendance.shifts.store'), [
            'name' => 'Mid Shift',
            'time_in' => '10:00',
            'break_out' => '14:00',
            'break_in' => '15:00',
            'time_out' => '19:00',
        ])->assertRedirect();

        $shift = Shift::where('name', 'Mid Shift')->firstOrFail();
        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_template_created',
            'target_id' => $shift->id,
        ]);

        $this->actingAs($tk)->put(route('attendance.shifts.update', $shift), [
            'name' => 'Mid Shift',
            'time_in' => '09:00',
            'break_out' => '13:00',
            'break_in' => '14:00',
            'time_out' => '18:00',
        ])->assertRedirect();

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_template_updated',
            'target_id' => $shift->id,
        ]);

        $this->actingAs($tk)->delete(route('attendance.shifts.destroy', $shift))
            ->assertRedirect();

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'shift_template_deleted',
        ]);

        $this->actingAs($tk)
            ->get(route('attendance.shift-logs'))
            ->assertSee('Template Created')
            ->assertSee('Template Updated')
            ->assertSee('Template Deleted');
    }

    /**
     * Work Days and No Break moved from the Shift template to the
     * shift_assignments row - this test now exercises assignment-level
     * persistence and audit logging instead of template-level.
     */
    public function test_shift_assignment_work_days_and_no_break_persist_and_are_audited(): void
    {
        $tk = $this->createTimeKeeper();
        $employee = $this->createEmployee(['last_name' => 'WorkDaysAudited']);
        $shift = Shift::create([
            'name' => 'Mon-Sat Shift',
            'time_in' => '08:00',
            'break_out' => '12:00',
            'break_in' => '13:00',
            'time_out' => '17:00',
        ]);

        $this->actingAs($tk)->put(route('attendance.schedules.update', $employee), [
            'shift_id' => $shift->id,
            'work_days' => [1, 2, 3, 4, 5, 6],
            'no_break' => '1',
        ])->assertRedirect();

        $row = ShiftAssignment::where('user_id', $employee->id)->firstOrFail();
        $this->assertSame([1, 2, 3, 4, 5, 6], $row->work_days);
        $this->assertTrue($row->no_break);

        $created = HRAuditTrail::where('target_id', $employee->id)
            ->where('action', 'shift_assigned')->firstOrFail();
        $this->assertSame([1, 2, 3, 4, 5, 6], $created->details['work_days']);
        $this->assertTrue($created->details['no_break']);

        $this->actingAs($tk)
            ->get(route('attendance.shift-logs'))
            ->assertSee('Mon-Sat')
            ->assertSee('no break');
    }

    public function test_department_filter_scopes_the_change_log(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');
        $tk = $this->createTimeKeeper();
        $employeeA = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'last_name' => 'InDeptA']);
        $employeeB = $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'last_name' => 'InDeptB']);

        $this->actingAs($tk)->put(route('attendance.schedules.exempt', $employeeA))->assertRedirect();
        $this->actingAs($tk)->put(route('attendance.schedules.exempt', $employeeB))->assertRedirect();

        // Unfiltered: both departments' entries show.
        $this->actingAs($tk)
            ->get(route('attendance.shift-logs'))
            ->assertSee('InDeptA')
            ->assertSee('InDeptB');

        // Filtered to Dept A: only Dept A's entry shows.
        $response = $this->actingAs($tk)->get(route('attendance.shift-logs', ['dept_id' => $deptA->Dept_id]));
        $response->assertSee('InDeptA');
        $response->assertDontSee('InDeptB');
    }

    public function test_shift_template_change_still_shows_when_filtered_by_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $tk = $this->createTimeKeeper();

        $this->actingAs($tk)->post(route('attendance.shifts.store'), [
            'name' => 'Global Template',
            'time_in' => '06:00',
            'break_out' => '10:00',
            'break_in' => '11:00',
            'time_out' => '14:00',
        ])->assertRedirect();

        // Shift templates are global, so they stay visible even when scoped to one department.
        $this->actingAs($tk)
            ->get(route('attendance.shift-logs', ['dept_id' => $deptA->Dept_id]))
            ->assertSee('Template Created');
    }

    public function test_time_keeper_cannot_trigger_monitoring_matrix_export_without_department_id(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($this->createTimeKeeper())
            ->postJson(route('export-jobs.create'), [
                'type' => 'monitoring_matrix',
                'params' => ['month' => 6, 'year' => 2026],
            ])
            ->assertStatus(403);
    }

    public function test_time_keeper_can_trigger_monitoring_matrix_export_for_any_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $this->createEmployee(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($this->createTimeKeeper())
            ->postJson(route('export-jobs.create'), [
                'type' => 'monitoring_matrix',
                'params' => ['month' => 6, 'year' => 2026, 'department_id' => $deptA->Dept_id],
            ])
            ->assertStatus(200);
    }

    public function test_administrative_officer_export_stays_scoped_to_own_department(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $ao = $this->createAdminOfficer(['Dept_id' => $deptA->Dept_id]);

        $this->actingAs($ao)
            ->postJson(route('export-jobs.create'), [
                'type' => 'monitoring_matrix',
                'params' => ['month' => 6, 'year' => 2026],
            ])
            ->assertStatus(200);
    }
}
