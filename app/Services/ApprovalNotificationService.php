<?php

namespace App\Services;

use App\Models\HRAuditTrail;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use Illuminate\Support\Facades\Log;

/**
 * Handles the two side-effects common to every approval/rejection action:
 *   1. Writing an HRAuditTrail record
 *   2. Dispatching an HrisTransactionNotification to the affected employee
 *
 * Centralising these removes the repeated try-catch boilerplate from
 * DepartmentHeadController and AdministrativeOfficerController.
 */
class ApprovalNotificationService
{
    /**
     * Persist an audit trail entry, swallowing and logging any DB exception
     * so a trail write failure never blocks the approval response.
     *
     * @param array{
     *   actor_user_id: int|null,
     *   module: string,
     *   action: string,
     *   target_type: string,
     *   target_id: int|null,
     *   details: array<string, mixed>,
     * } $payload
     */
    public function writeAuditTrail(array $payload): void
    {
        try {
            HRAuditTrail::create($payload);
        } catch (\Exception $ex) {
            Log::error('Failed to write HRAuditTrail', [
                'module' => $payload['module'] ?? null,
                'action' => $payload['action'] ?? null,
                'target_id' => $payload['target_id'] ?? null,
                'error' => $ex->getMessage(),
            ]);
        }
    }

    /**
     * Dispatch a transaction notification to an employee.
     * Skips silently (with a warning log) when the employee has no email.
     * Logs and swallows exceptions so a notification failure never blocks the
     * approval response.
     *
     * @param  array<string, string>  $details
     */
    public function notifyEmployee(
        User $employee,
        string $requestType,
        string $status,
        array $details,
        ?string $actor = null,
    ): void {
        $email = $employee->email ?? null;

        if (empty($email)) {
            Log::warning('Approval notification skipped: employee has no email', [
                'user_id' => $employee->id,
                'request_type' => $requestType,
                'status' => $status,
            ]);

            return;
        }

        try {
            $employee->notify(new HrisTransactionNotification(
                requestType: $requestType,
                status: $status,
                details: $details,
                actor: $actor,
            ));
        } catch (\Exception $ex) {
            Log::error('Failed to send approval notification', [
                'user_id' => $employee->id,
                'request_type' => $requestType,
                'status' => $status,
                'error' => $ex->getMessage(),
            ]);
        }
    }
}
