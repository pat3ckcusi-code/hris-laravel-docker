<?php

namespace App\Services;

use App\Models\HRAuditTrail;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HolidayLeaveCancellationService
{
    /**
     * Cancel all approved leave dates that fall on the given date and refund credits.
     *
     * @param  string  $date        The holiday date (Y-m-d)
     * @param  string  $reason      Reason for cancellation
     * @param  int|null $actorId    User performing the action (null = system/auto)
     * @return array   Summary: ['cancelled_count' => int, 'affected_employees' => int, 'details' => array]
     */
    public function cancelLeavesOnDate(string $date, string $reason, ?int $actorId = null): array
    {
        $leaveDates = LeaveDate::where('is_cancelled', false)
            ->whereDate('leave_date', $date)
            ->whereHas('leaveRequest', function ($q) {
                $q->where('status', 'approved');
            })
            ->with(['leaveRequest.user.leaveBalance'])
            ->get();

        if ($leaveDates->isEmpty()) {
            return ['cancelled_count' => 0, 'affected_employees' => 0, 'details' => []];
        }

        $details = [];
        $affectedEmployees = [];

        DB::beginTransaction();
        try {
            foreach ($leaveDates as $ld) {
                $leave = $ld->leaveRequest;
                $user = $leave->user ?? null;

                // Mark date as cancelled
                $ld->is_cancelled = true;
                $ld->cancel_reason = $reason;
                $ld->cancelled_by = $actorId;
                $ld->cancelled_at = now();
                $ld->save();

                // Refund 1 day to appropriate balance
                $refundedField = null;
                if ($user && $user->leaveBalance) {
                    $refundedField = $this->refundCredit($user->leaveBalance, $leave->leave_type);
                }

                // Check if all dates are now cancelled → update leave status
                $remainingActive = LeaveDate::where('leave_request_id', $leave->id)
                    ->where('is_cancelled', false)
                    ->count();

                if ($remainingActive === 0) {
                    $leave->status = 'cancelled';
                    $leave->detailed_status = 'Cancelled';
                    $leave->save();
                }

                // Track affected employee
                if ($user) {
                    $affectedEmployees[$user->id] = true;
                }

                $detail = [
                    'leave_request_id' => $leave->id,
                    'leave_date_id' => $ld->id,
                    'date' => $date,
                    'user_id' => $user->id ?? null,
                    'employee' => $user ? trim(($user->last_name ?? '') . ', ' . ($user->first_name ?? '')) : null,
                    'leave_type' => $leave->leave_type,
                    'refunded_field' => $refundedField,
                ];
                $details[] = $detail;

                // Audit log for each cancellation
                HRAuditTrail::create([
                    'actor_user_id' => $actorId,
                    'module' => 'Leave Management',
                    'action' => 'Holiday Leave Cancellation',
                    'target_type' => 'LeaveDate',
                    'target_id' => $ld->id,
                    'details' => $detail,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return [
            'cancelled_count' => count($details),
            'affected_employees' => count($affectedEmployees),
            'details' => $details,
        ];
    }

    /**
     * Refund 1 day to the appropriate leave balance field.
     *
     * @return string|null  The field that was credited, or null if no refund possible
     */
    private function refundCredit($leaveBalance, ?string $leaveType): ?string
    {
        $type = strtolower(trim((string) $leaveType));

        $mapping = [
            'sick' => 'SL',
            'sick leave' => 'SL',
            'vacation' => 'VL',
            'vacation leave' => 'VL',
            'vl' => 'VL',
            'wellness' => 'WLNS',
            'wellness leave' => 'WLNS',
            'special privilege' => 'SPL',
            'special previlage' => 'SPL',
            'special privilege leave' => 'SPL',
            'spl' => 'SPL',
            'sp' => 'SP',
            'solo parent' => 'SP',
            'solo parent leave' => 'SP',
            'cto' => 'CTO',
        ];

        $field = null;
        foreach ($mapping as $keyword => $f) {
            if (strpos($type, $keyword) !== false) {
                $field = $f;
                break;
            }
        }

        if (!$field) {
            $code = strtoupper(trim($type));
            $allowed = ['VL', 'SL', 'WLNS', 'SPL', 'SP', 'CTO'];
            if (in_array($code, $allowed, true)) {
                $field = $code;
            }
        }

        if (!$field) {
            $field = 'VL';
        }

        $current = (float) ($leaveBalance->{$field} ?? 0);
        $leaveBalance->{$field} = $current + 1.0;
        $leaveBalance->save();

        return $field;
    }
}
