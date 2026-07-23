<?php

namespace Tests\Feature\Records;

use App\Models\EmployeeAssignment;
use App\Models\JobOrderAppointment;
use App\Models\Plantilla;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class DeactivateExpiredJobOrdersCommandTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function createAppointment(User $employee, array $overrides = []): JobOrderAppointment
    {
        return JobOrderAppointment::create(array_merge([
            'user_id' => $employee->id,
            'office' => 'BAC',
            'rate_per_day' => 400,
            'period_from' => now()->subMonths(3)->toDateString(),
            'period_until' => now()->subDay()->toDateString(),
        ], $overrides));
    }

    public function test_deactivates_job_order_employee_whose_appointment_history_has_fully_lapsed(): void
    {
        $employee = $this->createEmployee(['employee_type' => 'Job Orders', 'Status' => 'Active']);
        $this->createAppointment($employee);

        $this->artisan('job-order:deactivate-expired')->assertExitCode(0);

        $employee->refresh();
        $this->assertSame('Inactive', $employee->Status);
        $this->assertNotNull($employee->job_order_auto_deactivated_at);
        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'records',
            'action' => 'status_changed',
            'target_id' => $employee->id,
        ]);
    }

    public function test_leaves_employee_with_a_currently_covering_appointment_untouched(): void
    {
        $employee = $this->createEmployee(['employee_type' => 'Job Orders', 'Status' => 'Active']);
        $this->createAppointment($employee, [
            'period_from' => now()->subMonth()->toDateString(),
            'period_until' => now()->addMonth()->toDateString(),
        ]);

        $this->artisan('job-order:deactivate-expired');

        $employee->refresh();
        $this->assertSame('Active', $employee->Status);
        $this->assertNull($employee->job_order_auto_deactivated_at);
    }

    public function test_leaves_employee_with_only_a_future_dated_appointment_untouched(): void
    {
        $employee = $this->createEmployee(['employee_type' => 'Job Orders', 'Status' => 'Active']);
        $this->createAppointment($employee, [
            'period_from' => now()->addMonth()->toDateString(),
            'period_until' => now()->addMonths(3)->toDateString(),
        ]);

        $this->artisan('job-order:deactivate-expired');

        $employee->refresh();
        $this->assertSame('Active', $employee->Status);
    }

    public function test_ignores_non_job_order_employees(): void
    {
        $employee = $this->createEmployee(['employee_type' => 'Permanent', 'Status' => 'Active']);
        $this->createAppointment($employee);

        $this->artisan('job-order:deactivate-expired');

        $employee->refresh();
        $this->assertSame('Active', $employee->Status);
    }

    public function test_ignores_job_order_employee_with_no_appointment_history(): void
    {
        $employee = $this->createEmployee(['employee_type' => 'Job Orders', 'Status' => 'Active']);

        $this->artisan('job-order:deactivate-expired');

        $employee->refresh();
        $this->assertSame('Active', $employee->Status);
    }

    public function test_skips_employee_already_inactive_and_does_not_duplicate_audit_rows(): void
    {
        $employee = $this->createEmployee(['employee_type' => 'Job Orders', 'Status' => 'Inactive']);
        $this->createAppointment($employee);

        $this->artisan('job-order:deactivate-expired');

        $this->assertDatabaseMissing('hr_audit_trails', [
            'module' => 'records',
            'action' => 'status_changed',
            'target_id' => $employee->id,
        ]);
    }

    public function test_ends_active_plantilla_assignment_on_deactivation(): void
    {
        $employee = $this->createEmployee(['employee_type' => 'Job Orders', 'Status' => 'Active']);
        $this->createAppointment($employee);

        $plantilla = Plantilla::create([
            'title' => 'Utility Worker',
            'salary_grade' => 1,
            'step' => 1,
            'employment_type' => 'job order',
        ]);
        $assignment = EmployeeAssignment::create([
            'employee_id' => $employee->id,
            'plantilla_id' => $plantilla->id,
            'start_date' => now()->subYear()->toDateString(),
        ]);

        $this->artisan('job-order:deactivate-expired');

        $this->assertNotNull($assignment->fresh()->end_date);
    }
}
