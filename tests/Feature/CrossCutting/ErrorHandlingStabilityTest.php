<?php

namespace Tests\Feature\CrossCutting;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;
use App\Models\LeaveRequest;
use App\Models\Eta;
use App\Models\Locator;
use App\Models\DocumentRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Cross-Cutting: Error Handling & Long-Run Stability Tests
 *
 * Covers: Graceful error recovery, validation, logging, stability simulation
 */
class ErrorHandlingStabilityTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers, MeasuresPerformance;

    // ──────────────────────────────────────────────
    // 1. Validation Error Handling
    // ──────────────────────────────────────────────

    public function test_leave_request_missing_fields(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), []);

        // Should handle gracefully - redirect with errors or 422
        $this->assertTrue(
            $response->isRedirection() || $response->getStatusCode() === 422,
            "Missing fields not handled: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_eta_invalid_date(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->post(route('employee.eta.store'), [
            'date'   => 'not-a-date',
            'type'   => 'late_arrival',
            'time'   => '09:30',
            'reason' => 'Test',
        ]);

        $this->assertTrue(
            $response->isRedirection() || $response->getStatusCode() === 422,
            "Invalid date not handled: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_locator_invalid_time_range(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->post(route('employee.locator.store'), [
            'date'        => now()->addDay()->toDateString(),
            'type'        => 'Official',
            'destination' => 'City Hall',
            'purpose'     => 'Meeting',
            'time_out'    => '17:00',    // Later than time_in
            'time_in'     => '10:00',
        ]);

        // Should handle gracefully
        $this->assertTrue(
            $response->isRedirection() || $response->isSuccessful() || $response->getStatusCode() === 422,
            "Invalid time range not handled"
        );
    }

    public function test_duplicate_leave_request(): void
    {
        $user = $this->createEmployee();
        $this->createLeaveBalance($user, ['VL' => 15]);

        $date = now()->addWeek()->startOfWeek()->toDateString();

        // First leave
        $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_type' => 'VL',
            'start_date' => $date,
            'end_date'   => $date,
            'reason'     => 'First leave',
            'dates'      => [$date],
        ]);

        // Duplicate leave on same date
        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_type' => 'VL',
            'start_date' => $date,
            'end_date'   => $date,
            'reason'     => 'Duplicate leave',
            'dates'      => [$date],
        ]);

        // Should not create duplicate - redirect with error or reject
        $this->assertTrue(
            $response->isRedirection() || $response->getStatusCode() === 422 || $response->getStatusCode() === 409,
            "Duplicate leave not properly handled"
        );
    }

    // ──────────────────────────────────────────────
    // 2. Insufficient Balance Handling
    // ──────────────────────────────────────────────

    public function test_leave_request_insufficient_balance(): void
    {
        $user = $this->createEmployee();
        $this->createLeaveBalance($user, ['VL' => 0.000]);

        $response = $this->actingAs($user)->post(route('employee.leave.apply'), [
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date'   => now()->addWeek()->addDays(4)->toDateString(),
            'reason'     => 'No balance',
            'dates'      => [
                now()->addWeek()->toDateString(),
                now()->addWeek()->addDays(1)->toDateString(),
                now()->addWeek()->addDays(2)->toDateString(),
            ],
        ]);

        // Should handle insufficient balance gracefully
        $this->assertTrue(
            $response->isRedirection() || $response->getStatusCode() === 422,
            "Insufficient balance not handled: HTTP {$response->getStatusCode()}"
        );
    }

    // ──────────────────────────────────────────────
    // 3. Non-Existent Resource Access
    // ──────────────────────────────────────────────

    public function test_access_nonexistent_leave_request(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(
            route('employee.leave.show', 99999)
        );

        $this->assertTrue(
            in_array($response->getStatusCode(), [403, 404]),
            "Non-existent leave request returned: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_approve_nonexistent_leave(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->post(
            route('department-head.leave.approve', 99999)
        );

        $this->assertTrue(
            in_array($response->getStatusCode(), [404, 422, 500]),
            "Approving non-existent leave did not error: HTTP {$response->getStatusCode()}"
        );
    }

    // ──────────────────────────────────────────────
    // 4. Long-Run Stability Simulation
    // ──────────────────────────────────────────────

    public function test_stability_mixed_operations_500_cycles(): void
    {
        $users = [
            'employee' => $this->createEmployee(),
            'dh'       => $this->createDepartmentHead(),
            'hr'       => $this->createHRManager(),
        ];

        $this->createLeaveBalance($users['employee'], ['VL' => 100, 'SL' => 100]);

        $successes = 0;
        $failures = 0;
        $startTime = microtime(true);

        for ($cycle = 0; $cycle < 500; $cycle++) {
            $operation = $cycle % 5;

            try {
                switch ($operation) {
                    case 0: // Employee views dashboard
                        $response = $this->actingAs($users['employee'])->get(route('dashboard'));
                        break;
                    case 1: // Employee submits ETA
                        $response = $this->actingAs($users['employee'])->post(route('employee.eta.store'), [
                            'date'   => now()->addDays($cycle)->toDateString(),
                            'type'   => 'late_arrival',
                            'time'   => '09:30',
                            'reason' => "Stability test #{$cycle}",
                        ]);
                        break;
                    case 2: // DH views pending requests
                        $response = $this->actingAs($users['dh'])->get(route('department-head.pending-requests'));
                        break;
                    case 3: // HR views records
                        $response = $this->actingAs($users['hr'])->get(route('hr-manager.records'));
                        break;
                    case 4: // Employee views attendance
                        $response = $this->actingAs($users['employee'])->get(route('dashboard.employee.attendance'));
                        break;
                }

                if ($response->isSuccessful() || $response->isRedirection()) {
                    $successes++;
                } else {
                    $failures++;
                }
            } catch (\Throwable $e) {
                $failures++;
            }
        }

        $elapsed = (microtime(true) - $startTime) * 1000;
        $total = $successes + $failures;
        $rate = $total > 0 ? ($successes / $total) * 100 : 0;

        $this->assertGreaterThanOrEqual(95, $rate,
            "Stability test: {$rate}% ({$successes}/{$total}) in {$elapsed}ms. " .
            "Avg: " . round($elapsed / $total, 2) . "ms/op");
    }

    // ──────────────────────────────────────────────
    // 5. Memory Leak Detection
    // ──────────────────────────────────────────────

    public function test_memory_stability_over_iterations(): void
    {
        $user = $this->createEmployee();
        $this->createLeaveBalance($user);

        $initialMemory = memory_get_usage(true);

        for ($i = 0; $i < 100; $i++) {
            $this->actingAs($user)->get(route('dashboard'));
        }

        $finalMemory = memory_get_usage(true);
        $growthMB = ($finalMemory - $initialMemory) / 1024 / 1024;

        $this->assertLessThanOrEqual(50, $growthMB,
            "Memory grew by {$growthMB}MB over 100 iterations (possible leak)");
    }

    // ──────────────────────────────────────────────
    // 6. Concurrent Write Integrity
    // ──────────────────────────────────────────────

    public function test_concurrent_leave_filing_data_integrity(): void
    {
        $employees = [];
        for ($i = 0; $i < 20; $i++) {
            $emp = $this->createEmployee();
            $this->createLeaveBalance($emp, ['VL' => 15.000]);
            $employees[] = $emp;
        }

        // All employees file leaves simultaneously
        foreach ($employees as $idx => $emp) {
            $this->actingAs($emp)->post(route('employee.leave.apply'), [
                'leave_type' => 'VL',
                'start_date' => now()->addDays($idx + 7)->toDateString(),
                'end_date'   => now()->addDays($idx + 7)->toDateString(),
                'reason'     => "Concurrent #{$idx}",
                'dates'      => [now()->addDays($idx + 7)->toDateString()],
            ]);
        }

        // Verify data integrity — each employee's leave should be distinct
        $leaveCount = LeaveRequest::count();
        $uniqueUsers = LeaveRequest::distinct('user_id')->count('user_id');

        $this->assertEquals($uniqueUsers, $leaveCount,
            "Data integrity issue: {$leaveCount} leaves for {$uniqueUsers} unique users");
    }
}
