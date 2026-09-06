<?php

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\HRAuditTrail;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

    /**
     * Bulk-sync users.salary_grade/salary_step to whichever employee_assignments
     * row covers today, for every user with assignment history - one SQL
     * statement instead of a per-user loop. Shared by the daily
     * plantilla:sync-salary-cache command and CscPlantillaImportService's
     * post-import sync, so both stay on the same (correct) resolution -
     * salary_step from the assignment's own step, never the plantilla's
     * shared, position-level step (see syncUserSalary()'s docblock).
     */
    public function syncAllSalaryCaches(): int
    {
        return DB::update('
            UPDATE users u
            JOIN employee_assignments ea ON ea.employee_id = u.id
                AND ea.start_date <= CURDATE()
                AND (ea.end_date IS NULL OR ea.end_date >= CURDATE())
            JOIN plantillas p ON p.id = ea.plantilla_id
            SET u.salary_grade = p.salary_grade, u.salary_step = ea.step
            WHERE u.salary_grade IS NULL OR u.salary_grade != p.salary_grade
                OR u.salary_step IS NULL OR u.salary_step != ea.step
        ');
    }
}
