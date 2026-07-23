<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One fixed-term Job Order engagement period for an employee. Unlike
 * ShiftAssignment/EmployeeAssignment, a period_until is always known at
 * creation (a JO contract's end date is fixed up front), so rows are never
 * open-ended and never auto-superseded by a later one - see
 * JobOrderAppointmentService for why overlapping periods are rejected
 * outright rather than truncated.
 *
 * designation is snapshotted onto each row (what was true for that specific
 * appointment period), while the employee's department is read live via the
 * employee relation - see JobOrderAppointmentService/JobOrderRosterExportService.
 */
class JobOrderAppointment extends Model
{
    protected $fillable = [
        'user_id', 'designation', 'office', 'funding_source',
        'rate_per_day', 'rate_note', 'period_from', 'period_until',
        'remarks', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_until' => 'date',
            'rate_per_day' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** Rows whose fixed period covers today. */
    public function scopeCurrent(Builder $query): Builder
    {
        $today = Carbon::today()->toDateString();

        return $query->where('period_from', '<=', $today)->where('period_until', '>=', $today);
    }

    public function isCurrent(): bool
    {
        $today = Carbon::today();

        return $this->period_from->lte($today) && $this->period_until->gte($today);
    }

    /**
     * "400.00 w/SH" - single source of truth for how rate_per_day + rate_note
     * are displayed, reused by both the appointment history UI and the roster
     * export so the two can never disagree on formatting.
     */
    public function rateLabel(): string
    {
        $formatted = number_format((float) $this->rate_per_day, 2);

        return $this->rate_note ? "{$formatted} {$this->rate_note}" : $formatted;
    }
}
