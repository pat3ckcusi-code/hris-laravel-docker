<?php

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\PayrollDetail;
use App\Models\Pds;
use App\Models\SalaryMatrix;
use App\Models\User;

class DocumentPlaceholderResolver
{
    /**
     * Resolve the standard document-template placeholder values for an
     * employee. Keys are bare (no braces) to match this project's existing
     * `placeholder_styles` key convention; callers wrap them in `{token}`
     * form as needed for their own substitution mechanism.
     */
    public static function resolve(?User $employee): array
    {
        return [
            'employee_name' => $employee->name ?? 'Employee',
            'designation' => $employee->designation ?? 'Position',
            'employee_type' => $employee->employee_type ?? 'Permanent',
            'department' => $employee?->department?->Dept_name ?? '',
            'date' => now()->format('F d, Y'),
            'salary' => self::resolveSalary($employee),
            'honorific' => self::resolveHonorific($employee),
            'last_name' => $employee->last_name ?? '',
            'pronoun' => self::resolvePronoun($employee),
        ];
    }

    /**
     * "Mr."/"Ms." from the employee's own PDS Sex answer (`personal[sex]` in
     * the `pds-personal-info` section) - optional, employee-entered data, not
     * a `users` column. Falls back to the literal "Mr./Ms." when unset so the
     * gap is visible in the rendered document rather than silently blank.
     */
    private static function resolveHonorific(?User $employee): string
    {
        if (! $employee) {
            return 'Mr./Ms.';
        }

        return match (self::resolveSex($employee)) {
            'male' => 'Mr.',
            'female' => 'Ms.',
            default => 'Mr./Ms.',
        };
    }

    /**
     * "He"/"She" from the same PDS Sex answer as {honorific} - see that
     * method's docblock. Falls back to the literal "He/She" when unset, for
     * the same reason.
     */
    private static function resolvePronoun(?User $employee): string
    {
        if (! $employee) {
            return 'He/She';
        }

        return match (self::resolveSex($employee)) {
            'male' => 'He',
            'female' => 'She',
            default => 'He/She',
        };
    }

    private static function resolveSex(User $employee): string
    {
        $pds = Pds::where('user_id', $employee->id)->first();

        return strtolower(trim((string) ($pds?->getAllSectionData()['pds-personal-info']['personal[sex]'] ?? '')));
    }

    private static function resolveSalary(?User $employee): string
    {
        if (! $employee) {
            return 'N/A';
        }

        $amount = PayrollDetail::where('employee_id', $employee->id)->latest('id')->value('basic_salary');

        if ($amount === null) {
            // users.salary_grade/salary_step are only a mirror of the employee's
            // active plantilla assignment and can go stale (see
            // plantilla:backfill-salary-sync) - resolve from the canonical
            // source directly instead of trusting the cached columns.
            $assignment = EmployeeAssignment::where('employee_id', $employee->id)
                ->current()
                ->with('plantilla')
                ->latest('start_date')
                ->first();

            if ($assignment?->plantilla?->salary_grade) {
                $amount = SalaryMatrix::where('sg', $assignment->plantilla->salary_grade)
                    ->where('step', $assignment->step)
                    ->where('effective_date', '<=', now())
                    ->orderByDesc('effective_date')
                    ->value('amount');
            }
        }

        return $amount !== null ? '₱'.number_format((float) $amount, 2) : 'N/A';
    }
}
