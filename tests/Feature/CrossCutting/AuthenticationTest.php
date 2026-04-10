<?php

namespace Tests\Feature\CrossCutting;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Tests\Traits\MeasuresPerformance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

/**
 * Cross-Cutting: Authentication Tests
 *
 * Covers: Concurrent logins, session management, force password change,
 *         inactive/separated user blocking
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers, MeasuresPerformance;

    // ──────────────────────────────────────────────
    // 1. Basic Authentication
    // ──────────────────────────────────────────────

    public function test_login_page_loads(): void
    {
        $response = $this->get(route('login'));

        $response->assertStatus(200);
    }

    public function test_valid_credentials_login(): void
    {
        $user = $this->createEmployee(['password' => Hash::make('TestPass123!')]);

        $response = $this->post(route('login.submit'), [
            'email'    => $user->email,
            'password' => 'TestPass123!',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_invalid_credentials_rejected(): void
    {
        $user = $this->createEmployee();

        $response = $this->post(route('login.submit'), [
            'email'    => $user->email,
            'password' => 'WrongPassword',
        ]);

        $this->assertGuest();
    }

    public function test_inactive_user_blocked(): void
    {
        $user = $this->createEmployee([
            'Status'   => 'Inactive',
            'password' => Hash::make('TestPass123!'),
        ]);

        $response = $this->post(route('login.submit'), [
            'email'    => $user->email,
            'password' => 'TestPass123!',
        ]);

        // Should be rejected
        $this->assertGuest();
    }

    public function test_separated_user_blocked(): void
    {
        $user = $this->createEmployee([
            'Status'   => 'Separated',
            'password' => Hash::make('TestPass123!'),
        ]);

        $response = $this->post(route('login.submit'), [
            'email'    => $user->email,
            'password' => 'TestPass123!',
        ]);

        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $user = $this->createEmployee();

        $this->actingAs($user);
        $this->assertAuthenticated();

        $response = $this->post(route('logout'));

        $response->assertRedirect();
    }

    // ──────────────────────────────────────────────
    // 2. Force Password Change
    // ──────────────────────────────────────────────

    public function test_force_password_change_page(): void
    {
        $user = $this->createEmployee(['force_password_change' => true]);

        $response = $this->actingAs($user)->get(route('password.force.edit'));

        $response->assertStatus(200);
    }

    public function test_force_password_change_submission(): void
    {
        $user = $this->createEmployee([
            'password' => Hash::make('OldPass123!'),
            'force_password_change' => true,
        ]);

        $response = $this->actingAs($user)->post(route('password.force.update'), [
            'current_password'      => 'OldPass123!',
            'password'              => 'NewSecurePass456!',
            'password_confirmation' => 'NewSecurePass456!',
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Force password change failed: HTTP {$response->getStatusCode()}"
        );
    }

    // ──────────────────────────────────────────────
    // 3. Password Reset Flow
    // ──────────────────────────────────────────────

    public function test_forgot_password_page(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertStatus(200);
    }

    public function test_reset_password_email(): void
    {
        $user = $this->createEmployee();

        $response = $this->post(route('password.email'), [
            'email' => $user->email,
        ]);

        $this->assertTrue(
            $response->isSuccessful() || $response->isRedirection(),
            "Password reset email failed: HTTP {$response->getStatusCode()}"
        );
    }

    // ──────────────────────────────────────────────
    // 4. Concurrent Login Simulation
    // ──────────────────────────────────────────────

    public function test_simulate_concurrent_logins(): void
    {
        $successes = 0;
        $failures = 0;
        $times = [];

        // Simulate 200 unique users logging in (scaled from 5000 for test speed)
        for ($i = 0; $i < 200; $i++) {
            $user = $this->createEmployee(['password' => Hash::make('TestPass123!')]);

            $start = microtime(true);
            try {
                $response = $this->post(route('login.submit'), [
                    'email'    => $user->email,
                    'password' => 'TestPass123!',
                ]);

                $elapsed = (microtime(true) - $start) * 1000;
                $times[] = $elapsed;

                if ($response->isRedirection() || $response->isSuccessful()) {
                    $successes++;
                } else {
                    $failures++;
                }
            } catch (\Throwable $e) {
                $elapsed = (microtime(true) - $start) * 1000;
                $times[] = $elapsed;
                $failures++;
            }

            // Reset session for next user
            $this->app['auth']->guard()->logout();
            session()->flush();
        }

        $total = $successes + $failures;
        $rate = $total > 0 ? ($successes / $total) * 100 : 0;
        $avgTime = count($times) > 0 ? array_sum($times) / count($times) : 0;
        $p95 = $this->percentile($times, 95);

        $this->assertGreaterThanOrEqual(90, $rate,
            "Concurrent login success rate: {$rate}% ({$successes}/{$total})");
        $this->assertLessThanOrEqual(3000, $p95,
            "Login p95 response: {$p95}ms (max 3000ms)");
    }

    // ──────────────────────────────────────────────
    // 5. Protected Route Access
    // ──────────────────────────────────────────────

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_cannot_access_login(): void
    {
        $user = $this->createEmployee();

        $response = $this->actingAs($user)->get(route('login'));

        $response->assertRedirect();
    }
}
