<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\Plantilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * plantilla:sync-salary-cache - the daily reconciliation command that
 * advances users.salary_grade/salary_step once a previously future-dated
 * employee_assignments row's start date actually arrives (audit §2.10).
 */
class SyncPlantillaSalaryCacheTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makePlantilla(array $overrides = []): Plantilla
    {
        return Plantilla::create(array_merge([
            'title' => 'Administrative Aide II',
            'item_number' => '901',
            'department' => 'TEST OFFICE',
            'salary_grade' => 2,
            'step' => 5,
            'employment_type' => 'permanent',
        ], $overrides));
    }

    public function test_command_advances_salary_cache_once_a_future_assignment_start_date_arrives(): void
    {
        $employee = $this->createEmployee();
        $plantilla = $this->makePlantilla(['salary_grade' => 9]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'step' => 2,
            'start_date' => now()->toDateString(),
        ]);

        // Simulate a stale cache left over from before this assignment took
        // effect (e.g. from a prior future-dated promotion that correctly
        // left the old values in place).
        $employee->forceFill(['salary_grade' => 3, 'salary_step' => 1])->save();

        $this->artisan('plantilla:sync-salary-cache')->assertSuccessful();

        $employee->refresh();
        $this->assertSame(9, $employee->salary_grade);
        // The assignment's own step (2), never the plantilla's own budgeted
        // step (5) - the bug this command's shared sync method fixes.
        $this->assertSame(2, $employee->salary_step);
    }

    public function test_command_is_a_no_op_when_cache_already_matches(): void
    {
        $employee = $this->createEmployee();
        $plantilla = $this->makePlantilla(['salary_grade' => 4]);

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'step' => 1,
            'start_date' => now()->toDateString(),
        ]);

        $employee->forceFill(['salary_grade' => 4, 'salary_step' => 1])->save();

        $this->artisan('plantilla:sync-salary-cache')->assertSuccessful();

        $employee->refresh();
        $this->assertSame(4, $employee->salary_grade);
        $this->assertSame(1, $employee->salary_step);
    }
}
