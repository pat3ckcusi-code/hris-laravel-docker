<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $leave_type
 * @property string|null $start_date
 * @property string|null $end_date
 * @property string|null $reason
 * @property string $status
 * @property string|null $detailed_status
 * @property string|null $rejection_notes
 * @property float|null $total_days
 * @property float|null $paid_days
 * @property float|null $lwop_days
 * @property string|null $date_filed
 * @property string|null $details_location
 * @property string|null $details_location_specify
 * @property string|null $details_sick_illness
 * @property string|null $details_sick_treatment
 * @property float|null $balance_vacation_leave
 * @property float|null $balance_sick_leave
 * @property float|null $balance_wellness_leave
 * @property float|null $balance_solo_parent_leave
 * @property float|null $balance_special_leave_privilege
 * @property int|null $approved_by
 * @property string|null $approved_role
 * @property Carbon|null $approved_at
 * @property string|null $remarks
 * @property string|null $action_remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, LeaveDate> $leaveDates
 *
 * @mixin Builder
 */
class LeaveRequest extends Model
{
    use HasFactory;

    public const VALID_DETAILED_STATUSES = [
        'For Recommendation',
        'Recommended',
        'Approved',
        'Final / Archived',
        'Disapproved',
        'Cancelled',
    ];

    protected $fillable = [
        'user_id',
        'leave_type',
        'start_date',
        'end_date',
        'cancellation_status',
        'cancellation_reason',
        'cancellation_remarks',
        'cancellation_requested_at',
        'cancellation_reviewed_at',
        'cancellation_requested_by',
        'cancellation_reviewed_by',
        'cancellation_dh_action',
        'cancellation_dh_at',
        'cancellation_dh_by',
        'cancellation_dh_remarks',
        'cancellation_ao_action',
        'cancellation_ao_at',
        'cancellation_ao_by',
        'cancellation_ao_remarks',
        'reason',
        'status',
        'detailed_status',
        'rejection_notes',
        'total_days',
        'paid_days',
        'lwop_days',
        'date_filed',
        'details_location',
        'details_location_specify',
        'details_sick_illness',
        'details_sick_treatment',
        'details_others_type',
        //  balances at time of filing (for auditing/printing)
        'balance_vacation_leave',
        'balance_sick_leave',
        'balance_wellness_leave',
        'balance_solo_parent_leave',
        'balance_special_leave_privilege',
        // printing control
        'printing_allowed',
        'printing_allowed_by',
        'printing_allowed_at',
        // printing deduction tracking
        'printing_deduction_applied',
        'printing_deduction_details',
        // print history tracking
        'print_count',
        'last_printed_at',
        'last_printed_by',
        // reschedule tracking
        'reschedule_status',
        'rescheduled_from_id',
    ];

    protected $casts = [
        'print_count' => 'integer',
        'last_printed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function leaveDates()
    {
        return $this->hasMany(LeaveDate::class);
    }

    public function lastPrintedBy()
    {
        return $this->belongsTo(User::class, 'last_printed_by');
    }

    public function rescheduledFrom()
    {
        return $this->belongsTo(LeaveRequest::class, 'rescheduled_from_id');
    }

    public function rescheduledLeaves()
    {
        return $this->hasMany(LeaveRequest::class, 'rescheduled_from_id');
    }

    public function cancellationDhBy()
    {
        return $this->belongsTo(User::class, 'cancellation_dh_by');
    }

    public function cancellationAoBy()
    {
        return $this->belongsTo(User::class, 'cancellation_ao_by');
    }

    public function approver()
    {
        return null;
    }

    protected static function booted(): void
    {
        static::saving(function (LeaveRequest $model) {
            if ($model->isDirty('detailed_status') && $model->detailed_status !== null) {
                if (! in_array($model->detailed_status, self::VALID_DETAILED_STATUSES, true)) {
                    throw new \InvalidArgumentException(
                        "Invalid detailed_status '{$model->detailed_status}'. Allowed: ".implode(', ', self::VALID_DETAILED_STATUSES)
                    );
                }
            }
        });
    }

    /**
     * Scope: only approved requests that have leave dates on a given date.
     */
    public function scopeApprovedOnDate($query, string $date)
    {
        return $query->where('status', 'approved')
            ->whereHas('leaveDates', function ($q) use ($date) {
                $q->whereDate('leave_date', $date)
                    ->where('is_cancelled', false);
            });
    }
}
