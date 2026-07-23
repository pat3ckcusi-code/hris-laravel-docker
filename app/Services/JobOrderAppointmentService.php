<?php

namespace App\Services;

use App\Models\HRAuditTrail;
use App\Models\JobOrderAppointment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sole write path for job_order_appointments. Unlike ShiftAssignment/
 * EmployeeAssignment history, a Job Order contract's end date is always
 * known up front, so there is never an "open" prior row for a new
 * appointment to implicitly supersede - overlapping periods are rejected
 * outright rather than truncated, and the employee must edit or end the
 * conflicting row first. The only implicit rewrite is the same-period_from
 * case, treated as a correction to the same appointment rather than a new
 * historical fact (mirrors ShiftAssignmentService's same-effective_from rule).
 *
 * Also owns the one place that reverses job-order:deactivate-expired: if a
 * renewal gives the employee coverage through today or later, and their
 * Status is Inactive *because that command set it*
 * (job_order_auto_deactivated_at is set), reactivate them. A manually-set
 * Inactive/Separated status is never touched - see maybeReactivateEmployee().
 */
class JobOrderAppointmentService
{
    public function create(User $user, array $data, ?int $createdBy): JobOrderAppointment
    {
        $appointment = DB::transaction(function () use ($user, $data, $createdBy) {
            $existing = JobOrderAppointment::forUser($user->id)
                ->where('period_from', $data['period_from'])
                ->first();

            if ($existing) {
                $this->assertNoOverlap($user->id, $data['period_from'], $data['period_until'], excludeId: $existing->id);
                $existing->update($data);

                return $existing->fresh();
            }

            $this->assertNoOverlap($user->id, $data['period_from'], $data['period_until']);

            return JobOrderAppointment::create($data + [
                'user_id' => $user->id,
                'created_by' => $createdBy,
            ]);
        });

        $this->maybeReactivateEmployee($user);

        return $appointment;
    }

    public function update(JobOrderAppointment $appointment, array $data): JobOrderAppointment
    {
        $appointment = DB::transaction(function () use ($appointment, $data) {
            $this->assertNoOverlap($appointment->user_id, $data['period_from'], $data['period_until'], excludeId: $appointment->id);
            $appointment->update($data);

            return $appointment->fresh();
        });

        $this->maybeReactivateEmployee($appointment->employee);

        return $appointment;
    }

    /**
     * Only fires when this exact command auto-deactivated the employee
     * (job_order_auto_deactivated_at set) - a manual Inactive/Separated
     * status, which never sets that column, is left alone.
     */
    private function maybeReactivateEmployee(User $user): void
    {
        if (! $user->isInactive() || ! $user->job_order_auto_deactivated_at) {
            return;
        }

        $hasCurrentOrFutureCoverage = JobOrderAppointment::forUser($user->id)
            ->where('period_until', '>=', now()->toDateString())
            ->exists();

        if (! $hasCurrentOrFutureCoverage) {
            return;
        }

        $previousStatus = $user->Status;

        $user->forceFill([
            'Status' => User::STATUS_ACTIVE,
            'job_order_auto_deactivated_at' => null,
        ])->save();

        HRAuditTrail::create([
            'actor_user_id' => auth()->id(),
            'module' => 'records',
            'action' => 'status_changed',
            'target_type' => User::class,
            'target_id' => $user->id,
            'details' => [
                'previous_status' => $previousStatus,
                'new_status' => User::STATUS_ACTIVE,
                'employee_name' => $user->name,
                'employee_no' => $user->EmpNo,
                'reason' => 'job_order_appointment_renewed',
            ],
        ]);
    }

    private function assertNoOverlap(int $userId, string $from, string $until, ?int $excludeId = null): void
    {
        $overlap = JobOrderAppointment::forUser($userId)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('period_from', '<=', $until)
            ->where('period_until', '>=', $from)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'period_from' => 'This period overlaps an existing appointment for this employee. Edit or end the existing appointment first.',
            ]);
        }
    }
}
