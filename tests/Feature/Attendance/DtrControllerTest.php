<?php

namespace Tests\Feature\Attendance;

use App\Models\Department;
use App\Models\OicAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * DtrController::resolveContext() access-tier resolution: plain employees get
 * their own personal DTR, admins (HR Manager/Time Keeper/Payroll Manager/
 * Records Manager) get every department, and Department Head/Administrative
 * Officer get the officer view scoped to their own department(s) - including
 * a department they only cover via an active OicAssignment, not just one they
 * really head.
 */
class DtrControllerTest extends TestCase
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

    public function test_plain_employee_sees_their_own_personal_dtr_view(): void
    {
        $this->actingAs($this->createEmployee())
            ->get(route('attendance.dtr'))
            ->assertStatus(200)
            ->assertSee('My Daily Time Records');
    }

    public function test_oic_covering_employee_sees_officer_view_scoped_to_covered_department_only(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');

        $coveringEmployee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $inDeptA = $this->createEmployee(['Dept_id' => $deptA->Dept_id, 'first_name' => 'Test', 'last_name' => 'InDeptA']);
        $inDeptB = $this->createEmployee(['Dept_id' => $deptB->Dept_id, 'first_name' => 'Test', 'last_name' => 'InDeptB']);

        OicAssignment::create([
            'user_id' => $coveringEmployee->id,
            'dept_id' => $deptA->Dept_id,
            'role' => 'department head',
            'appointed_by' => $this->createHRManager()->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $response = $this->actingAs($coveringEmployee)->get(route('attendance.dtr'));

        $response->assertStatus(200)
            ->assertDontSee('My Daily Time Records')
            ->assertSee('Daily Time Records')
            ->assertSee('InDeptA, Test')
            ->assertDontSee('InDeptB, Test');
    }

    public function test_oic_covering_employee_cannot_load_another_departments_dtr_data(): void
    {
        $deptA = $this->makeDepartment('Dept A');
        $deptB = $this->makeDepartment('Dept B');

        $coveringEmployee = $this->createEmployee(['Dept_id' => $deptA->Dept_id]);
        $inDeptB = $this->createEmployee(['Dept_id' => $deptB->Dept_id]);

        OicAssignment::create([
            'user_id' => $coveringEmployee->id,
            'dept_id' => $deptA->Dept_id,
            'role' => 'department head',
            'appointed_by' => $this->createHRManager()->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->actingAs($coveringEmployee)
            ->get(route('attendance.dtr.data', [
                'employee_id' => $inDeptB->id,
                'dtr_type' => 'monthly',
                'month' => '2026-06',
            ]))
            ->assertStatus(403);
    }
}
