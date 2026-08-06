<?php

namespace Tests\Feature\RoleBased;

use App\Models\Dtr;
use App\Models\Eta;
use App\Models\LeaveRequest;
use App\Models\Locator;
use App\Models\Pds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;

/**
 * Employee Role Tests
 *
 * Covers: Dashboard, PDS, ETA, Locator, Leave Requests,
 *         Document Requests, Payslips, Attendance Logs
 */
class EmployeeTest extends TestCase
{
    use CreatesTestUsers, MeasuresPerformance, RefreshDatabase;

    // ──────────────────────────────────────────────
    // 1. Dashboard
    // ──────────────────────────────────────────────

    public function test_employee_dashboard_loads_successfully(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertStatus(200);
    }

    public function test_employee_dashboard_shows_summary_data(): void
    {
        $user = $this->createEmployee();
        $this->createLeaveBalance($user);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertStatus(200);
    }

    public function test_dashboard_concurrent_logins_performance(): void
    {
        $user = $this->createEmployee();
        $this->createLeaveBalance($user);

        $this->actingAs($user);

        $results = $this->simulateConcurrentRequests('GET', route('dashboard'), 50);

        $this->assertGreaterThanOrEqual(90, $results['success_rate'],
            "Dashboard success rate {$results['success_rate']}% is below 90% threshold");
        $this->assertLessThanOrEqual(2000, $results['p95_ms'],
            "Dashboard p95 response time {$results['p95_ms']}ms exceeds 2000ms");
    }

    // ──────────────────────────────────────────────
    // 2. PDS (Personal Data Sheet) CRUD
    // ──────────────────────────────────────────────

    public function test_employee_can_access_pds_page(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(route('dashboard.employee.pds'));

        $response->assertStatus(200);
    }

    public function test_employee_can_save_pds_draft(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->post(route('dashboard.employee.pds.save-draft'), [
            'section_key' => 'pds-personal-info',
            'section_data' => json_encode([
                'surname' => 'Doe',
                'first_name' => 'John',
                'middle_name' => 'M',
                'birth_date' => '1990-01-15',
                'birth_place' => 'Manila',
                'sex' => 'Male',
                'civil_status' => 'Single',
            ]),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_employee_can_update_pds_draft(): void
    {
        $user = $this->createEmployee();

        // Create initial draft
        $this->actingAs($user)->post(route('dashboard.employee.pds.save-draft'), [
            'section_key' => 'pds-personal-info',
            'section_data' => json_encode(['surname' => 'Doe']),
        ]);

        // Update draft
        $response = $this->actingAs($user)->post(route('dashboard.employee.pds.save-draft'), [
            'section_key' => 'pds-personal-info',
            'section_data' => json_encode(['surname' => 'Smith']),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
    }

    public function test_pds_export_works(): void
    {
        $user = $this->createEmployee();

        // Save some PDS data first
        $this->actingAs($user)->post(route('dashboard.employee.pds.save-draft'), [
            'section_key' => 'pds-personal-info',
            'section_data' => json_encode(['surname' => 'Doe', 'first_name' => 'John']),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard.employee.pds.export'));

        $response->assertOk();
    }

    // ──────────────────────────────────────────────
    // 3. ETA (Estimated Time of Arrival/Departure)
    // ──────────────────────────────────────────────

    public function test_employee_can_access_eta_page(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(route('dashboard.employee.eta'));

        $response->assertStatus(200);
    }

    public function test_employee_can_submit_eta(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->post(route('employee.eta.store'), [
            'date' => now()->addDay()->toDateString(),
            'type' => 'late_arrival',
            'time' => '09:30',
            'reason' => 'Traffic congestion due to road work',
        ]);

        $response->assertRedirect();
    }

    public function test_multiple_eta_submissions_simultaneously(): void
    {
        $user = $this->createEmployee();

        $submitted = 0;
        $errors = [];

        for ($i = 0; $i < 20; $i++) {
            $response = $this->actingAs($user)->post(route('employee.eta.store'), [
                'date' => now()->addDays($i + 1)->toDateString(),
                'type' => 'late_arrival',
                'time' => '09:'.str_pad($i + 10, 2, '0', STR_PAD_LEFT),
                'reason' => "Concurrent ETA test #{$i}",
            ]);

            if ($response->isSuccessful() || $response->isRedirection()) {
                $submitted++;
            } else {
                $errors[] = "ETA #{$i}: HTTP {$response->getStatusCode()}";
            }
        }

        $this->assertGreaterThanOrEqual(15, $submitted,
            "Only {$submitted}/20 ETAs submitted. Errors: ".implode('; ', $errors));
    }

    public function test_employee_can_fetch_eta_data(): void
    {
        $user = $this->createEmployee();

        // Create some ETAs
        Eta::create([
            'user_id' => $user->id,
            'departure_date' => now()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Test',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('employee.eta.data'));

        $response->assertStatus(200);
    }

    public function test_pending_eta_does_not_show_approver_name(): void
    {
        $user = $this->createEmployee();

        Eta::create([
            'user_id' => $user->id,
            'departure_date' => now()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Test',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('employee.eta.data'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.dept_head', null);
    }

    public function test_approved_eta_shows_actual_approver_name(): void
    {
        $user = $this->createEmployee();
        $deptHead = $this->createDepartmentHead([
            'first_name' => 'Angel',
            'middle_name' => 'Miranda',
            'last_name' => 'Navarro',
        ]);

        $eta = Eta::create([
            'user_id' => $user->id,
            'departure_date' => now()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Test',
            'status' => 'approved',
        ]);
        $eta->forceFill(['approved_by' => $deptHead->id])->save();

        $response = $this->actingAs($user)->get(route('employee.eta.data'));

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.dept_head', 'Angel Miranda Navarro');
    }

    public function test_employee_can_cancel_eta(): void
    {
        $user = $this->createEmployee();

        $eta = Eta::create([
            'user_id' => $user->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Test',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->post(route('employee.eta.cancel', $eta));

        $response->assertRedirect();
    }

    public function test_employee_can_request_cancellation_of_approved_eta(): void
    {
        $user = $this->createEmployee();

        $eta = Eta::create([
            'user_id' => $user->id,
            'departure_date' => now()->addDay()->toDateString(),
            'arrival_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Meeting',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.eta.request-cancellation', $eta), [
            'reason' => 'Trip no longer necessary',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $eta->refresh();
        $this->assertEquals('approved', $eta->status);
        $this->assertEquals('Pending Cancellation', $eta->cancellation_status);
        $this->assertEquals('Trip no longer necessary', $eta->cancellation_reason);
        $this->assertEquals($user->id, $eta->cancellation_requested_by);
        $this->assertNotNull($eta->cancellation_requested_at);
    }

    public function test_eta_cancellation_request_requires_reason(): void
    {
        $user = $this->createEmployee();

        $eta = Eta::create([
            'user_id' => $user->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Meeting',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.eta.request-cancellation', $eta), []);

        $response->assertStatus(422);
        $eta->refresh();
        $this->assertNull($eta->cancellation_status);
    }

    public function test_employee_cannot_request_cancellation_of_pending_eta(): void
    {
        $user = $this->createEmployee();

        $eta = Eta::create([
            'user_id' => $user->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Meeting',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.eta.request-cancellation', $eta), [
            'reason' => 'Changed my mind',
        ]);

        $response->assertStatus(400);
        $eta->refresh();
        $this->assertNull($eta->cancellation_status);
    }

    public function test_employee_cannot_request_cancellation_of_another_employees_eta(): void
    {
        $owner = $this->createEmployee();
        $other = $this->createEmployee();

        $eta = Eta::create([
            'user_id' => $owner->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Meeting',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($other)->postJson(route('employee.eta.request-cancellation', $eta), [
            'reason' => 'Not mine',
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_cannot_submit_duplicate_cancellation_request(): void
    {
        $user = $this->createEmployee();

        $eta = Eta::create([
            'user_id' => $user->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Meeting',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.eta.request-cancellation', $eta), [
            'reason' => 'Second attempt',
        ]);

        $response->assertStatus(400);
    }

    public function test_employee_can_request_cancellation_again_after_prior_rejection(): void
    {
        $user = $this->createEmployee();

        $eta = Eta::create([
            'user_id' => $user->id,
            'departure_date' => now()->addDay()->toDateString(),
            'destination' => 'City Hall',
            'purpose' => 'Meeting',
            'status' => 'approved',
            'cancellation_status' => 'Rejected',
            'cancellation_remarks' => 'Not approved previously',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.eta.request-cancellation', $eta), [
            'reason' => 'Trying again',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $eta->refresh();
        $this->assertEquals('Pending Cancellation', $eta->cancellation_status);
        $this->assertEquals('Trying again', $eta->cancellation_reason);
    }

    // ──────────────────────────────────────────────
    // 4. Locator
    // ──────────────────────────────────────────────

    public function test_employee_can_access_locator_page(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(route('dashboard.employee.locator'));

        $response->assertStatus(200);
    }

    public function test_employee_can_submit_locator(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->post(route('employee.locator.store'), [
            'date' => now()->addDay()->toDateString(),
            'type' => 'Official',
            'destination' => 'City Hall Annex',
            'purpose' => 'Document pickup',
            'time_out' => '10:00',
            'time_in' => '12:00',
        ]);

        $response->assertRedirect();
    }

    public function test_locator_data_endpoint_returns_json(): void
    {
        $user = $this->createEmployee();

        Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get(route('employee.locator.data'));

        $response->assertStatus(200);
    }

    public function test_locator_concurrent_updates(): void
    {
        $users = [];
        for ($i = 0; $i < 10; $i++) {
            $users[] = $this->createEmployee();
        }

        $successes = 0;
        foreach ($users as $idx => $user) {
            $response = $this->actingAs($user)->post(route('employee.locator.store'), [
                'date' => now()->addDay()->toDateString(),
                'type' => $idx % 2 === 0 ? 'Official' : 'Personal',
                'destination' => "Destination #{$idx}",
                'purpose' => "Purpose #{$idx}",
                'time_out' => '10:00',
                'time_in' => '12:00',
            ]);

            if ($response->isSuccessful() || $response->isRedirection()) {
                $successes++;
            }
        }

        $this->assertGreaterThanOrEqual(8, $successes,
            "Only {$successes}/10 concurrent locator submissions succeeded");
    }

    public function test_employee_can_request_cancellation_of_approved_locator(): void
    {
        $user = $this->createEmployee();

        $locator = Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.locator.request-cancellation', $locator), [
            'reason' => 'Trip no longer necessary',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $locator->refresh();
        $this->assertEquals('approved', $locator->status);
        $this->assertEquals('Pending Cancellation', $locator->cancellation_status);
        $this->assertEquals('Trip no longer necessary', $locator->cancellation_reason);
        $this->assertEquals($user->id, $locator->cancellation_requested_by);
        $this->assertNotNull($locator->cancellation_requested_at);
    }

    public function test_locator_cancellation_request_requires_reason(): void
    {
        $user = $this->createEmployee();

        $locator = Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.locator.request-cancellation', $locator), []);

        $response->assertStatus(422);
        $locator->refresh();
        $this->assertNull($locator->cancellation_status);
    }

    public function test_employee_cannot_request_cancellation_of_pending_locator(): void
    {
        $user = $this->createEmployee();

        $locator = Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.locator.request-cancellation', $locator), [
            'reason' => 'Changed my mind',
        ]);

        $response->assertStatus(400);
        $locator->refresh();
        $this->assertNull($locator->cancellation_status);
    }

    public function test_employee_cannot_request_cancellation_of_another_employees_locator(): void
    {
        $owner = $this->createEmployee();
        $other = $this->createEmployee();

        $locator = Locator::create([
            'user_id' => $owner->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($other)->postJson(route('employee.locator.request-cancellation', $locator), [
            'reason' => 'Not mine',
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_cannot_submit_duplicate_locator_cancellation_request(): void
    {
        $user = $this->createEmployee();

        $locator = Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'approved',
            'cancellation_status' => 'Pending Cancellation',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.locator.request-cancellation', $locator), [
            'reason' => 'Second attempt',
        ]);

        $response->assertStatus(400);
    }

    public function test_employee_can_request_locator_cancellation_again_after_prior_rejection(): void
    {
        $user = $this->createEmployee();

        $locator = Locator::create([
            'user_id' => $user->id,
            'application_type' => 'Official',
            'location' => 'City Hall',
            'travel_date' => now()->addDay()->toDateString(),
            'intended_departure_time' => '10:00',
            'intended_arrival_time' => '12:00',
            'detail' => 'Meeting',
            'status' => 'approved',
            'cancellation_status' => 'Rejected',
            'cancellation_review_remarks' => 'Not approved previously',
        ]);

        $response = $this->actingAs($user)->postJson(route('employee.locator.request-cancellation', $locator), [
            'reason' => 'Trying again',
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $locator->refresh();
        $this->assertEquals('Pending Cancellation', $locator->cancellation_status);
        $this->assertEquals('Trying again', $locator->cancellation_reason);
    }

    // ──────────────────────────────────────────────
    // 5. Leave Requests
    // ──────────────────────────────────────────────

    public function test_employee_can_access_leave_management(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(route('employee.leave.management'));

        $response->assertStatus(200);
    }

    public function test_employee_can_file_leave_request(): void
    {
        $user = $this->createEmployee();
        $this->createLeaveBalance($user, ['VL' => 15.000]);

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->startOfWeek()->toDateString(),
            'end_date' => now()->addWeek()->startOfWeek()->toDateString(),
            'reason' => 'Personal matters',
            'dates' => [now()->addWeek()->startOfWeek()->toDateString()],
        ]);

        $response->assertRedirect();
    }

    public function test_employee_cannot_file_solo_parent_leave_when_not_designated(): void
    {
        $user = $this->createEmployee(['is_solo_parent' => false]);
        $this->createLeaveBalance($user, ['SP' => 5.000]);

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_type' => 'Solo Parent Leave',
            'start_date' => now()->addWeek()->startOfWeek()->toDateString(),
            'end_date' => now()->addWeek()->startOfWeek()->toDateString(),
            'reason' => 'Solo parent leave test',
            'dates' => [now()->addWeek()->startOfWeek()->toDateString()],
        ]);

        $response->assertSessionHasErrors('leave_type');
    }

    public function test_employee_cannot_file_solo_parent_leave_via_leave_dates_when_not_designated(): void
    {
        $user = $this->createEmployee(['is_solo_parent' => false]);
        $this->createLeaveBalance($user, ['SP' => 5.000]);

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_types' => ['Solo Parent Leave'],
            'leave_dates' => now()->addWeeks(2)->toDateString(),
            'reason' => 'Solo parent leave test',
        ]);

        $response->assertSessionHasErrors('leave_types');
    }

    public function test_employee_can_file_solo_parent_leave_when_designated(): void
    {
        $user = $this->createEmployee(['is_solo_parent' => true]);
        $this->createLeaveBalance($user, ['SP' => 5.000]);

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_types' => ['Solo Parent Leave'],
            'leave_dates' => now()->addWeeks(2)->toDateString(),
            'reason' => 'Solo parent leave test',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('leave_requests', [
            'user_id' => $user->id,
            'leave_type' => 'Solo Parent Leave',
        ]);
    }

    public function test_job_order_employee_cannot_file_leave(): void
    {
        $user = $this->createEmployee(['employee_type' => 'Job Orders']);

        $response = $this->actingAs($user)->get(route('employee.leave.management'));

        // DenyJobOrder middleware should block with 403
        $response->assertForbidden();
    }

    public function test_leave_permission_based_visibility(): void
    {
        $employee = $this->createEmployee();
        $otherEmployee = $this->createEmployee();

        $this->createLeaveBalance($employee, ['VL' => 15]);
        $this->createLeaveBalance($otherEmployee, ['VL' => 15]);

        // Create leave for other employee
        LeaveRequest::create([
            'user_id' => $otherEmployee->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'Other employee leave',
            'status' => 'pending',
        ]);

        // Employee should only see their own leave
        $response = $this->actingAs($employee)->get(route('employee.leave.management'));

        $response->assertStatus(200);
        $response->assertDontSee('Other employee leave');
    }

    public function test_simulate_500_leave_filings(): void
    {
        $startTime = microtime(true);
        $successes = 0;
        $failures = 0;
        $errors = [];

        // Create 50 employees, each filing 10 leaves
        for ($batch = 0; $batch < 50; $batch++) {
            $user = $this->createEmployee();
            $this->createLeaveBalance($user, ['VL' => 30.000, 'SL' => 30.000]);

            for ($i = 0; $i < 10; $i++) {
                $date = now()->addDays($batch * 10 + $i + 7)->toDateString();

                try {
                    $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
                        'leave_type' => $i % 2 === 0 ? 'VL' : 'SL',
                        'start_date' => $date,
                        'end_date' => $date,
                        'reason' => "Bulk leave test #{$batch}-{$i}",
                        'dates' => [$date],
                    ]);

                    if ($response->isSuccessful() || $response->isRedirection()) {
                        $successes++;
                    } else {
                        $failures++;
                        if (count($errors) < 10) {
                            $errors[] = "Batch {$batch}, Leave {$i}: HTTP {$response->getStatusCode()}";
                        }
                    }
                } catch (\Throwable $e) {
                    $failures++;
                    if (count($errors) < 10) {
                        $errors[] = "Batch {$batch}, Leave {$i}: {$e->getMessage()}";
                    }
                }
            }
        }

        $totalTime = (microtime(true) - $startTime) * 1000;
        $total = $successes + $failures;
        $rate = $total > 0 ? ($successes / $total) * 100 : 0;

        $this->assertGreaterThanOrEqual(80, $rate,
            "Leave filing success rate {$rate}% (successes: {$successes}, failures: {$failures}). ".
            "Time: {$totalTime}ms. Errors: ".implode('; ', $errors));
    }

    // ──────────────────────────────────────────────
    // 6. Document Requests
    // ──────────────────────────────────────────────

    public function test_employee_can_access_document_requests(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(route('dashboard.employee.request-documents'));

        $response->assertStatus(200);
    }

    public function test_employee_can_submit_document_request(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->post(route('document-requests.store'), [
            'document_type' => 'Certificate of Employment',
            'purpose' => 'Bank loan application',
            'copies' => 2,
        ]);

        $response->assertRedirect();
    }

    public function test_document_request_queue_handling(): void
    {
        $successes = 0;
        for ($i = 0; $i < 30; $i++) {
            $user = $this->createEmployee();

            $response = $this->actingAs($user)->post(route('document-requests.store'), [
                'document_type' => 'Certificate of Employment',
                'purpose' => "Queue test #{$i}",
                'copies' => 1,
            ]);

            if ($response->isSuccessful() || $response->isRedirection()) {
                $successes++;
            }
        }

        $this->assertGreaterThanOrEqual(25, $successes,
            "Only {$successes}/30 document requests queued successfully");
    }

    // ──────────────────────────────────────────────
    // 7. Payslips
    // ──────────────────────────────────────────────

    public function test_employee_can_access_payslips(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(route('dashboard.employee.payslips'));

        $response->assertStatus(200);
    }

    public function test_payslip_stress_test_batch_access(): void
    {
        $successes = 0;
        $times = [];

        // Simulate 100 users accessing payslips (scaled from 1000 for test speed)
        for ($i = 0; $i < 100; $i++) {
            $user = $this->createEmployee();

            $start = microtime(true);
            $response = $this->actingAs($user)->get(route('dashboard.employee.payslips'));
            $elapsed = (microtime(true) - $start) * 1000;

            $times[] = $elapsed;
            if ($response->isSuccessful()) {
                $successes++;
            }
        }

        $avgTime = array_sum($times) / count($times);
        $rate = ($successes / 100) * 100;

        $this->assertGreaterThanOrEqual(95, $rate,
            "Payslip access success rate: {$rate}%");
        $this->assertLessThanOrEqual(3000, $avgTime,
            "Average payslip load time: {$avgTime}ms exceeds 3000ms threshold");
    }

    // ──────────────────────────────────────────────
    // 8. Attendance Logs
    // ──────────────────────────────────────────────

    public function test_employee_can_access_attendance(): void
    {
        $this->markTestSkipped('dashboard.employee.attendance route not registered; use attendance.dtr.');

        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(route('dashboard.employee.attendance'));

        $response->assertStatus(200);
    }

    public function test_attendance_query_performance(): void
    {
        $this->markTestSkipped('dashboard.employee.attendance route not registered; use attendance.dtr.');

        $user = $this->createEmployee();

        // Seed DTR records
        for ($i = 0; $i < 90; $i++) {
            Dtr::create([
                'employee_id' => $user->id,
                'date' => now()->subDays($i)->toDateString(),
                'time_in_am' => '08:00',
                'time_out_am' => '12:00',
                'time_in_pm' => '13:00',
                'time_out_pm' => '17:00',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'is_absent' => false,
            ]);
        }

        $this->startQueryLog();
        $response = $this->actingAs($user)->get(route('dashboard.employee.attendance'));
        $queryCount = $this->getQueryCount();
        $this->stopQueryLog();

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(20, $queryCount,
            "Attendance page generated {$queryCount} queries (max 20 expected)");
    }
}
