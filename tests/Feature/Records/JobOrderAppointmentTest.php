<?php

namespace Tests\Feature\Records;

use App\Models\JobOrderAppointment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;

class JobOrderAppointmentTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'designation' => 'Field Worker/Office Staff',
            'office' => 'BAC',
            'funding_source' => 'CMO - Other General Services',
            'rate_per_day' => 400,
            'rate_note' => 'w/SH',
            'period_from' => '2026-07-01',
            'period_until' => '2026-09-30',
            'remarks' => 'RENEWAL',
        ], $overrides);
    }

    public function test_records_manager_can_create_appointment_for_job_order_employee(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders']);

        $response = $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload()
        );

        $response->assertStatus(200);
        $response->assertJsonPath('appointment.rate_label', '400.00 w/SH');
        $this->assertDatabaseHas('job_order_appointments', [
            'user_id' => $employee->id,
            'office' => 'BAC',
        ]);
    }

    public function test_cannot_create_appointment_for_non_job_order_employee(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Permanent']);

        $response = $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload()
        );

        $response->assertStatus(422);
        $this->assertDatabaseCount('job_order_appointments', 0);
    }

    public function test_creating_appointment_with_same_period_from_replaces_existing_entry(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders']);

        $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload(['remarks' => 'NEW'])
        )->assertStatus(200);

        $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload(['remarks' => 'CORRECTED', 'rate_per_day' => 450])
        )->assertStatus(200);

        $this->assertDatabaseCount('job_order_appointments', 1);
        $this->assertDatabaseHas('job_order_appointments', [
            'user_id' => $employee->id,
            'remarks' => 'CORRECTED',
        ]);
    }

    public function test_creating_overlapping_period_is_rejected(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders']);

        $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload(['period_from' => '2026-07-01', 'period_until' => '2026-09-30'])
        )->assertStatus(200);

        $response = $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload(['period_from' => '2026-08-01', 'period_until' => '2026-10-31'])
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('period_from');
        $this->assertDatabaseCount('job_order_appointments', 1);
    }

    public function test_sequential_non_overlapping_renewal_succeeds_and_both_rows_remain_in_history(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders']);

        $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload(['period_from' => '2026-04-01', 'period_until' => '2026-06-30', 'remarks' => 'NEW'])
        )->assertStatus(200);

        $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload(['period_from' => '2026-07-01', 'period_until' => '2026-09-30', 'remarks' => 'RENEWAL'])
        )->assertStatus(200);

        $this->assertDatabaseCount('job_order_appointments', 2);

        $response = $this->actingAs($rm)->getJson(
            route('dashboard.records-manager.job-order-appointments.index', $employee)
        );
        $response->assertStatus(200);
        $response->assertJsonCount(2, 'appointments');
    }

    public function test_update_edits_a_mistaken_entry(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders']);

        $create = $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload()
        );
        $appointmentId = $create->json('appointment.id');
        $appointment = JobOrderAppointment::findOrFail($appointmentId);

        $response = $this->actingAs($rm)->putJson(
            route('dashboard.records-manager.job-order-appointments.update', [$employee, $appointment]),
            $this->payload(['rate_per_day' => 500, 'remarks' => 'CORRECTED'])
        );

        $response->assertStatus(200);
        $this->assertDatabaseHas('job_order_appointments', [
            'id' => $appointmentId,
            'remarks' => 'CORRECTED',
        ]);
    }

    public function test_destroy_removes_appointment_and_logs_audit(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders']);

        $create = $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload()
        );
        $appointmentId = $create->json('appointment.id');
        $appointment = JobOrderAppointment::findOrFail($appointmentId);

        $response = $this->actingAs($rm)->deleteJson(
            route('dashboard.records-manager.job-order-appointments.destroy', [$employee, $appointment])
        );

        $response->assertStatus(200);
        $this->assertDatabaseMissing('job_order_appointments', ['id' => $appointmentId]);
        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'records',
            'action' => 'job_order_appointment_deleted',
        ]);
    }

    public function test_validation_requires_period_and_rate_fields(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders']);

        $response = $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            []
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['rate_per_day', 'period_from', 'period_until']);
    }

    public function test_audit_trail_logged_with_module_records_on_create_update(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders']);

        $create = $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload()
        );
        $appointmentId = $create->json('appointment.id');
        $appointment = JobOrderAppointment::findOrFail($appointmentId);

        $this->actingAs($rm)->putJson(
            route('dashboard.records-manager.job-order-appointments.update', [$employee, $appointment]),
            $this->payload(['remarks' => 'CORRECTED'])
        );

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'records',
            'action' => 'job_order_appointment_created',
        ]);
        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'records',
            'action' => 'job_order_appointment_updated',
        ]);
    }

    public function test_creating_a_renewal_reactivates_an_employee_auto_deactivated_by_expiry_command(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders', 'Status' => 'Inactive']);
        $employee->forceFill(['job_order_auto_deactivated_at' => now()->subDay()])->save();

        $response = $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload()
        );

        $response->assertStatus(200);
        $employee->refresh();
        $this->assertSame('Active', $employee->Status);
        $this->assertNull($employee->job_order_auto_deactivated_at);
        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'records',
            'action' => 'status_changed',
            'target_id' => $employee->id,
        ]);
    }

    public function test_creating_appointment_does_not_reactivate_a_manually_deactivated_employee(): void
    {
        $rm = $this->createRecordsManager();
        // Inactive for an unrelated, manually-chosen reason - job_order_auto_deactivated_at
        // was never set, so this must not be mistaken for an expiry auto-deactivation.
        $employee = $this->createEmployee(['employee_type' => 'Job Orders', 'Status' => 'Inactive']);

        $response = $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload()
        );

        $response->assertStatus(200);
        $employee->refresh();
        $this->assertSame('Inactive', $employee->Status);
    }

    public function test_manual_status_change_clears_auto_deactivated_marker_and_blocks_reactivation(): void
    {
        $rm = $this->createRecordsManager();
        $employee = $this->createEmployee(['employee_type' => 'Job Orders', 'Status' => 'Inactive']);
        $employee->forceFill(['job_order_auto_deactivated_at' => now()->subDay()])->save();

        $updateResponse = $this->actingAs($rm)->putJson(
            route('dashboard.records-manager.users.update', $employee),
            [
                'last_name' => $employee->last_name,
                'first_name' => $employee->first_name,
                'email' => $employee->email,
                'Status' => 'Separated',
                'employee_type' => 'Job Orders',
                'access_level' => 'employee',
                'date_hired' => now()->subYear()->toDateString(),
            ]
        );
        $updateResponse->assertStatus(200);

        $employee->refresh();
        $this->assertSame('Separated', $employee->Status);
        $this->assertNull($employee->job_order_auto_deactivated_at);

        // A renewal must not resurrect an employee the Records Manager just
        // deliberately marked Separated for an unrelated reason.
        $this->actingAs($rm)->postJson(
            route('dashboard.records-manager.job-order-appointments.store', $employee),
            $this->payload()
        )->assertStatus(200);

        $this->assertSame('Separated', $employee->refresh()->Status);
    }

    public function test_non_records_manager_roles_are_blocked(): void
    {
        $employee = $this->createEmployee(['employee_type' => 'Job Orders']);
        $blockedActors = [
            $this->createEmployee(),
            $this->createHRManager(),
            $this->createTimeKeeper(),
        ];

        foreach ($blockedActors as $actor) {
            $response = $this->actingAs($actor)->postJson(
                route('dashboard.records-manager.job-order-appointments.store', $employee),
                $this->payload()
            );

            $this->assertTrue(
                $response->getStatusCode() === 403,
                'Expected 403 for actor with role '.$actor->access_level.', got '.$response->getStatusCode()
            );
        }
    }
}
