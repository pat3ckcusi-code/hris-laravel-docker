<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $leave_request_id
 * @property string $leave_date
 * @property string|null $leave_type
 * @property float $days
 * @property bool $is_cancelled
 * @property bool $is_lwop
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_status
 * @property string|null $cancellation_reason
 * @property string|null $cancellation_remarks
 * @property Carbon|null $cancellation_requested_at
 * @property int|null $cancellation_requested_by
 * @property Carbon|null $cancellation_reviewed_at
 * @property int|null $cancellation_reviewed_by
 * @property string|null $cancellation_dh_action
 * @property Carbon|null $cancellation_dh_at
 * @property int|null $cancellation_dh_by
 * @property string|null $cancellation_dh_remarks
 * @property string|null $cancellation_ao_action
 * @property Carbon|null $cancellation_ao_at
 * @property int|null $cancellation_ao_by
 * @property string|null $cancellation_ao_remarks
 * @property int|null $rescheduled_to_leave_request_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeaveRequest|null $leaveRequest
 * @property-read LeaveRequest|null $rescheduledTo
 *
 * @mixin Builder
 */
class LeaveDate extends Model
{
    use HasFactory;

    protected $table = 'leave_dates';

    protected $fillable = [
        'leave_request_id',
        'leave_date',
        'leave_type',
        'days',
        'is_cancelled',
        'is_lwop',
        'cancelled_by',
        'cancel_reason',
        'cancelled_at',
        'cancellation_status',
        'cancellation_reason',
        'cancellation_remarks',
        'cancellation_requested_at',
        'cancellation_requested_by',
        'cancellation_reviewed_at',
        'cancellation_reviewed_by',
        'cancellation_dh_action',
        'cancellation_dh_at',
        'cancellation_dh_by',
        'cancellation_dh_remarks',
        'cancellation_ao_action',
        'cancellation_ao_at',
        'cancellation_ao_by',
        'cancellation_ao_remarks',
        'rescheduled_to_leave_request_id',
    ];

    public function leaveRequest()
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function rescheduledTo()
    {
        return $this->belongsTo(LeaveRequest::class, 'rescheduled_to_leave_request_id');
    }

    public function cancellationDhBy()
    {
        return $this->belongsTo(User::class, 'cancellation_dh_by');
    }

    public function cancellationAoBy()
    {
        return $this->belongsTo(User::class, 'cancellation_ao_by');
    }

    public function cancellationReviewedBy()
    {
        return $this->belongsTo(User::class, 'cancellation_reviewed_by');
    }
}
