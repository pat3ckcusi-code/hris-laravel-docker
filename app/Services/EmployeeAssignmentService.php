<?php

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\HRAuditTrail;
use App\Models\User;

class EmployeeAssignmentService
{
    /**
     * End the employee's active plantilla assignment (if any) and clear their
     * synced salary, in response to their Status changing to Inactive/Separated.
     * Reactivating back to Active never restores an assignment automatically -
     * that stays a manual re-assignment by HR.
     */
    public function endActiveAssignmentForStatusChange(int $employeeId, string $newStatus): void
    {
        $active = EmployeeAssignment::where('employee_id', $employeeId)
            ->notEnded()
            ->with('plantilla')
            ->first();

        if (! $active) {
            return;
        }

        if ($active->start_date->isFuture()) {
            // Hasn't taken effect yet - remove outright rather than leave an
            // inverted (end < start) range behind, mirroring promote()/store().
            $active->delete();
        } else {
            $active->update(['end_date' => now()->toDateString()]);
        }

        User::where('id', $employeeId)->update([
            'salary_grade' => null,
            'salary_step' => 1,
        ]);

        HRAuditTrail::create([
            'actor_user_id' => auth()->id(),
            'module' => 'payroll',
            'action' => 'assignment_ended',
            'target_type' => User::class,
            'target_id' => $employeeId,
            'details' => [
                'reason' => 'status_changed',
                'new_status' => $newStatus,
                'item_number' => $active->plantilla?->item_number,
                'title' => $active->plantilla?->title,
                'end_date' => $active->end_date?->toDateString(),
            ],
        ]);
    }

    /**
     * The salary_grade/salary_step values the employee's current assignment
     * (date-range containment, not just open-ended) implies right now - split
     * out from syncUserSalary() so a dry-run reconciliation can preview the
     * target values without writing them.
     */
    public function currentSalaryFor(int $employeeId): array
    {
        $current = EmployeeAssignment::where('employee_id', $employeeId)
            ->current()
            ->with('plantilla')
            ->orderByDesc('start_date')
            ->first();

        return [
            'salary_grade' => $current?->plantilla?->salary_grade,
            'salary_step' => $current?->step ?? 1,
        ];
    }

    /**
     * Keep the denormalized users.salary_grade/salary_step in step with the
     * employee's current plantilla assignment (or clear/reset them when none).
     * salary_grade follows the position (plantilla.salary_grade); salary_step
     * follows the assignment's own step, which is personal to this stint -
     * never the plantilla's shared, position-level step.
     */
    public function syncUserSalary(int $employeeId): void
    {
        $target = $this->currentSalaryFor($employeeId);

        User::where('id', $employeeId)->update([
            'salary_grade' => $target['salary_grade'],
            'salary_step' => $target['salary_step'],
        ]);
    }
}
