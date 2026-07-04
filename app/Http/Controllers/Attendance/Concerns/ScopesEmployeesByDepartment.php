<?php

namespace App\Http\Controllers\Attendance\Concerns;

use App\Models\ShiftManagementGrant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shared department-scoping logic for the Shift Management screens, usable by
 * Time Keeper/HR Manager (unrestricted) and Department Head/Administrative
 * Officer (restricted to their own department(s) - and only the ones with an
 * active ShiftManagementGrant; access is per-department, not per-officer, so
 * covering a department via OIC without a grant does not unlock it).
 *
 * Requires the consuming controller to inject DepartmentService as
 * $this->departmentService (constructor-promoted property).
 */
trait ScopesEmployeesByDepartment
{
    private const SCOPE_ADMIN_ROLES = ['time keeper', 'hr manager'];

    private const SCOPE_OFFICER_ROLES = ['administrative officer', 'department head'];

    /**
     * Resolve employee IDs accessible to the acting user.
     * Returns null for full admins (all employees), or an array of IDs for scoped officers.
     * Aborts 403 if the user holds none of the allowed roles, or has no department
     * with an active shift-management grant.
     */
    private function resolveAccessibleEmployeeIds(User $user): ?array
    {
        $role = strtolower(trim((string) ($user->access_level ?? '')));

        if (in_array($role, self::SCOPE_ADMIN_ROLES, true)) {
            return null;
        }

        if (! in_array($role, self::SCOPE_OFFICER_ROLES, true)) {
            abort(403);
        }

        $depts = $this->resolveAccessibleDepartments($user);

        abort_if(
            $depts->isEmpty(),
            403,
            'Shift management access has not been enabled for your department(s). Contact your Time Keeper.'
        );

        return User::whereIn('Dept_id', $depts->pluck('Dept_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->toArray();
    }

    /**
     * Resolve the Department models accessible to a scoped officer: the
     * department(s) they head (or cover via OIC), narrowed to only those with
     * an active ShiftManagementGrant.
     */
    private function resolveAccessibleDepartments(User $user): Collection
    {
        $roleNormalized = strtolower(str_replace(['-', '_'], ' ', trim((string) ($user->access_level ?? ''))));

        $depts = $roleNormalized === 'administrative officer'
            ? $this->departmentService->resolveAllDepartmentsForAdminOfficer($user)
            : $this->departmentService->resolveAllDepartmentsForUser($user);

        $grantedDeptIds = ShiftManagementGrant::active()
            ->whereIn('dept_id', $depts->pluck('Dept_id'))
            ->pluck('dept_id');

        return $depts->whereIn('Dept_id', $grantedDeptIds)->values();
    }

    /** True if the acting user is Time Keeper / HR Manager (full, unscoped access). */
    private function isUnscopedManager(User $user): bool
    {
        $role = strtolower(trim((string) ($user->access_level ?? '')));

        return in_array($role, self::SCOPE_ADMIN_ROLES, true);
    }
}
