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
            ->whereNull('end_date')
            ->with('plantilla')
            ->first();

        if (! $active) {
            return;
        }

        $active->update(['end_date' => now()->toDateString()]);

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
}
