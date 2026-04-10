<?php

namespace Tests\Feature\RoleBased;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;
use App\Models\LeaveRequest;
use App\Models\TravelOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * City Mayor Role Tests
 *
 * Covers: Executive Snapshot, Leave/Travel Approvals, Reports, Policies, Settings
 */
class MayorTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers, MeasuresPerformance;

    // ──────────────────────────────────────────────
    // 1. Executive Snapshot (Dashboard)
    // ──────────────────────────────────────────────

    public function test_mayor_dashboard_loads(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.dashboard'));

        $response->assertStatus(200);
    }

    public function test_mayor_chart_data(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.chart-data'));

        $response->assertStatus(200);
    }

    public function test_executive_dashboard_under_load(): void
    {
        $mayor = $this->createMayor();

        // Seed org-wide data
        for ($i = 0; $i < 100; $i++) {
            $emp = $this->createEmployee();
            LeaveRequest::create([
                'user_id'    => $emp->id,
                'leave_type' => ['VL', 'SL', 'SPL'][$i % 3],
                'start_date' => now()->subDays($i)->toDateString(),
                'end_date'   => now()->subDays($i)->toDateString(),
                'reason'     => "Mayor analytics #{$i}",
                'status'     => ['pending', 'approved', 'rejected'][$i % 3],
            ]);
        }

        $this->startQueryLog();
        $start = microtime(true);

        $response = $this->actingAs($mayor)->get(route('mayor.chart-data'));

        $elapsed = (microtime(true) - $start) * 1000;
        $queryCount = $this->getQueryCount();
        $this->stopQueryLog();

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(5000, $elapsed,
            "Mayor chart data took {$elapsed}ms with 100 employees (max 5000ms)");
    }

    public function test_employees_filter(): void
    {
        $mayor = $this->createMayor();

        for ($i = 0; $i < 20; $i++) {
            $this->createEmployee();
        }

        $response = $this->actingAs($mayor)->get(route('mayor.employees.filter'));

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────
    // 2. Leave Approvals
    // ──────────────────────────────────────────────

    public function test_approvals_page_loads(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.approvals'));

        $response->assertStatus(200);
    }

    public function test_leave_requests_data(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.leave-requests.data'));

        $response->assertStatus(200);
    }

    public function test_mayor_approve_leave(): void
    {
        $mayor = $this->createMayor();
        $dh = $this->createDepartmentHead();
        $this->createLeaveBalance($dh, ['VL' => 15]);

        $leave = LeaveRequest::create([
            'user_id'    => $dh->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date'   => now()->addWeek()->toDateString(),
            'reason'     => 'DH leave for mayor approval',
            'status'     => 'pending',
        ]);

        $response = $this->actingAs($mayor)->post(
            route('mayor.leave.approve', $leave->id)
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Mayor leave approval failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_mayor_reject_leave(): void
    {
        $mayor = $this->createMayor();
        $hr = $this->createHRManager();

        $leave = LeaveRequest::create([
            'user_id'    => $hr->id,
            'leave_type' => 'VL',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date'   => now()->addWeek()->toDateString(),
            'reason'     => 'HR leave for mayor rejection',
            'status'     => 'pending',
        ]);

        $response = $this->actingAs($mayor)->post(
            route('mayor.leave.reject', $leave->id),
            ['remarks' => 'Critical period']
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Mayor leave rejection failed: HTTP {$response->getStatusCode()}"
        );
    }

    public function test_simulate_100_concurrent_approvals(): void
    {
        $mayor = $this->createMayor();
        $successes = 0;
        $errors = [];

        for ($i = 0; $i < 100; $i++) {
            $dh = $this->createDepartmentHead();
            $this->createLeaveBalance($dh, ['VL' => 30]);

            $leave = LeaveRequest::create([
                'user_id'    => $dh->id,
                'leave_type' => 'VL',
                'start_date' => now()->addDays($i + 7)->toDateString(),
                'end_date'   => now()->addDays($i + 7)->toDateString(),
                'reason'     => "Mayor concurrent approval #{$i}",
                'status'     => 'pending',
            ]);

            try {
                $response = $this->actingAs($mayor)->post(
                    route('mayor.leave.approve', $leave->id)
                );

                if ($response->isSuccessful() || $response->isRedirection()) {
                    $successes++;
                } else {
                    if (count($errors) < 5) $errors[] = "#{$i}: HTTP {$response->getStatusCode()}";
                }
            } catch (\Throwable $e) {
                if (count($errors) < 5) $errors[] = "#{$i}: {$e->getMessage()}";
            }
        }

        $rate = ($successes / 100) * 100;
        $this->assertGreaterThanOrEqual(85, $rate,
            "Mayor concurrent approvals: {$rate}% ({$successes}/100). Errors: " . implode('; ', $errors));
    }

    // ──────────────────────────────────────────────
    // 3. Travel Order Approvals
    // ──────────────────────────────────────────────

    public function test_travel_order_approvals_page(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.travel-order-approvals'));

        $response->assertStatus(200);
    }

    public function test_approve_travel_order(): void
    {
        $mayor = $this->createMayor();
        $dh = $this->createDepartmentHead();

        $toId = \Illuminate\Support\Facades\DB::table('travel_orders')->insertGetId([
            'travel_order_num' => 'TO-TEST-' . time(),
            'destination'      => 'Provincial Capitol',
            'purpose'          => 'Official business',
            'start_date'       => now()->addDays(3)->toDateString(),
            'end_date'         => now()->addDays(5)->toDateString(),
            'status'           => 'Pending',
            'recommender'      => $dh->id,
            'created_by'       => $dh->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $response = $this->actingAs($mayor)->post(
            route('mayor.travel-orders.approve', $toId)
        );

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Travel order approval failed: HTTP {$response->getStatusCode()}"
        );
    }

    // ──────────────────────────────────────────────
    // 4. Reports
    // ──────────────────────────────────────────────

    public function test_reports_page_loads(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.reports'));

        $response->assertStatus(200);
    }

    public function test_consolidated_report_performance(): void
    {
        $mayor = $this->createMayor();

        // Seed data
        for ($i = 0; $i < 50; $i++) {
            $this->createEmployee();
        }

        $start = microtime(true);
        $response = $this->actingAs($mayor)->get(route('mayor.reports'));
        $elapsed = (microtime(true) - $start) * 1000;

        $response->assertStatus(200);
        $this->assertLessThanOrEqual(5000, $elapsed,
            "Reports page took {$elapsed}ms with 50 employees (max 5000ms)");
    }

    // ──────────────────────────────────────────────
    // 5. Policies & Events
    // ──────────────────────────────────────────────

    public function test_policies_page_loads(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.policies'));

        $response->assertStatus(200);
    }

    public function test_events_page_loads(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.events'));

        $response->assertStatus(200);
    }

    public function test_employees_page_loads(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.employees'));

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────
    // 6. Settings
    // ──────────────────────────────────────────────

    public function test_settings_page_loads(): void
    {
        $mayor = $this->createMayor();

        $response = $this->actingAs($mayor)->get(route('mayor.settings'));

        $response->assertStatus(200);
    }
}
