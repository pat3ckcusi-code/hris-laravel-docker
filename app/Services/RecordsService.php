<?php

namespace App\Services;

use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Collection;

class RecordsService
{
    /**
     * @return array{0: Collection<int, User>, 1: Collection<int, Department>, 2: array{total:int, active:int, inactive:int}}
     */
    public function collections(): array
    {
        $employees = User::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('middle_name')
            ->get([
                'id',
                'name',
                'last_name',
                'first_name',
                'middle_name',
                'email',
                'EmpNo',
                'designation',
                'Dept_id',
                'Status',
                'employee_type',
                'access_level',
            ]);

        $employees->each(function (User $employee): void {
            if ($employee->last_name && $employee->first_name) {
                return;
            }

            $nameParts = $this->splitEmployeeName((string) $employee->name);
            $employee->setAttribute('last_name', $employee->last_name ?: $nameParts['last_name']);
            $employee->setAttribute('first_name', $employee->first_name ?: $nameParts['first_name']);
            $employee->setAttribute('middle_name', $employee->middle_name ?: $nameParts['middle_name']);
        });

        $departments = Department::query()
            ->orderBy('Dept_name')
            ->get(['Dept_id', 'Dept_name']);

        $statusSummary = [
            'total' => $employees->count(),
            'active' => $employees->where('Status', 'Active')->count(),
            'inactive' => $employees->where('Status', '!=', 'Active')->count(),
        ];

        return [$employees, $departments, $statusSummary];
    }

    public function getRecordsManagerData(): array
    {
        [$employees, $departments, $statusSummary] = $this->collections();
        $totalEmployees = max((int) $employees->count(), 1);

        $employeesByDepartment = $employees
            ->filter(fn (User $employee) => $employee->Dept_id !== null && $employee->Dept_id !== '')
            ->groupBy('Dept_id')
            ->map(function ($group, $deptId) use ($departments): array {
                $department = $departments->firstWhere('Dept_id', (int) $deptId);

                return [
                    'department' => (string) ($department->Dept_name ?? ('Department #'.$deptId)),
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();

        $topDepartments = $employeesByDepartment->take(5)->values();
        $largestDepartmentCount = (int) ($topDepartments->first()['count'] ?? 0);

        $accessDistribution = $employees
            ->groupBy(fn (User $employee) => $this->normalizeRole((string) ($employee->access_level ?: 'unassigned')))
            ->map(function ($group, $role) use ($totalEmployees): array {
                $count = $group->count();

                return [
                    'role' => ucwords((string) $role),
                    'count' => $count,
                    'percentage' => round(($count / $totalEmployees) * 100, 1),
                ];
            })
            ->sortByDesc('count')
            ->values();

        $employeeTypeDistribution = $employees
            ->filter(fn (User $employee) => $this->normalizeRole((string) $employee->access_level) === 'employee')
            ->groupBy(fn (User $employee) => (string) ($employee->employee_type ?: 'Unset'))
            ->map(function ($group, $type): array {
                return [
                    'type' => (string) $type,
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('count')
            ->values();

        $statusByGroup = [
            'Active' => $employees->where('Status', 'Active')->count(),
            'Inactive' => $employees->where('Status', 'Inactive')->count(),
            'Separated' => $employees->where('Status', 'Separated')->count(),
            'Unset' => $employees->filter(fn (User $employee) => ! in_array((string) $employee->Status, ['Active', 'Inactive', 'Separated'], true))->count(),
        ];

        $dataQuality = [
            'missing_emp_no' => $employees->filter(fn (User $employee) => trim((string) $employee->EmpNo) === '')->count(),
            'missing_designation' => $employees->filter(fn (User $employee) => trim((string) $employee->designation) === '')->count(),
            'missing_department' => $employees->filter(fn (User $employee) => $employee->Dept_id === null || $employee->Dept_id === '')->count(),
            'missing_employee_type' => $employees
                ->filter(fn (User $employee) => $this->normalizeRole((string) $employee->access_level) === 'employee')
                ->filter(fn (User $employee) => trim((string) $employee->employee_type) === '')
                ->count(),
        ];

        $completenessGaps = $employees->map(function (User $employee): int {
            $gaps = 0;

            if (trim((string) $employee->EmpNo) === '') {
                $gaps++;
            }

            if (trim((string) $employee->designation) === '') {
                $gaps++;
            }

            if ($employee->Dept_id === null || $employee->Dept_id === '') {
                $gaps++;
            }

            return $gaps;
        });

        $averageGapScore = round((float) $completenessGaps->avg(), 2);
        $employeesWithNoGaps = $completenessGaps->filter(fn (int $gap): bool => $gap === 0)->count();
        $profileCompletenessRate = round(($employeesWithNoGaps / $totalEmployees) * 100, 1);

        return [
            'employees' => $employees,
            'departments' => $departments,
            'statusSummary' => $statusSummary,
            'statusByGroup' => $statusByGroup,
            'topDepartments' => $topDepartments,
            'largestDepartmentCount' => $largestDepartmentCount,
            'accessDistribution' => $accessDistribution,
            'employeeTypeDistribution' => $employeeTypeDistribution,
            'dataQuality' => $dataQuality,
            'averageGapScore' => $averageGapScore,
            'profileCompletenessRate' => $profileCompletenessRate,
        ];
    }

    /**
     * @return array{last_name:string, first_name:string, middle_name:string}
     */
    private function splitEmployeeName(string $fullName): array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);

        if ($fullName === '') {
            return [
                'last_name' => '',
                'first_name' => '',
                'middle_name' => '',
            ];
        }

        if (str_contains($fullName, ',')) {
            [$lastName, $remainingName] = array_pad(array_map('trim', explode(',', $fullName, 2)), 2, '');
            $remainingParts = preg_split('/\s+/', $remainingName, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return [
                'last_name' => $lastName,
                'first_name' => $remainingParts[0] ?? '',
                'middle_name' => implode(' ', array_slice($remainingParts, 1)),
            ];
        }

        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) === 1) {
            return [
                'last_name' => '',
                'first_name' => $parts[0],
                'middle_name' => '',
            ];
        }

        $firstName = array_shift($parts) ?? '';
        $lastName = array_pop($parts) ?? '';

        return [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => implode(' ', $parts),
        ];
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }
}
