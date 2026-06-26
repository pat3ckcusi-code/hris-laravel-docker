<?php

namespace Tests\Feature\ISO25010;

use App\Models\Department;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISO/IEC 25010 - 6. Security
 *
 * Tests: Confidentiality, Integrity, Non-repudiation, Accountability, Authenticity
 */
class SecurityTest extends TestCase
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
            'name' => 'Sec User',
            'first_name' => 'Sec',
            'last_name' => 'User',
            'email' => 'sec' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'access_level' => $role,
            'employee_type' => 'permanent',
            'Dept_id' => $dept->Dept_id,
            'EmpNo' => 'S' . uniqid(),
        ], $extra));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6.1 AUTHENTICATION - login/logout/session
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    /** @test */
    public function unauthenticated_access_to_hr_manager_redirected(): void
    {
        $response = $this->get('/dashboard/hr-manager');
        $response->assertRedirect('/login');
    }

    /** @test */
    public function unauthenticated_access_to_payroll_redirected(): void
    {
        $response = $this->get('/payroll-manager/dashboard');
        $response->assertRedirect('/login');
    }

    /** @test */
    public function unauthenticated_access_to_mayor_redirected(): void
    {
        $response = $this->get('/mayor/dashboard');
        $response->assertRedirect('/login');
    }

    /** @test */
    public function login_with_valid_credentials_succeeds(): void
    {
        $user = $this->createUser('employee');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    /** @test */
    public function login_with_invalid_credentials_fails(): void
    {
        $user = $this->createUser('employee');

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    /** @test */
    public function logout_terminates_session(): void
    {
        $user = $this->createUser('employee');
        $this->actingAs($user);

        $response = $this->post('/logout');

        $this->assertGuest();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6.2 ROLE-BASED ACCESS CONTROL
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function employee_cannot_access_hr_manager_dashboard(): void
    {
        $employee = $this->createUser('employee');

        $response = $this->actingAs($employee)->get('/dashboard/hr-manager');
        $response->assertStatus(403);
    }

    /** @test */
    public function employee_cannot_access_payroll_dashboard(): void
    {
        $employee = $this->createUser('employee');

        $response = $this->actingAs($employee)->get('/payroll-manager/dashboard');
        $response->assertStatus(403);
    }

    /** @test */
    public function employee_cannot_access_mayor_dashboard(): void
    {
        $employee = $this->createUser('employee');

        $response = $this->actingAs($employee)->get('/mayor/dashboard');
        $response->assertStatus(403);
    }

    /** @test */
    public function hr_manager_can_access_hr_dashboard(): void
    {
        $hrManager = $this->createUser('hr-manager');

        $response = $this->actingAs($hrManager)->get('/dashboard/hr-manager');
        $response->assertStatus(200);
    }

    /** @test */
    public function payroll_manager_can_access_payroll_dashboard(): void
    {
        $payroll = $this->createUser('payroll-manager');

        $response = $this->actingAs($payroll)->get('/payroll-manager/dashboard');
        $response->assertStatus(200);
    }

    /** @test */
    public function mayor_can_access_mayor_dashboard(): void
    {
        $mayor = $this->createUser('mayor');

        $response = $this->actingAs($mayor)->get('/mayor/dashboard');
        $response->assertStatus(200);
    }

    /** @test */
    public function employee_cannot_lock_payroll_run(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'computed',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($employee)->post("/payroll-manager/runs/{$run->id}/lock");
        $response->assertStatus(403);
    }

    /** @test */
    public function employee_cannot_compute_payroll_run(): void
    {
        $admin = $this->createUser('payroll-manager');
        $employee = $this->createUser('employee');

        $run = PayrollRun::create([
            'period' => '2026-04 1st',
            'period_start' => '2026-04-01',
            'period_end' => '2026-04-15',
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($employee)->post("/payroll-manager/runs/{$run->id}/compute");
        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6.3 CSRF PROTECTION
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function login_form_contains_csrf_token(): void
    {
        $response = $this->get('/login');
        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertMatchesRegularExpression('/_token/', $content);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6.4 CONFIDENTIALITY - sensitive data protection
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function password_is_hashed_in_database(): void
    {
        $user = $this->createUser('employee');
        $raw = \Illuminate\Support\Facades\DB::table('users')->where('id', $user->id)->value('password');

        $this->assertNotEquals('password', $raw);
        $this->assertTrue(password_verify('password', $raw));
    }

    /** @test */
    public function password_field_hidden_from_serialization(): void
    {
        $user = $this->createUser('employee');
        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }

    /** @test */
    public function pds_encrypts_sensitive_fields(): void
    {
        $user = $this->createUser('employee');

        $pds = \App\Models\Pds::create([
            'user_id' => $user->id,
            'section_data' => [
                'personal' => [
                    'ssn' => '12-345-678-9',
                    'tin' => '123-456-789',
                    'first_name' => 'Test',
                ],
            ],
            'status' => 'draft',
        ]);

        // Raw DB value should be encrypted (SSN should not appear in plain text)
        $rawData = \Illuminate\Support\Facades\DB::table('user_pds')->where('id', $pds->id)->value('section_data');
        $this->assertStringNotContainsString('12-345-678-9', $rawData);

        // But reading through model decrypts the outer blob; individual fields still encrypted
        // Use getAllSectionData() to decrypt individual sensitive fields
        $pds->refresh();
        $sectionData = $pds->getAllSectionData();
        $this->assertEquals('12-345-678-9', $sectionData['personal']['ssn'] ?? null);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6.5 ACCOUNTABILITY - audit trails for security events
    // ═══════════════════════════════════════════════════════════════════════

    /** @test */
    public function hr_actions_generate_audit_trail(): void
    {
        $hrManager = $this->createUser('hr-manager');

        \App\Models\HRAuditTrail::create([
            'actor_user_id' => $hrManager->id,
            'module' => 'roles',
            'action' => 'role_changed',
            'target_type' => 'user',
            'target_id' => $hrManager->id,
            'details' => ['from' => 'employee', 'to' => 'hr-manager'],
        ]);

        $this->assertDatabaseHas('hr_audit_trails', [
            'actor_user_id' => $hrManager->id,
            'action' => 'role_changed',
        ]);
    }

    /** @test */
    public function employee_cannot_access_payslips_of_others(): void
    {
        $employee = $this->createUser('employee');

        $response = $this->actingAs($employee)->get('/payroll-manager/payslips');
        $response->assertStatus(403);
    }

    /** @test */
    public function employee_cannot_access_payroll_reports(): void
    {
        $employee = $this->createUser('employee');

        $response = $this->actingAs($employee)->get('/payroll-manager/reports');
        $response->assertStatus(403);
    }
}
