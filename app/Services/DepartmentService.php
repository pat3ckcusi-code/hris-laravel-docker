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

    /**
     * Return all departments the given user heads (matched by EmpNo, with Dept_id fallback).
     */
    public function resolveAllDepartmentsForUser(User $user): \Illuminate\Support\Collection
    {
        $depts = collect();

        if (!empty($user->EmpNo)) {
            $depts = Department::where('EmpNo', $user->EmpNo)->get();
        }

        if (!empty($user->Dept_id) && $depts->where('Dept_id', $user->Dept_id)->isEmpty()) {
            $primary = Department::where('Dept_id', $user->Dept_id)->first();
            if ($primary) {
                $depts->push($primary);
            }
        }

        return $depts;
    }

    /**
     * Return all departments the given administrative officer serves (matched by ao_emp_no, with Dept_id fallback).
     */
    public function resolveAllDepartmentsForAdminOfficer(User $user): \Illuminate\Support\Collection
    {
        $depts = collect();

        if (!empty($user->EmpNo)) {
            $depts = Department::where('ao_emp_no', $user->EmpNo)->get();
        }

        if (!empty($user->Dept_id) && $depts->where('Dept_id', $user->Dept_id)->isEmpty()) {
            $primary = Department::where('Dept_id', $user->Dept_id)->first();
            if ($primary) {
                $depts->push($primary);
            }
        }

        return $depts;
    }

    /**
     * Return employee ids across a collection of departments.
     */
    public function getEmployeeIdsForDepartments(\Illuminate\Support\Collection $depts): array
    {
        if ($depts->isEmpty()) {
            return [];
        }

        return User::whereIn('Dept_id', $depts->pluck('Dept_id')->toArray())->pluck('id')->toArray();
    }
}
