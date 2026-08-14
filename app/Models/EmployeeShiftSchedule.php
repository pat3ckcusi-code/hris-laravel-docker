<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-DATE override (rest day / field work / forced Standard Day / a
 * different shift for one specific date), layered on top of ShiftAssignment
 * history - see WorkSchedule for the full precedence chain.
 *
 * no_break mirrors the same flag on shift_assignments, but only has any
 * effect on a row that also carries a shift_id - WorkSchedule::forUserOnDate()
 * only reads it in that branch, so a rest/field_work/standard row's no_break
 * value (always false, since nothing ever sets it) is simply never consulted.
 * punch_requirement mirrors the same field on shift_assignments, with the
 * identical restriction - only meaningful on a row that also carries a shift_id.
 *
 * type also has one value written only by code, never picked in the UI:
 * 'field_work_unconfirmed', written by
 * App\Services\Attendance\WeeklyPunchPairReconciliationService to retroactively
 * turn a normally-excluded field-work day into a real, absence-eligible
 * workday once a week's Monday/Friday punch pairing is confirmed incomplete -
 * see that class and WorkSchedule::isWorkday()/isRestDay() for how this type
 * is treated identically to 'field_work'/'wfh'/'standard' for workday
 * purposes, but carries no shift_id (there was never a real shift expected
 * that date, only a retroactive absence marker).
 *
 * is_rotation_generated marks a row written by
 * ShiftScheduleController::writeRotationForEmployee() as part of the same
 * action that also wrote the underlying ShiftAssignment - i.e. the rotation's
 * own intended rest day, not an independent edit that happens to collide with
 * it. WorkSchedule::resolutionSource()'s shadow-conflict warning and
 * EmployeeScheduleController::findConflictingOverrides() both skip these rows,
 * since flagging a rotation's own rest days as "conflicting" with the very
 * assignment it created would be misleading noise, not a real conflict. Any
 * manual edit through the Shift Schedule week-grid (applyWeekAssignments())
 * explicitly resets this back to false, since it's then a genuine deliberate
 * override again.
 *
 * is_reconciliation_generated is the analogous marker for
 * WeeklyPunchPairReconciliationService's own writes (both the
 * 'field_work_unconfirmed' rows and a mid-week 'in_only' check-in override) -
 * it's what lets that service tell its own prior output apart from a genuine
 * manual override on the same date (never touched/deleted) and safely
 * self-heal (delete its own stale rows) once a week's outcome changes on a
 * later run.
 */
class EmployeeShiftSchedule extends Model
{
    protected $fillable = ['user_id', 'date', 'shift_id', 'type', 'created_by', 'is_rotation_generated', 'no_break', 'punch_requirement', 'is_reconciliation_generated'];

    protected $casts = [
        'date' => 'date',
        'is_rotation_generated' => 'boolean',
        'no_break' => 'boolean',
        'is_reconciliation_generated' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
