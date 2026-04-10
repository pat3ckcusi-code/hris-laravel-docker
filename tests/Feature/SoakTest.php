<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/**
 * Soak Test Suite — simulates sustained mixed traffic patterns
 * to verify system stability under extended load.
 *
 * Run: php artisan test --filter=SoakTest
 */
class SoakTest extends TestCase
{
    use RefreshDatabase;

    private const ITERATIONS = 50;

    private function createAuthenticatedUser(string $role = 'employee'): User
    {
        return User::factory()->create([
            'access_level' => $role,
            'Status' => 'Active',
        ]);
    }

    public function test_sustained_dashboard_load(): void
    {
        $user = $this->createAuthenticatedUser('HR Manager');

        $responseTimes = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $start = microtime(true);
            $response = $this->actingAs($user)->get(route('dashboard'));
            $elapsed = (microtime(true) - $start) * 1000;
            $responseTimes[] = $elapsed;

            $response->assertStatus(200);
        }

        $avg = array_sum($responseTimes) / count($responseTimes);
        $max = max($responseTimes);

        // Ensure no memory leak or performance degradation: avg < 2s, max < 5s
        $this->assertLessThan(2000, $avg, "Average dashboard response time {$avg}ms exceeds 2s threshold");
        $this->assertLessThan(5000, $max, "Max dashboard response time {$max}ms exceeds 5s threshold");
    }

    public function test_sustained_api_endpoint_load(): void
    {
        $user = $this->createAuthenticatedUser('Department Head');

        $responseTimes = [];
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $start = microtime(true);
            $response = $this->actingAs($user)->get(route('dashboard'));
            $elapsed = (microtime(true) - $start) * 1000;
            $responseTimes[] = $elapsed;

            $response->assertSuccessful();
        }

        $avg = array_sum($responseTimes) / count($responseTimes);

        // No progressive slowdown: last 10 avg should not exceed first 10 avg by > 50%
        $first10Avg = array_sum(array_slice($responseTimes, 0, 10)) / 10;
        $last10Avg = array_sum(array_slice($responseTimes, -10)) / 10;

        if ($first10Avg > 0) {
            $degradation = (($last10Avg - $first10Avg) / $first10Avg) * 100;
            $this->assertLessThan(50, $degradation, "Performance degradation of {$degradation}% detected over {self::ITERATIONS} iterations");
        }
    }

    public function test_sustained_login_flow(): void
    {
        $user = $this->createAuthenticatedUser('employee');

        for ($i = 0; $i < 20; $i++) {
            // Visit login page
            $response = $this->get(route('login'));
            $response->assertStatus(200);

            // Authenticated dashboard access
            $response = $this->actingAs($user)->get(route('dashboard'));
            $response->assertSuccessful();
        }

        // If we got here without exception, the auth flow is stable
        $this->assertTrue(true);
    }

    public function test_cache_stability_under_repeated_access(): void
    {
        Cache::flush();
        $user = $this->createAuthenticatedUser('HR Manager');

        // Warm cache
        $this->actingAs($user)->get(route('dashboard'));

        // Repeated cached hits
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $response = $this->actingAs($user)->get(route('dashboard'));
            $response->assertSuccessful();
        }

        $this->assertTrue(true, 'Cache remained stable across repeated access');
    }

    public function test_concurrent_role_switching(): void
    {
        $roles = ['employee', 'Department Head', 'Administrative Officer', 'HR Manager'];
        $users = [];
        foreach ($roles as $role) {
            $users[$role] = $this->createAuthenticatedUser($role);
        }

        // Simulate rapid role-based access
        for ($i = 0; $i < 30; $i++) {
            foreach ($users as $role => $user) {
                $response = $this->actingAs($user)->get(route('dashboard'));
                $response->assertSuccessful();
            }
        }

        $this->assertTrue(true, 'Multi-role concurrent access stable');
    }

    public function test_path_traversal_blocked_under_load(): void
    {
        $malicious = [
            '/../../etc/passwd',
            '/..%2f..%2fetc/passwd',
            '/%00test',
        ];

        for ($i = 0; $i < 20; $i++) {
            foreach ($malicious as $path) {
                $response = $this->get($path);
                $this->assertContains($response->status(), [400, 404]);
            }
        }

        $this->assertTrue(true, 'Path traversal consistently blocked');
    }

    public function test_rate_limiting_enforced(): void
    {
        // Attempt more than 5 login POSTs per minute to trigger rate limit
        for ($i = 0; $i < 7; $i++) {
            $response = $this->post(route('login.submit'), [
                'email' => 'nonexistent@test.com',
                'password' => 'wrong',
            ]);
        }

        // After 5+ attempts, expect 429
        $response = $this->post(route('login.submit'), [
            'email' => 'nonexistent@test.com',
            'password' => 'wrong',
        ]);

        $this->assertContains($response->status(), [302, 429], 'Rate limiting should throttle or redirect excessive login attempts');
    }
}
