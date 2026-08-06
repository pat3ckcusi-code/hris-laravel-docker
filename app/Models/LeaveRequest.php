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

    /**
     * A compact, gap-aware rendering of this leave's actual dates, e.g. "Aug 17, 19 & 21, 2026"
     * for non-consecutive individually-picked dates, or "Aug 17–21, 2026" for a genuinely
     * contiguous range (a plain start_date-end_date span misrepresents the former as the
     * latter, since individually-picked dates can have gaps start_date/end_date don't show).
     */
    public function formattedPeriod(): string
    {
        $dates = $this->relationLoaded('leaveDates')
            ? $this->leaveDates->where('is_cancelled', false)
            : $this->leaveDates()->where('is_cancelled', false)->get();

        if ($dates->isEmpty()) {
            if (! $this->start_date) {
                return '-';
            }
            $start = Carbon::parse($this->start_date);
            $end = $this->end_date ? Carbon::parse($this->end_date) : null;

            return ($end && ! $end->isSameDay($start))
                ? $start->format('M d, Y').' to '.$end->format('M d, Y')
                : $start->format('M d, Y');
        }

        $sorted = $dates->pluck('leave_date')
            ->map(fn ($d) => Carbon::parse($d))
            ->sort()
            ->values();

        // Collapse into consecutive-calendar-day runs.
        $runs = [];
        $runStart = $runEnd = $sorted->first();
        foreach ($sorted->slice(1) as $date) {
            if ($date->isSameDay($runEnd->copy()->addDay())) {
                $runEnd = $date;
            } else {
                $runs[] = [$runStart, $runEnd];
                $runStart = $runEnd = $date;
            }
        }
        $runs[] = [$runStart, $runEnd];

        $finalYear = $runs[count($runs) - 1][1]->year;
        $lastMonth = null;
        $parts = [];
        foreach ($runs as [$runStart, $runEnd]) {
            if ($runStart->isSameDay($runEnd)) {
                $part = ($runStart->month !== $lastMonth ? $runStart->format('M ') : '').$runStart->format('j');
                $lastMonth = $runStart->month;
            } elseif ($runStart->month === $runEnd->month) {
                $part = ($runStart->month !== $lastMonth ? $runStart->format('M ') : '').$runStart->format('j').'–'.$runEnd->format('j');
                $lastMonth = $runStart->month;
            } else {
                $part = $runStart->format('M j').'–'.$runEnd->format('M j');
                $lastMonth = $runEnd->month;
            }
            if ($runEnd->year !== $finalYear) {
                $part .= ', '.$runEnd->year;
            }
            $parts[] = $part;
        }

        $joined = count($parts) > 1
            ? implode(', ', array_slice($parts, 0, -1)).' & '.end($parts)
            : $parts[0];

        return $joined.', '.$finalYear;
    }

    /**
     * Dates with an active per-date cancellation request (see requestPartialCancellation).
     * Distinct from the whole-row cancellation_status column on this model, which stays
     * null for a partial cancellation - the employee's leave list needs this relation to
     * surface that state, since it isn't visible via cancellation_status alone.
     */
    public function pendingCancellationDates()
    {
        return $this->hasMany(LeaveDate::class)
            ->whereIn('cancellation_status', ['Pending Cancellation', 'DH Recommended', 'AO Endorsed']);
    }

    /**
     * The original leave_dates rows (belonging to a DIFFERENT LeaveRequest - the one
     * this leave was rescheduled from) that were replaced by this leave specifically.
     * More precise than reading rescheduledFrom's current start_date/end_date, which
     * gets recomputed as other reschedules/cancellations touch the same original and
     * so no longer reflects what THIS particular reschedule replaced once more than
     * one reschedule has come off the same original.
     */
    public function originalDatesReplaced()
    {
        return $this->hasMany(LeaveDate::class, 'rescheduled_to_leave_request_id');
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

    public function cancellationReviewedBy()
    {
        return $this->belongsTo(User::class, 'cancellation_reviewed_by');
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
