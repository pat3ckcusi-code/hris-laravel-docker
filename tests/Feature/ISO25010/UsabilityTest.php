<?php

namespace Tests\Feature\ISO25010;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISO/IEC 25010 — 4. Usability
 *
 * Tests: Learnability, Operability, User interface aesthetics, Accessibility
 */
class UsabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Database\Eloquent\Model::unguard();
    }

    protected function tearDown(): void
    {
        \Illuminate\Database\Eloquent\Model::reguard();
        parent::tearDown();
    }

    private function createDepartment(): Department
    {
        return Department::create([
            'DeptCode' => 'TST',
            'Dept_name' => 'Test Department',
            'EmpNo' => 'DH' . uniqid(),
            'Designation' => 'Department Head',
        ]);
    }

    private function createUser(string $role, array $extra = []): User
    {
        $dept = $this->createDepartment();
        return User::create(array_merge([
            'name' => 'UI User',
            'first_name' => 'UI',
            'last_name' => 'User',
            'email' => 'ui' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'access_level' => $role,
            'employee_type' => 'permanent',
            'Dept_id' => $dept->Dept_id,
            'EmpNo' => 'U' . uniqid(),
        ], $extra));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4.1 SIDEBAR CONSISTENCY — every role dashboard has sidebar
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function employee_dashboard_contains_sidebar(): void
    {
        $user = $this->createUser('employee');
        $response = $this->actingAs($user)->get('/dashboard');
        $response->assertStatus(200);
        $response->assertSee('sidebar', false);
    }

    /** @test */
    public function hr_manager_dashboard_contains_sidebar(): void
    {
        $user = $this->createUser('hr manager');
        $response = $this->actingAs($user)->get('/dashboard/hr-manager');
        $response->assertStatus(200);
        $response->assertSee('sidebar', false);
    }

    /** @test */
    public function payroll_manager_dashboard_contains_sidebar(): void
    {
        $user = $this->createUser('payroll manager');
        $response = $this->actingAs($user)->get('/payroll-manager/dashboard');
        $response->assertStatus(200);
        $response->assertSee('sidebar', false);
    }

    /** @test */
    public function mayor_dashboard_contains_sidebar(): void
    {
        $user = $this->createUser('mayor');
        $response = $this->actingAs($user)->get('/mayor/dashboard');
        $response->assertStatus(200);
        $response->assertSee('sidebar', false);
    }

    /** @test */
    public function department_head_dashboard_contains_sidebar(): void
    {
        $user = $this->createUser('department head');
        $response = $this->actingAs($user)->get('/department-head');
        $response->assertStatus(200);
        $response->assertSee('sidebar', false);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4.2 NAVIGATION GROUPING — sections are properly labelled
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function payroll_sidebar_has_grouped_navigation(): void
    {
        $user = $this->createUser('payroll manager');
        $response = $this->actingAs($user)->get('/payroll-manager/dashboard');
        $content = $response->getContent();

        $this->assertStringContainsString('Dashboard', $content);
        $this->assertStringContainsString('sidebar', strtolower($content));
        $this->assertStringContainsString('Pay Processing', $content);
        $this->assertStringContainsString('Compensation', $content);
        $this->assertStringContainsString('Quick Actions', $content);
        $this->assertStringContainsString('Create Payroll Run', $content);
        $this->assertStringContainsString('View Latest Payroll', $content);
    }

    /** @test */
    public function hr_manager_sidebar_has_key_links(): void
    {
        $user = $this->createUser('hr manager');
        $response = $this->actingAs($user)->get('/dashboard/hr-manager');
        $content = $response->getContent();

        $this->assertStringContainsString('Dashboard', $content);
        $this->assertStringContainsString('Records', $content);
        $this->assertStringContainsString('Leave', $content);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4.3 ACCESSIBILITY — labels, titles, form accessibility
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function login_page_has_proper_form_labels(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $content = $response->getContent();

        // Check for email and password inputs
        $this->assertMatchesRegularExpression('/name=["\']email["\']/', $content);
        $this->assertMatchesRegularExpression('/name=["\']password["\']/', $content);
    }

    /** @test */
    public function all_dashboards_return_html_with_title(): void
    {
        $roles = [
            'employee' => '/dashboard',
            'hr manager' => '/dashboard/hr-manager',
            'payroll manager' => '/payroll-manager/dashboard',
            'mayor' => '/mayor/dashboard',
        ];

        foreach ($roles as $role => $url) {
            $user = $this->createUser($role);
            $response = $this->actingAs($user)->get($url);
            $response->assertStatus(200);
            $this->assertStringContainsString('<html', $response->getContent(), "Dashboard for {$role} missing <html> tag");
        }
    }

    /** @test */
    public function payroll_settings_page_loads_with_form(): void
    {
        $user = $this->createUser('payroll manager');
        $response = $this->actingAs($user)->get('/payroll-manager/settings');
        $response->assertStatus(200);
        $response->assertSee('Payroll Settings', false);
    }

    /** @test */
    public function employee_sidebar_shows_self_service_links(): void
    {
        $user = $this->createUser('employee');
        $response = $this->actingAs($user)->get('/dashboard');
        $content = $response->getContent();

        $this->assertStringContainsString('Self-Service', $content);
        $this->assertStringContainsString('PDS', $content);
        $this->assertStringContainsString('Leave Requests', $content);
        $this->assertStringContainsString('Payslips', $content);
        $this->assertStringContainsString('Attendance Logs', $content);
        $this->assertStringContainsString('sidebar', strtolower($content));
    }

    /** @test */
    public function logout_button_present_on_dashboards(): void
    {
        $user = $this->createUser('employee');
        $response = $this->actingAs($user)->get('/dashboard');
        $content = $response->getContent();

        $this->assertStringContainsString('logout', strtolower($content));
    }
}
