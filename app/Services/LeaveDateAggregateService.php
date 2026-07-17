<?php

namespace App\Services;

use App\Models\LeaveBalance;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Collection;

/**
 * Keeps a leave_requests parent row's aggregate columns (start_date/end_date/total_days/
 * paid_days/lwop_days/printing_deduction_details) consistent after a subset of its
 * leave_dates rows is cancelled or rescheduled, and provides the shared per-date,
 * is_lwop-gated balance refund used by partial cancellation and partial reschedule.
 */
class LeaveDateAggregateService
{
    /**
     * Credits balance for each non-LWOP date in $leaveDates, grouped by the date's own
     * leave_type. Does NOT mark dates cancelled or save $leaveBalance — callers own the
     * transaction and the leave_dates mutation.
     *
     * @return array<string, float> restored amounts keyed by balance column (VL/SL/WLNS/SP/SPL)
     */
    public function refundDates(Collection $leaveDates, ?LeaveBalance $leaveBalance): array
    {
        $restored = [];

        if (! $leaveBalance) {
            return $restored;
        }

        foreach ($leaveDates as $ld) {
            if ($ld->is_lwop) {
                continue;
            }

            $days = floatval($ld->days ?? 1.0);
            $column = $this->resolveBalanceColumn((string) ($ld->leave_type ?? ''));
            if (! $column) {
                continue;
            }

            $leaveBalance->{$column} = floatval($leaveBalance->{$column} ?? 0) + $days;
            $restored[$column] = ($restored[$column] ?? 0) + $days;
        }

        return $restored;
    }

    public function resolveBalanceColumn(string $leaveType): ?string
    {
        $type = strtolower($leaveType);

        if (str_contains($type, 'vacation') || str_contains($type, 'others')) {
            return 'VL';
        }
        if (str_contains($type, 'sick')) {
            return 'SL';
        }
        if (str_contains($type, 'wellness')) {
            return 'WLNS';
        }
        if (str_contains($type, 'solo')) {
            return 'SP';
        }
        if (str_contains($type, 'special') || str_contains($type, 'spl')) {
            return 'SPL';
        }

        return null;
    }

    /**
     * Recomputes the parent leave_requests row's aggregates from its remaining
     * (not-cancelled) leave_dates rows. If no dates remain, collapses the parent to the
     * same terminal state whole-row cancellation/reschedule already produce.
     */
    public function recomputeParentAfterDateChange(LeaveRequest $leave, string $terminalCancellationLabel = 'Cancelled'): void
    {
        $active = $leave->leaveDates()->where('is_cancelled', false)->orderBy('leave_date')->get();

        if ($active->isEmpty()) {
            $leave->status = 'cancelled';
            $leave->detailed_status = 'Cancelled';
            $leave->cancellation_status = $terminalCancellationLabel;
            $leave->save();

            return;
        }

        $leave->start_date = $active->first()->leave_date;
        $leave->end_date = $active->last()->leave_date;
        $leave->total_days = $active->sum('days');
        $leave->paid_days = $active->where('is_lwop', false)->sum('days');
        $leave->lwop_days = $active->where('is_lwop', true)->sum('days');

        $preview = [];
        foreach ($active->where('is_lwop', false)->groupBy('leave_type') as $type => $rows) {
            $column = $this->resolveBalanceColumn((string) $type);
            if ($column) {
                $preview[$column] = ($preview[$column] ?? 0) + $rows->sum('days');
            }
        }
        $leave->printing_deduction_details = json_encode($preview);
        $leave->save();
    }

    /**
     * Clears the per-date reschedule link created for $newLeaveId, and clears the
     * original's reschedule_status single-flight gate only if no other reschedule
     * (from a concurrent request) is still linked to it. Call from every entry point
     * that can reject a reschedule's new LeaveRequest.
     */
    public function unfreezeOriginalReschedule(int $newLeaveId, int $originalLeaveId): void
    {
        LeaveDate::where('rescheduled_to_leave_request_id', $newLeaveId)
            ->update(['rescheduled_to_leave_request_id' => null]);

        // rescheduled_to_leave_request_id stays set permanently on a date once its
        // reschedule is approved (it's the historical record of where that date went),
        // so its mere presence doesn't mean a reschedule is still awaiting approval.
        // Only an actually-pending linked leave should keep the single-flight gate locked.
        $stillPending = LeaveDate::where('leave_request_id', $originalLeaveId)
            ->whereNotNull('rescheduled_to_leave_request_id')
            ->whereHas('rescheduledTo', fn ($q) => $q->where('status', 'pending'))
            ->exists();

        if (! $stillPending) {
            LeaveRequest::where('id', $originalLeaveId)->update(['reschedule_status' => null]);
        }
    }
}
