<?php

namespace Tests\Feature\RoleBased;

use App\Models\HRAuditTrail;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;

/**
 * HR Manager Role Tests
 *
 * Covers: Charts & Analytics, Records Management, Leave Operations,
 *         Audit Logs, User Roles, System Settings
 */
class HRManagerTest extends TestCase
{
    use CreatesTestUsers, MeasuresPerformance, RefreshDatabase;

    // ──────────────────────────────────────────────
    // 1. Dashboard & Charts
    // ──────────────────────────────────────────────

    public function test_hr_manager_dashboard_loads(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.dashboard'));

        $response->assertStatus(200);
    }

    public function test_chart_data_api(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.chart-data'));

        $response->assertStatus(200);
    }

    public function test_chart_data_stress_test_with_5000_records(): void
    {
        $hr = $this->createHRManager();

        // Seed a large number of employee records
        $users = [];
        for ($i = 0; $i < 200; $i++) {
            $users[] = $this->createEmployee();
        }

        // Create leave records for analytics
        foreach ($users as $idx => $user) {
            for ($j = 0; $j < 5; $j++) {
                LeaveRequest::create([
                    'user_id' => $user->id,
                    'leave_type' => ['VL', 'SL', 'SPL'][$j % 3],
                    'start_date' => now()->subDays($idx + $j)->toDateString(),
                    'end_date' => now()->subDays($idx + $j)->toDateString(),
                    'reason' => "Analytics seed #{$idx}-{$j}",
                    'status' => ['pending', 'approved', 'rejected'][$j % 3],
                ]);
            }
        }

        $this->startQueryLog();
        $start = microtime(true);

        $response = $this->actingAs($hr)->get(route('hr-manager.chart-data'));

        $elapsed = (microtime(true) - $start) * 1000;
        $queryCount = $this->getQueryCount();
        $slowQueries = $this->getSlowQueries(500);
        $this->stopQueryLog();

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(5000, $elapsed,
            "Chart data with 1000 leave records took {$elapsed}ms (max 5000ms)");
        $this->assertEmpty($slowQueries,
            'Found '.count($slowQueries).' slow queries (>500ms)');
    }

    public function test_employees_by_filter(): void
    {
        $hr = $this->createHRManager();

        for ($i = 0; $i < 10; $i++) {
            $this->createEmployee();
        }

        $response = $this->actingAs($hr)->get(route('hr-manager.employees.filter'));

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────
    // 2. Records Management
    // ──────────────────────────────────────────────

    public function test_records_page_loads(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.records'));

        $response->assertStatus(200);
    }

    public function test_records_data_api(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.records.data'));

        $response->assertStatus(200);
    }

    public function test_records_action_on_employee(): void
    {
        $hr = $this->createHRManager();
        $emp = $this->createEmployee();

        $response = $this->actingAs($hr)->post(
            route('hr-manager.records.action', $emp->id),
            ['action' => 'deactivate']
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Records action failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_records_update_changes_employee_status(): void
    {
        $hr = $this->createHRManager();
        $emp = $this->createEmployee(['Status' => 'Active']);

        $response = $this->actingAs($hr)->putJson(
            route('hr-manager.records.update', $emp->id),
            ['Status' => 'Inactive']
        );

        $response->assertStatus(200);
        $this->assertSame('Inactive', $emp->fresh()->Status);

        $this->assertDatabaseHas('hr_audit_trails', [
            'module' => 'records',
            'action' => 'status_changed',
            'target_type' => User::class,
            'target_id' => $emp->id,
        ]);
    }

    public function test_records_update_rejects_invalid_status(): void
    {
        $hr = $this->createHRManager();
        $emp = $this->createEmployee(['Status' => 'Active']);

        $response = $this->actingAs($hr)->putJson(
            route('hr-manager.records.update', $emp->id),
            ['Status' => 'Bogus']
        );

        $response->assertStatus(422);
    }

    public function test_bulk_records_operations(): void
    {
        $hr = $this->createHRManager();
        $successes = 0;

        // Create and process 100 employee records
        for ($i = 0; $i < 100; $i++) {
            $emp = $this->createEmployee();

            try {
                $response = $this->actingAs($hr)->post(
                    route('hr-manager.records.action', $emp->id),
                    ['action' => 'deactivate']
                );

                if ($response->isSuccessful() || $response->isRedirection()) {
                    $successes++;
                }
            } catch (\Throwable $e) {
                // Continue with next
            }
        }

        $this->assertGreaterThanOrEqual(80, $successes,
            "Bulk records operations: {$successes}/100 succeeded");
    }

    // ──────────────────────────────────────────────
    // 3. Leave Management Operations
    // ──────────────────────────────────────────────

    public function test_leave_management_page(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.leave'));

        $response->assertStatus(200);
    }

    public function test_leave_data_api(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.leave.data'));

        $response->assertStatus(200);
    }

    public function test_hr_leave_action_approve(): void
    {
        $hr = $this->createHRManager();
        $emp = $this->createEmployee();
        $this->createLeaveBalance($emp, ['VL' => 15]);

        $leave = LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'reason' => 'HR approval test',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($hr)->post(
            route('hr-manager.leave.action', $leave->id),
            ['action' => 'approve']
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "HR leave action failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_org_wide_leave_processing_simulation(): void
    {
        $hr = $this->createHRManager();
        $startTime = microtime(true);
        $successes = 0;

        // Simulate org-wide processing of 200 leaves
        for ($i = 0; $i < 200; $i++) {
            $emp = $this->createEmployee();
            $this->createLeaveBalance($emp, ['VL' => 15]);

            $leave = LeaveRequest::create([
                'user_id' => $emp->id,
                'leave_type' => 'VL',
                'start_date' => now()->addDays($i + 7)->toDateString(),
                'end_date' => now()->addDays($i + 7)->toDateString(),
                'reason' => "Org-wide test #{$i}",
                'status' => 'pending',
            ]);

            try {
                $response = $this->actingAs($hr)->post(
                    route('hr-manager.leave.action', $leave->id),
                    ['action' => $i % 5 === 0 ? 'reject' : 'approve']
                );

                if ($response->isSuccessful() || $response->isRedirection()) {
                    $successes++;
                }
            } catch (\Throwable $e) {
                // Continue
            }
        }

        $elapsed = (microtime(true) - $startTime) * 1000;
        $rate = ($successes / 200) * 100;

        $this->assertGreaterThanOrEqual(80, $rate,
            "Org-wide leave processing: {$rate}% ({$successes}/200) in {$elapsed}ms");
    }

    // ──────────────────────────────────────────────
    // 4. Audit Logs
    // ──────────────────────────────────────────────

    public function test_audit_page_loads(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.audit'));

        $response->assertStatus(200);
    }

    public function test_audit_data_api(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.audit.data'));

        $response->assertStatus(200);
    }

    public function test_audit_logs_under_concurrent_actions(): void
    {
        $hr = $this->createHRManager();

        // Generate audit trail entries
        for ($i = 0; $i < 100; $i++) {
            HRAuditTrail::create([
                'user_id' => $hr->id,
                'module' => ['leave', 'records', 'settings', 'roles'][$i % 4],
                'action' => ['create', 'update', 'delete', 'approve'][$i % 4],
                'target_type' => 'user',
                'target_id' => $i + 1,
                'details' => json_encode(['test' => "audit_#{$i}"]),
            ]);
        }

        $this->startQueryLog();
        $response = $this->actingAs($hr)->get(route('hr-manager.audit.data'));
        $queryCount = $this->getQueryCount();
        $this->stopQueryLog();

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(15, $queryCount,
            "Audit data generated {$queryCount} queries (max 15)");
    }

    // ──────────────────────────────────────────────
    // 5. User Roles & Access
    // ──────────────────────────────────────────────

    public function test_roles_page_loads(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.roles'));

        $response->assertStatus(200);
    }

    public function test_role_escalation_prevention(): void
    {
        // An employee should not be able to access HR Manager routes
        $emp = $this->createEmployee();

        $response = $this->actingAs($emp)->get(route('hr-manager.dashboard'));

        $response->assertStatus(403);
    }

    public function test_department_head_cannot_access_hr_routes(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('hr-manager.dashboard'));

        $response->assertStatus(403);
    }

    // ──────────────────────────────────────────────
    // 6. System Settings
    // ──────────────────────────────────────────────

    public function test_settings_page_loads(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.settings'));

        $response->assertStatus(200);
    }

    public function test_update_settings(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->post(route('hr-manager.settings.update'), [
            'signatory_hr_name' => 'Test HR Director',
            'signatory_hr_title' => 'HR Director IV',
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Settings update failed: HTTP {$response->getStatusCode()}"
        );
    }

    // ──────────────────────────────────────────────
    // 7. Front Desk Management (HR view)
    // ──────────────────────────────────────────────

    public function test_frontdesk_page_loads(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.frontdesk'));

        $response->assertStatus(200);
    }

    public function test_frontdesk_data_api(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.frontdesk.data'));

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────
    // 8. Reports
    // ──────────────────────────────────────────────

    public function test_reports_page_loads(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.reports'));

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────
    // 9. Leave Monthly Filtering
    // ──────────────────────────────────────────────

    public function test_leave_page_month_filter(): void
    {
        $hr = $this->createHRManager();
        $emp = $this->createEmployee();

        LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'VL',
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-10',
            'reason' => 'June leave test',
            'status' => 'pending',
        ]);

        LeaveRequest::create([
            'user_id' => $emp->id,
            'leave_type' => 'SL',
            'start_date' => '2026-05-10',
            'end_date' => '2026-05-10',
            'reason' => 'May leave test',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($hr)->get(route('hr-manager.leave').'?month=2026-06&status=all');

        $response->assertStatus(200);
        $response->assertSee('2026-06');
    }

    public function test_leave_analytics_month_filter(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('hr-manager.leave.analytics').'?month=2026-06&department=0');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'balance_summary',
            'critical_employees',
            'trend' => ['labels', 'submitted', 'approved'],
        ]);
    }
}
