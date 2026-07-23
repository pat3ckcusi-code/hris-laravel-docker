<?php

namespace App\Console\Commands;

use App\Models\HRAuditTrail;
use App\Models\JobOrderAppointment;
use App\Models\User;
use App\Services\EmployeeAssignmentService;
use Illuminate\Console\Command;

/**
 * Flips an Active Job Orders employee to Inactive the day after their last
 * known JobOrderAppointment's period_until has passed, mirroring the manual
 * Status-change path in RecordsManagerController::update()/
 * HRManagerController::recordsUpdate() (same hr_audit_trails shape, same
 * EmployeeAssignmentService::endActiveAssignmentForStatusChange() call).
 * Stamps job_order_auto_deactivated_at so JobOrderAppointmentService can tell
 * this apart from a manually-set Inactive/Separated status and safely
 * auto-reactivate on renewal without ever overriding a manual decision.
 */
class DeactivateExpiredJobOrders extends Command
{
    protected $signature = 'job-order:deactivate-expired';

    protected $description = 'Set Status=Inactive for Job Orders employees whose appointment history has fully lapsed';

    public function handle(EmployeeAssignmentService $employeeAssignmentService): int
    {
        $today = now()->toDateString();
        $deactivated = 0;

        User::where('employee_type', 'Job Orders')
            ->active()
            ->whereHas('jobOrderAppointments')
            ->whereDoesntHave('jobOrderAppointments', fn ($q) => $q->where('period_until', '>=', $today))
            ->chunkById(200, function ($users) use (&$deactivated, $employeeAssignmentService) {
                foreach ($users as $user) {
                    $previousStatus = $user->Status;

                    $user->forceFill([
                        'Status' => User::STATUS_INACTIVE,
                        'job_order_auto_deactivated_at' => now(),
                    ])->save();

                    HRAuditTrail::create([
                        'actor_user_id' => null,
                        'module' => 'records',
                        'action' => 'status_changed',
                        'target_type' => User::class,
                        'target_id' => $user->id,
                        'details' => [
                            'previous_status' => $previousStatus,
                            'new_status' => User::STATUS_INACTIVE,
                            'employee_name' => $user->name,
                            'employee_no' => $user->EmpNo,
                            'reason' => 'job_order_appointment_expired',
                        ],
                    ]);

                    $employeeAssignmentService->endActiveAssignmentForStatusChange($user->id, User::STATUS_INACTIVE);

                    $deactivated++;
                }
            });

        $this->info("Deactivated {$deactivated} Job Orders employee(s) with a fully lapsed appointment.");

        return self::SUCCESS;
    }
}
