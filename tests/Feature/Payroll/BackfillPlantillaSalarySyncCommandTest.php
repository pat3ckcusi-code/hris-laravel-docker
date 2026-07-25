<?php

namespace Tests\Feature\Payroll;

use App\Models\EmployeeAssignment;
use App\Models\Plantilla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

/**
 * plantilla:backfill-salary-sync
 *
 * Covers: reconciling users.salary_grade/salary_step for employees whose
 * current (date-range, not just open-ended) assignment was invisible to the
 * old whereNull('end_date')-only definition.
 */
class BackfillPlantillaSalarySyncCommandTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function makePlantilla(array $overrides = []): Plantilla
    {
        return Plantilla::create(array_merge([
            'title' => 'Administrative Aide II',
            'item_number' => '901',
            'salary_grade' => 2,
            'step' => 5,
            'employment_type' => 'permanent',
        ], $overrides));
    }

    public function test_corrects_a_users_salary_columns_when_their_only_current_assignment_is_fixed_term(): void
    {
        $employee = $this->createEmployee(['salary_grade' => null, 'salary_step' => 1]);
        $plantilla = $this->makePlantilla();

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'step' => 5,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);

        $this->artisan('plantilla:backfill-salary-sync')->assertExitCode(0);

        $employee->refresh();
        $this->assertSame(2, $employee->salary_grade);
        $this->assertSame(5, $employee->salary_step);
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $employee = $this->createEmployee(['salary_grade' => null, 'salary_step' => 1]);
        $plantilla = $this->makePlantilla();

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'step' => 5,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);

        $this->artisan('plantilla:backfill-salary-sync', ['--dry-run' => true])->assertExitCode(0);

        $employee->refresh();
        $this->assertNull($employee->salary_grade);
        $this->assertSame(1, $employee->salary_step);
    }

    public function test_leaves_already_correct_rows_untouched(): void
    {
        $employee = $this->createEmployee(['salary_grade' => 2, 'salary_step' => 5]);
        $plantilla = $this->makePlantilla();

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'step' => 5,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
        ]);

        $this->artisan('plantilla:backfill-salary-sync')
            ->expectsOutputToContain('Already correct: 1')
            ->assertExitCode(0);

        $employee->refresh();
        $this->assertSame(2, $employee->salary_grade);
        $this->assertSame(5, $employee->salary_step);
    }

    public function test_clears_stale_salary_for_a_user_whose_only_assignment_has_already_ended(): void
    {
        // Stale values left over from before the assignment ended (e.g. an
        // employee whose separation predates endActiveAssignmentForStatusChange()
        // ever running, or whose assignment just lapsed on its own end_date).
        $employee = $this->createEmployee(['salary_grade' => 9, 'salary_step' => 3]);
        $plantilla = $this->makePlantilla();

        EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'step' => 5,
            'start_date' => now()->subYears(2)->toDateString(),
            'end_date' => now()->subYear()->toDateString(),
        ]);

        $this->artisan('plantilla:backfill-salary-sync')->assertExitCode(0);

        $employee->refresh();
        $this->assertNull($employee->salary_grade);
        $this->assertSame(1, $employee->salary_step);
    }

    public function test_leaves_a_user_with_no_assignment_history_at_all_untouched(): void
    {
        $employee = $this->createEmployee(['salary_grade' => null, 'salary_step' => 1]);

        $this->artisan('plantilla:backfill-salary-sync')->assertExitCode(0);

        $employee->refresh();
        $this->assertNull($employee->salary_grade);
        $this->assertSame(1, $employee->salary_step);
    }
}
