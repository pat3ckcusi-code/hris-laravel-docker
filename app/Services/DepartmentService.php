<?php

namespace App\Services;

use App\Models\Department;
use App\Models\OicAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DepartmentService
{
    /**
     * Return the department's current department head, but only if that user
     * still holds the department head role. Prevents a stale FK (left behind by
     * a role change) from silently misdirecting a notification.
     */
    public function getDepartmentHeadUser(Department $dept): ?User
    {
        $user = $dept->departmentHead;
        if (! $user || $this->normalizeRole($user->access_level) !== 'department head') {
            if ($user) {
                Log::warning('Stale department head reference ignored', ['dept_id' => $dept->Dept_id, 'user_id' => $user->id]);
            }

            return null;
        }

        return $user;
    }

    /**
     * Return the department's current administrative officer, but only if that
     * user still holds the administrative officer role.
     */
    public function getAdminOfficerUser(Department $dept): ?User
    {
        $user = $dept->adminOfficer;
        if (! $user || $this->normalizeRole($user->access_level) !== 'administrative officer') {
            if ($user) {
                Log::warning('Stale admin officer reference ignored', ['dept_id' => $dept->Dept_id, 'user_id' => $user->id]);
            }

            return null;
        }

        return $user;
    }

    private function normalizeRole(?string $role): string
    {
        return strtolower(trim(str_replace(['-', '_'], ' ', (string) $role)));
    }

    /**
     * Resolve a Department model for the given user.
     */
    public function resolveDepartmentForUser(User $user): ?Department
    {
        if (! empty($user->Dept_id)) {
            $dept = Department::where('Dept_id', $user->Dept_id)->first();
            if ($dept) {
                return $dept;
            }
        }

        return Department::where('EmpNo', $user->EmpNo)->first();
    }

    /**
     * Return employee ids for a department.
     */
    public function getEmployeeIdsForDepartment(?Department $dept): array
    {
        if (! $dept) {
            return [];
        }

        return User::active()->where('Dept_id', $dept->Dept_id)->pluck('id')->toArray();
    }

    /**
     * Return all departments the given user heads (matched by EmpNo, with Dept_id fallback).
     * Also includes departments from active OIC assignments with role 'department head'.
     */
    public function resolveAllDepartmentsForUser(User $user): Collection
    {
        $depts = collect();

        if (! empty($user->EmpNo)) {
            $depts = Department::where('EmpNo', $user->EmpNo)->get();
        }

        if (! empty($user->Dept_id) && $depts->where('Dept_id', $user->Dept_id)->isEmpty()) {
            $primary = Department::where('Dept_id', $user->Dept_id)->first();
            if ($primary) {
                $depts->push($primary);
            }
        }

        $oicDeptIds = $this->getActiveOicAssignments($user)
            ->where('role', 'department head')
            ->pluck('dept_id');

        if ($oicDeptIds->isNotEmpty()) {
            $oicDepts = Department::whereIn('Dept_id', $oicDeptIds)->get();
            foreach ($oicDepts as $oicDept) {
                if ($depts->where('Dept_id', $oicDept->Dept_id)->isEmpty()) {
                    $depts->push($oicDept);
                }
            }
        }

        return $depts;
    }

    /**
     * Return all departments the given administrative officer serves (matched by ao_emp_no, with Dept_id fallback).
     * Also includes departments from active OIC assignments with role 'administrative officer'.
     */
    public function resolveAllDepartmentsForAdminOfficer(User $user): Collection
    {
        $depts = collect();

        if (! empty($user->EmpNo)) {
            $depts = Department::where('ao_emp_no', $user->EmpNo)->get();
        }

        if (! empty($user->Dept_id) && $depts->where('Dept_id', $user->Dept_id)->isEmpty()) {
            $primary = Department::where('Dept_id', $user->Dept_id)->first();
            if ($primary) {
                $depts->push($primary);
            }
        }

        $oicDeptIds = $this->getActiveOicAssignments($user)
            ->where('role', 'administrative officer')
            ->pluck('dept_id');

        if ($oicDeptIds->isNotEmpty()) {
            $oicDepts = Department::whereIn('Dept_id', $oicDeptIds)->get();
            foreach ($oicDepts as $oicDept) {
                if ($depts->where('Dept_id', $oicDept->Dept_id)->isEmpty()) {
                    $depts->push($oicDept);
                }
            }
        }

        return $depts;
    }

    /**
     * Return active OIC assignments for the given user (today falls within start/end range).
     */
    public function getActiveOicAssignments(User $user): Collection
    {
        return OicAssignment::where('user_id', $user->id)->active()->get();
    }

    /**
     * Return the effective role label for audit logging.
     * If the user has an active OIC assignment, appends "(oic)" to the OIC role.
     * Otherwise returns the user's access_level.
     */
    public function getEffectiveRole(User $user): string
    {
        $oic = OicAssignment::where('user_id', $user->id)->active()->first();
        if ($oic) {
            return $oic->role.' (oic)';
        }

        return strtolower(trim((string) ($user->access_level ?? '')));
    }

    /**
     * Return employee ids across a collection of departments.
     */
    public function getEmployeeIdsForDepartments(Collection $depts): array
    {
        if ($depts->isEmpty()) {
            return [];
        }

        return User::active()->whereIn('Dept_id', $depts->pluck('Dept_id')->toArray())->pluck('id')->toArray();
    }
}
