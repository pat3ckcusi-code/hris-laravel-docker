<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;

class DepartmentService
{
    /**
     * Resolve a Department model for the given user.
     *
     * @param  \App\Models\User  $user
     * @return Department|null
     */
    public function resolveDepartmentForUser(User $user): ?Department
    {
        if (!empty($user->Dept_id)) {
            $dept = Department::where('Dept_id', $user->Dept_id)->first();
            if ($dept) {
                return $dept;
            }
        }

        return Department::where('EmpNo', $user->EmpNo)->first();
    }

    /**
     * Return employee ids for a department.
     *
     * @param  Department|null  $dept
     * @return array
     */
    public function getEmployeeIdsForDepartment(?Department $dept): array
    {
        if (!$dept) {
            return [];
        }

        return User::where('Dept_id', $dept->Dept_id)->pluck('id')->toArray();
    }
}
