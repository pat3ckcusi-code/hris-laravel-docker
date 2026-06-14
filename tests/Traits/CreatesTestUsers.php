<?php

namespace Tests\Traits;

use App\Models\User;
use App\Models\Department;
use App\Models\LeaveBalance;
use Illuminate\Support\Facades\Hash;

/**
 * Provides helper methods to create test users with various roles.
 */
trait CreatesTestUsers
{
    protected static int $empCounter = 90000;

    protected function createUserWithRole(string $role, array $overrides = []): User
    {
        $empNo = 'TEST-' . (++static::$empCounter);

        $dept = Department::first() ?? Department::forceCreate([
            'DeptCode'    => 'TEST',
            'Dept_name'   => 'Test Department',
            'EmpNo'       => $empNo,
            'Designation' => 'Test',
        ]);

        $defaults = [
            'name'          => fake()->name(),
            'last_name'     => fake()->lastName(),
            'first_name'    => fake()->firstName(),
            'middle_name'   => fake()->lastName(),
            'email'         => fake()->unique()->safeEmail(),
            'password'      => Hash::make('TestPass123!'),
            'EmpNo'         => $empNo,
            'UserName'      => 'testuser_' . static::$empCounter,
            'AcctName'      => fake()->name(),
            'designation'   => 'Staff',
            'Dept_id'       => $dept->Dept_id,
            'Status'        => 'Active',
            'ContactNo'     => fake()->phoneNumber(),
            'access_level'  => $role,
            'employee_type' => 'Permanent',
            'force_password_change' => false,
        ];

        return User::forceCreate(array_merge($defaults, $overrides));
    }

    protected function createEmployee(array $overrides = []): User
    {
        return $this->createUserWithRole('employee', $overrides);
    }

    protected function createDepartmentHead(array $overrides = []): User
    {
        return $this->createUserWithRole('Department Head', $overrides);
    }

    protected function createAdminOfficer(array $overrides = []): User
    {
        return $this->createUserWithRole('Administrative Officer', $overrides);
    }

    protected function createHRManager(array $overrides = []): User
    {
        return $this->createUserWithRole('HR Manager', $overrides);
    }

    protected function createMayor(array $overrides = []): User
    {
        return $this->createUserWithRole('Mayor', $overrides);
    }

    protected function createLeaveManager(array $overrides = []): User
    {
        return $this->createUserWithRole('Leave Manager', $overrides);
    }

    protected function createRecordsManager(array $overrides = []): User
    {
        return $this->createUserWithRole('Records Manager', $overrides);
    }

    protected function createFrontDesk(array $overrides = []): User
    {
        return $this->createUserWithRole('Front Desk', $overrides);
    }

    protected function createPayrollManager(array $overrides = []): User
    {
        return $this->createUserWithRole('Payroll Manager', $overrides);
    }

    protected function createTimeKeeper(array $overrides = []): User
    {
        return $this->createUserWithRole('Time Keeper', $overrides);
    }

    /**
     * Create a leave balance record for an employee.
     */
    protected function createLeaveBalance(User $user, array $overrides = []): LeaveBalance
    {
        $defaults = [
            'VL'   => 15.000,
            'SL'   => 15.000,
            'WLNS' => 0.000,
            'SPL'  => 3.000,
            'CTO'  => 0.000,
            'SP'   => 0.000,
        ];

        // UserObserver auto-creates a leave balance on user creation,
        // so update the existing record instead of inserting a duplicate.
        return LeaveBalance::updateOrCreate(
            ['user_id' => $user->id],
            array_merge($defaults, $overrides)
        );
    }

    /**
     * Create N users with a given role for load testing.
     */
    protected function createBulkUsers(string $role, int $count, array $overrides = []): array
    {
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $users[] = $this->createUserWithRole($role, $overrides);
        }
        return $users;
    }
}
