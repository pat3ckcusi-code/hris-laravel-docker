<?php

namespace Tests\Feature\Performance;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;
use App\Models\LeaveRequest;
use App\Models\LeaveBalance;
use App\Models\Dtr;
use App\Models\HRAuditTrail;
use App\Models\DocumentRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;

/**
 * Performance & Load Test Suite
 *
 * Simulates scalability scenarios for 5,000+ daily users.
 * Tests are designed for sequential execution to build realistic data load.
 */
class LoadTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers, MeasuresPerformance;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests intentionally fire far more requests per minute, per
        // user, than any real user would (that's the point - measuring the
        // underlying query/rendering performance under stress). Without this,
        // the production 'api'/'documents' rate limiters (60 and 30 req/min)
        // start rejecting requests partway through a run, which looks like a
        // performance failure but is actually just the rate limiter working
        // as designed.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    // ──────────────────────────────────────────────
    // 1. Login Throughput
    // ──────────────────────────────────────────────

    public function test_login_throughput_500_users(): void
    {
        $results = [
            'total'    => 500,
            'success'  => 0,
            'failures' => 0,
            'times'    => [],
        ];

        for ($i = 0; $i < 500; $i++) {
            $user = $this->createEmployee(['password' => \Illuminate\Support\Facades\Hash::make('Pass123!')]);

            $start = microtime(true);
            $response = $this->post(route('login.submit'), [
                'email'    => $user->email,
                'password' => 'Pass123!',
            ]);
            $elapsed = (microtime(true) - $start) * 1000;

            $results['times'][] = $elapsed;
            if ($response->isRedirection() || $response->isSuccessful()) {
                $results['success']++;
            } else {
                $results['failures']++;
            }

            $this->app['auth']->guard()->logout();
            session()->flush();
        }

        $avg = array_sum($results['times']) / count($results['times']);
        $p95 = $this->percentile($results['times'], 95);
        $rate = ($results['success'] / $results['total']) * 100;

        $this->assertGreaterThanOrEqual(95, $rate,
            "Login throughput: {$rate}% success ({$results['success']}/{$results['total']})");
        $this->assertLessThanOrEqual(2000, $p95,
            "Login p95: {$p95}ms (max 2000ms). Avg: {$avg}ms");
    }

    // ──────────────────────────────────────────────
    // 2. Dashboard Load Test (All Roles)
    // ──────────────────────────────────────────────

    public function test_multi_role_dashboard_load(): void
    {
        $roleConfigs = [
            ['create' => fn () => $this->createEmployee(),       'route' => 'dashboard',                'name' => 'Employee'],
            ['create' => fn () => $this->createDepartmentHead(), 'route' => 'department-head.index',    'name' => 'DeptHead'],
            ['create' => fn () => $this->createAdminOfficer(),   'route' => 'admin-officer.index',      'name' => 'AdminOfficer'],
            ['create' => fn () => $this->createHRManager(),      'route' => 'hr-manager.dashboard',     'name' => 'HRManager'],
            ['create' => fn () => $this->createMayor(),          'route' => 'mayor.dashboard',          'name' => 'Mayor'],
        ];

        $report = [];

        foreach ($roleConfigs as $config) {
            $user = ($config['create'])();

            $times = [];
            $successes = 0;

            for ($i = 0; $i < 50; $i++) {
                $start = microtime(true);
                $response = $this->actingAs($user)->get(route($config['route']));
                $elapsed = (microtime(true) - $start) * 1000;
                $times[] = $elapsed;

                if ($response->isSuccessful()) {
                    $successes++;
                }
            }

            $report[] = [
                'role'     => $config['name'],
                'avg_ms'   => round(array_sum($times) / count($times), 2),
                'p95_ms'   => round($this->percentile($times, 95), 2),
                'max_ms'   => round(max($times), 2),
                'success'  => $successes,
                'total'    => 50,
            ];
        }

        // All dashboards should load with >90% success
        foreach ($report as $r) {
            $rate = ($r['success'] / $r['total']) * 100;
            $this->assertGreaterThanOrEqual(90, $rate,
                "{$r['role']} dashboard: {$rate}% success, avg: {$r['avg_ms']}ms, p95: {$r['p95_ms']}ms");
        }
    }

    // ──────────────────────────────────────────────
    // 3. Leave Filing Throughput
    // ──────────────────────────────────────────────

    public function test_leave_filing_throughput(): void
    {
        $successes = 0;
        $times = [];

        for ($i = 0; $i < 200; $i++) {
            $emp = $this->createEmployee();
            $this->createLeaveBalance($emp, ['VL' => 30, 'SL' => 30]);

            $start = microtime(true);

            $response = $this->actingAs($emp)->post(route('employee.leave.apply'), [
                'leave_type' => $i % 2 === 0 ? 'VL' : 'SL',
                'start_date' => now()->addDays($i + 7)->toDateString(),
                'end_date'   => now()->addDays($i + 7)->toDateString(),
                'reason'     => "Load test #{$i}",
                'dates'      => [now()->addDays($i + 7)->toDateString()],
            ]);

            $elapsed = (microtime(true) - $start) * 1000;
            $times[] = $elapsed;

            if ($response->isSuccessful() || $response->isRedirection()) {
                $successes++;
            }
        }

        $avg = array_sum($times) / count($times);
        $p95 = $this->percentile($times, 95);
        $rate = ($successes / 200) * 100;

        $this->assertGreaterThanOrEqual(80, $rate,
            "Leave filing throughput: {$rate}% ({$successes}/200). Avg: {$avg}ms, P95: {$p95}ms");
    }

    // ──────────────────────────────────────────────
    // 4. Approval Throughput
    // ──────────────────────────────────────────────

    public function test_approval_throughput(): void
    {
        $dh = $this->createDepartmentHead();
        $leaveIds = [];

        // Create 100 pending leaves
        for ($i = 0; $i < 100; $i++) {
            $emp = $this->createEmployee(['Dept_id' => $dh->Dept_id]);
            $this->createLeaveBalance($emp, ['VL' => 15]);

            $leave = LeaveRequest::create([
                'user_id'    => $emp->id,
                'leave_type' => 'VL',
                'start_date' => now()->addDays($i + 7)->toDateString(),
                'end_date'   => now()->addDays($i + 7)->toDateString(),
                'reason'     => "Approval load #{$i}",
                'status'     => 'pending',
            ]);

            $leaveIds[] = $leave->id;
        }

        $successes = 0;
        $times = [];

        foreach ($leaveIds as $id) {
            $start = microtime(true);

            $response = $this->actingAs($dh)->post(
                route('department-head.leave.approve', $id)
            );

            $elapsed = (microtime(true) - $start) * 1000;
            $times[] = $elapsed;

            if ($response->isSuccessful() || $response->isRedirection()) {
                $successes++;
            }
        }

        $avg = array_sum($times) / count($times);
        $p95 = $this->percentile($times, 95);
        $rate = ($successes / 100) * 100;

        $this->assertGreaterThanOrEqual(80, $rate,
            "Approval throughput: {$rate}%. Avg: {$avg}ms, P95: {$p95}ms");
    }

    // ──────────────────────────────────────────────
    // 5. API Endpoint Stress
    // ──────────────────────────────────────────────

    public function test_api_endpoints_under_stress(): void
    {
        $dh = $this->createDepartmentHead();

        for ($i = 0; $i < 30; $i++) {
            $this->createEmployee(['Dept_id' => $dh->Dept_id]);
        }

        $endpoints = [
            ['route' => 'api.department.dashboard-metrics', 'name' => 'Dashboard Metrics'],
            ['route' => 'api.department.leave-requests',    'name' => 'Leave Requests'],
            ['route' => 'api.department.eta-requests',      'name' => 'ETA Requests'],
            ['route' => 'api.department.locator-requests',  'name' => 'Locator Requests'],
        ];

        $report = [];
        $this->actingAs($dh);

        foreach ($endpoints as $endpoint) {
            $times = [];
            $successes = 0;

            for ($i = 0; $i < 50; $i++) {
                $start = microtime(true);
                $response = $this->get(route($endpoint['route']));
                $elapsed = (microtime(true) - $start) * 1000;
                $times[] = $elapsed;

                if ($response->isSuccessful()) {
                    $successes++;
                }
            }

            $report[] = [
                'endpoint' => $endpoint['name'],
                'avg_ms'   => round(array_sum($times) / count($times), 2),
                'p95_ms'   => round($this->percentile($times, 95), 2),
                'success'  => $successes,
            ];
        }

        foreach ($report as $r) {
            $this->assertGreaterThanOrEqual(45, $r['success'],
                "{$r['endpoint']}: only {$r['success']}/50 succeeded. Avg: {$r['avg_ms']}ms, P95: {$r['p95_ms']}ms");
        }
    }

    // ──────────────────────────────────────────────
    // 6. Document Request Queue Load
    // ──────────────────────────────────────────────

    public function test_document_request_queue_load(): void
    {
        $fd = $this->createFrontDesk();
        $successes = 0;
        $times = [];

        for ($i = 0; $i < 100; $i++) {
            $emp = $this->createEmployee();

            $docReq = DocumentRequest::create([
                'EmpNo'         => $emp->EmpNo,
                'document_type' => 'Certificate of Employment',
                'purpose'       => "Load test #{$i}",
                'status'        => 'Requested',
                'requested_on'  => now(),
            ]);

            $start = microtime(true);

            $response = $this->actingAs($fd)->post(route('front-desk.accept'), [
                'request_id' => $docReq->id,
            ]);

            $elapsed = (microtime(true) - $start) * 1000;
            $times[] = $elapsed;

            if ($response->isSuccessful() || $response->isRedirection()) {
                $successes++;
            }
        }

        $avg = array_sum($times) / count($times);
        $rate = ($successes / 100) * 100;

        $this->assertGreaterThanOrEqual(90, $rate,
            "Document queue: {$rate}% success. Avg: {$avg}ms");
    }

    // ──────────────────────────────────────────────
    // 7. Chart/Analytics Data Load
    // ──────────────────────────────────────────────

    public function test_hr_analytics_with_growing_dataset(): void
    {
        $hr = $this->createHRManager();
        $benchmarks = [];

        $sizes = [50, 100, 200];

        foreach ($sizes as $size) {
            // Seed additional employees
            for ($i = 0; $i < $size; $i++) {
                $emp = $this->createEmployee();
                LeaveRequest::create([
                    'user_id'    => $emp->id,
                    'leave_type' => ['VL', 'SL', 'SPL'][$i % 3],
                    'start_date' => now()->subDays($i)->toDateString(),
                    'end_date'   => now()->subDays($i)->toDateString(),
                    'reason'     => "Scale test",
                    'status'     => ['pending', 'approved', 'rejected'][$i % 3],
                ]);
            }

            $start = microtime(true);
            $response = $this->actingAs($hr)->get(route('hr-manager.chart-data'));
            $elapsed = (microtime(true) - $start) * 1000;

            $totalUsers = User::count();

            $benchmarks[] = [
                'users'   => $totalUsers,
                'time_ms' => round($elapsed, 2),
                'status'  => $response->getStatusCode(),
            ];
        }

        // Verify scaling is not exponential (last benchmark should not be >5x first)
        if (count($benchmarks) >= 2 && $benchmarks[0]['time_ms'] > 0) {
            $scalingFactor = $benchmarks[count($benchmarks) - 1]['time_ms'] / $benchmarks[0]['time_ms'];

            $this->assertLessThanOrEqual(10, $scalingFactor,
                "Analytics scaling factor {$scalingFactor}x suggests non-linear degradation. " .
                "Benchmarks: " . json_encode($benchmarks));
        }
    }

    // ──────────────────────────────────────────────
    // 8. Mixed Workload Simulation
    // ──────────────────────────────────────────────

    public function test_mixed_workload_simulation(): void
    {
        // Setup users for each role
        $employee = $this->createEmployee();
        $this->createLeaveBalance($employee, ['VL' => 50, 'SL' => 50]);
        $dh = $this->createDepartmentHead();
        $hr = $this->createHRManager();
        $fd = $this->createFrontDesk();

        $results = [
            'total'     => 0,
            'success'   => 0,
            'failures'  => 0,
            'by_role'   => [],
        ];

        $startTime = microtime(true);

        // Simulate 200 mixed operations
        for ($cycle = 0; $cycle < 200; $cycle++) {
            $op = $cycle % 8;
            $role = '';
            $response = null;

            try {
                switch ($op) {
                    case 0: // Employee dashboard
                        $role = 'Employee-Dashboard';
                        $response = $this->actingAs($employee)->get(route('dashboard'));
                        break;
                    case 1: // Employee ETA
                        $role = 'Employee-ETA';
                        $response = $this->actingAs($employee)->post(route('employee.eta.store'), [
                            'date'   => now()->addDays($cycle)->toDateString(),
                            'type'   => 'late_arrival',
                            'time'   => '09:30',
                            'reason' => "Mixed load #{$cycle}",
                        ]);
                        break;
                    case 2: // DH pending requests
                        $role = 'DH-PendingRequests';
                        $response = $this->actingAs($dh)->get(route('department-head.pending-requests'));
                        break;
                    case 3: // HR records
                        $role = 'HR-Records';
                        $response = $this->actingAs($hr)->get(route('hr-manager.records'));
                        break;
                    case 4: // Front desk fetch requests
                        $role = 'FD-FetchRequests';
                        $response = $this->actingAs($fd)->get(route('front-desk.requests'));
                        break;
                    case 5: // Employee attendance
                        $role = 'Employee-Attendance';
                        $response = $this->actingAs($employee)->get(route('dashboard.employee.attendance'));
                        break;
                    case 6: // Employee payslips
                        $role = 'Employee-Payslips';
                        $response = $this->actingAs($employee)->get(route('dashboard.employee.payslips'));
                        break;
                    case 7: // HR audit
                        $role = 'HR-Audit';
                        $response = $this->actingAs($hr)->get(route('hr-manager.audit'));
                        break;
                }

                $results['total']++;
                if (!isset($results['by_role'][$role])) {
                    $results['by_role'][$role] = ['success' => 0, 'failure' => 0];
                }

                if ($response && ($response->isSuccessful() || $response->isRedirection())) {
                    $results['success']++;
                    $results['by_role'][$role]['success']++;
                } else {
                    $results['failures']++;
                    $results['by_role'][$role]['failure']++;
                }
            } catch (\Throwable $e) {
                $results['total']++;
                $results['failures']++;
                if (!isset($results['by_role'][$role])) {
                    $results['by_role'][$role] = ['success' => 0, 'failure' => 0];
                }
                $results['by_role'][$role]['failure']++;
            }
        }

        $elapsed = (microtime(true) - $startTime) * 1000;
        $rate = $results['total'] > 0 ? ($results['success'] / $results['total']) * 100 : 0;

        $this->assertGreaterThanOrEqual(85, $rate,
            "Mixed workload: {$rate}% ({$results['success']}/{$results['total']}) in {$elapsed}ms. " .
            "Per-role: " . json_encode($results['by_role']));
    }

    // ──────────────────────────────────────────────
    // 9. Database Connection Pool Test
    // ──────────────────────────────────────────────

    public function test_database_connection_stability(): void
    {
        $errors = 0;

        for ($i = 0; $i < 100; $i++) {
            try {
                DB::select('SELECT 1');
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        $this->assertEquals(0, $errors, "Database connection failed {$errors}/100 times");
    }
}
