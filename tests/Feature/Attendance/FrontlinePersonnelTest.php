<?php

namespace Tests\Feature\Attendance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * Standing frontline/essential-personnel designation screen: Time Keeper/HR
 * Manager mark departments and individual employees exempt from every
 * declared work suspension (see WorkSuspensionTest for the exemption's
 * effect on DTR computation).
 */
class FrontlinePersonnelTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_time_keeper_can_toggle_a_department_frontline(): void
    {
        $tk = $this->createTimeKeeper();
        $emp = $this->createEmployee();
        $dept = $emp->department;

        $this->actingAs($tk)
            ->put(route('attendance.frontline-personnel.departments.toggle', $dept))
            ->assertRedirect();

        $this->assertTrue($dept->fresh()->is_frontline);

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'frontline_department_toggled',
            'target_type' => 'department',
            'target_id' => $dept->Dept_id,
        ]);
    }

    public function test_toggling_a_department_twice_unmarks_it(): void
    {
        $tk = $this->createTimeKeeper();
        $emp = $this->createEmployee();
        $dept = $emp->department;

        $this->actingAs($tk)->put(route('attendance.frontline-personnel.departments.toggle', $dept));
        $this->assertTrue($dept->fresh()->is_frontline);

        $this->actingAs($tk)->put(route('attendance.frontline-personnel.departments.toggle', $dept));
        $this->assertFalse($dept->fresh()->is_frontline);
    }

    public function test_hr_manager_can_toggle_an_employee_frontline(): void
    {
        $hr = $this->createHRManager();
        $emp = $this->createEmployee();

        $this->actingAs($hr)
            ->put(route('attendance.frontline-personnel.employees.toggle', $emp))
            ->assertRedirect();

        $this->assertTrue($emp->fresh()->is_frontline);

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'shift_management',
            'action' => 'frontline_employee_toggled',
            'target_type' => 'user',
            'target_id' => $emp->id,
        ]);
    }

    public function test_department_head_cannot_manage_frontline_personnel(): void
    {
        $dh = $this->createDepartmentHead();
        $emp = $this->createEmployee();

        $this->actingAs($dh)
            ->get(route('attendance.frontline-personnel.index'))
            ->assertStatus(403);

        $this->actingAs($dh)
            ->put(route('attendance.frontline-personnel.departments.toggle', $emp->department))
            ->assertStatus(403);

        $this->actingAs($dh)
            ->put(route('attendance.frontline-personnel.employees.toggle', $emp))
            ->assertStatus(403);
    }

    public function test_time_keeper_can_view_the_index_page(): void
    {
        $tk = $this->createTimeKeeper();
        $emp = $this->createEmployee(['is_frontline' => true]);

        $this->actingAs($tk)
            ->get(route('attendance.frontline-personnel.index'))
            ->assertStatus(200)
            ->assertSee($emp->last_name);
    }
}
