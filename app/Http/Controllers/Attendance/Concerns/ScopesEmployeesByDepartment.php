<?php

namespace App\Http\Controllers\Attendance\Concerns;

use App\Models\Shift;
use App\Models\ShiftManagementGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Shift query scoped to the templates the user may see/select: unfiltered
     * for Time Keeper/HR Manager, otherwise global templates plus ones
     * explicitly scoped to the user's granted department(s).
     */
    private function resolveVisibleShiftsQuery(User $user): Builder
    {
        if ($this->isUnscopedManager($user)) {
            return Shift::query();
        }

        return Shift::visibleToDepartments($this->resolveAccessibleDepartments($user)->pluck('Dept_id'));
    }

    /**
     * Write-path guard: aborts unless $shiftId is a template the acting user
     * may assign to an employee in department $deptId. Time Keeper/HR Manager
     * may assign any existing template; scoped officers are checked against
     * the target employee's own department (not the officer's full accessible
     * set), so a shift scoped to another department never lands on this
     * employee even under OIC coverage of both departments.
     */
    private function assertShiftAssignable(int $shiftId, ?int $deptId, User $actor): void
    {
        if ($this->isUnscopedManager($actor)) {
            abort_unless(Shift::whereKey($shiftId)->exists(), 422, 'Invalid shift.');

            return;
        }

        abort_unless(
            Shift::whereKey($shiftId)->visibleToDepartments([$deptId])->exists(),
            403,
            'That shift template is not available to your department.'
        );
    }
}
