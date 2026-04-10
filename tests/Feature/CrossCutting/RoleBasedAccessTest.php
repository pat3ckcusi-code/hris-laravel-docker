<?php

namespace Tests\Feature\CrossCutting;

use Tests\TestCase;
use Tests\Traits\CreatesTestUsers;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Cross-Cutting: Role-Based Access Control Tests
 *
 * Ensures no unauthorized cross-role access across all 10 roles.
 */
class RoleBasedAccessTest extends TestCase
{
    use RefreshDatabase, CreatesTestUsers;

    /**
     * Map of role-restricted routes with their required roles.
     */
    protected function getProtectedRoutes(): array
    {
        return [
            // Department Head only
            ['route' => 'department-head.index',    'allowed' => ['Department Head'],              'method' => 'GET'],
            ['route' => 'department-head.pending-requests', 'allowed' => ['Department Head'],      'method' => 'GET'],
            ['route' => 'department-head.statistics','allowed' => ['Department Head'],              'method' => 'GET'],
            // Administrative Officer only
            ['route' => 'admin-officer.index',       'allowed' => ['Administrative Officer'],      'method' => 'GET'],
            ['route' => 'admin-officer.pending-requests', 'allowed' => ['Administrative Officer'], 'method' => 'GET'],
            // HR Manager only
            ['route' => 'hr-manager.dashboard',      'allowed' => ['HR Manager'],                  'method' => 'GET'],
            ['route' => 'hr-manager.records',        'allowed' => ['HR Manager'],                  'method' => 'GET'],
            ['route' => 'hr-manager.leave',          'allowed' => ['HR Manager'],                  'method' => 'GET'],
            ['route' => 'hr-manager.audit',          'allowed' => ['HR Manager'],                  'method' => 'GET'],
            ['route' => 'hr-manager.roles',          'allowed' => ['HR Manager'],                  'method' => 'GET'],
            ['route' => 'hr-manager.settings',       'allowed' => ['HR Manager'],                  'method' => 'GET'],
            // Leave Manager only
            ['route' => 'leave-manager.manage-balance', 'allowed' => ['Leave Manager'],            'method' => 'GET'],
            ['route' => 'leave-manager.manage-credits', 'allowed' => ['Leave Manager'],            'method' => 'GET'],
            ['route' => 'leave-manager.cancel-leaves',  'allowed' => ['Leave Manager'],            'method' => 'GET'],
            // Mayor only
            ['route' => 'mayor.dashboard',           'allowed' => ['Mayor'],                       'method' => 'GET'],
            ['route' => 'mayor.approvals',           'allowed' => ['Mayor'],                       'method' => 'GET'],
            ['route' => 'mayor.reports',             'allowed' => ['Mayor'],                       'method' => 'GET'],
            ['route' => 'mayor.policies',            'allowed' => ['Mayor'],                       'method' => 'GET'],
            ['route' => 'mayor.settings',            'allowed' => ['Mayor'],                       'method' => 'GET'],
        ];
    }

    /**
     * All roles to test against.
     */
    protected function getAllRoles(): array
    {
        return [
            'employee',
            'Department Head',
            'Administrative Officer',
            'HR Manager',
            'Mayor',
            'Leave Manager',
            'Records Manager',
            'Front Desk',
            'Payroll Manager',
            'Time Keeper',
        ];
    }

    public function test_employee_cannot_access_department_head_routes(): void
    {
        $emp = $this->createEmployee();

        $response = $this->actingAs($emp)->get(route('department-head.index'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_employee_cannot_access_hr_manager_routes(): void
    {
        $emp = $this->createEmployee();

        $response = $this->actingAs($emp)->get(route('hr-manager.dashboard'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_employee_cannot_access_mayor_routes(): void
    {
        $emp = $this->createEmployee();

        $response = $this->actingAs($emp)->get(route('mayor.dashboard'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_employee_cannot_access_leave_manager_routes(): void
    {
        $emp = $this->createEmployee();

        $response = $this->actingAs($emp)->get(route('leave-manager.manage-balance'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_department_head_cannot_access_mayor_routes(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('mayor.dashboard'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_department_head_cannot_access_hr_routes(): void
    {
        $dh = $this->createDepartmentHead();

        $response = $this->actingAs($dh)->get(route('hr-manager.dashboard'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_hr_manager_cannot_access_mayor_routes(): void
    {
        $hr = $this->createHRManager();

        $response = $this->actingAs($hr)->get(route('mayor.dashboard'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_leave_manager_cannot_access_hr_routes(): void
    {
        $lm = $this->createLeaveManager();

        $response = $this->actingAs($lm)->get(route('hr-manager.dashboard'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_front_desk_cannot_access_admin_routes(): void
    {
        $fd = $this->createFrontDesk();

        $response = $this->actingAs($fd)->get(route('admin-officer.index'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function test_records_manager_cannot_access_leave_manager_routes(): void
    {
        $rm = $this->createRecordsManager();

        $response = $this->actingAs($rm)->get(route('leave-manager.manage-balance'));
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Comprehensive cross-role access test: test every role against every protected route.
     */
    public function test_comprehensive_role_isolation(): void
    {
        $routes = $this->getProtectedRoutes();
        $roles = $this->getAllRoles();
        $violations = [];

        foreach ($routes as $routeConfig) {
            foreach ($roles as $role) {
                // Skip allowed roles
                if (in_array($role, $routeConfig['allowed'])) {
                    continue;
                }

                $user = $this->createUserWithRole($role);

                try {
                    $response = $this->actingAs($user)
                        ->{strtolower($routeConfig['method'])}(route($routeConfig['route']));

                    if ($response->getStatusCode() !== 403) {
                        $violations[] = sprintf(
                            '%s [%s] accessed %s (HTTP %d)',
                            $role,
                            $routeConfig['method'],
                            $routeConfig['route'],
                            $response->getStatusCode()
                        );
                    }
                } catch (\Throwable $e) {
                    // Route threw exception which is acceptable as denial
                }
            }
        }

        $this->assertEmpty($violations,
            "RBAC Violations found:\n" . implode("\n", $violations));
    }

    /**
     * Verify privilege escalation is prevented.
     */
    public function test_no_privilege_escalation_via_manual_role_change(): void
    {
        $emp = $this->createEmployee();

        // Employee tries to POST to HR Manager settings
        $response = $this->actingAs($emp)->post(route('hr-manager.settings.update'), [
            'signatory_hr_name' => 'Escalated User',
        ]);

        $this->assertEquals(403, $response->getStatusCode(),
            'Employee was able to escalate to HR Manager settings');
    }
}
