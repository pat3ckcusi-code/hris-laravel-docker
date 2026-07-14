<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-DATE override (rest day / field work / forced Standard Day / a
 * different shift for one specific date), layered on top of ShiftAssignment
 * history - see WorkSchedule for the full precedence chain.
 *
 * Known limitation: a one-off shift override here has no shift_assignments
 * row of its own, so it always resolves no_break = false for DTR purposes
 * (see WorkSchedule::forUserOnDate()) regardless of how that shift is
 * normally scheduled elsewhere. Deliberately out of scope - assigning a
 * no-break shift for one specific date is a narrow, rare case.
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
 */
class EmployeeShiftSchedule extends Model
{
    protected $fillable = ['user_id', 'date', 'shift_id', 'type', 'created_by', 'is_rotation_generated'];

    protected $casts = [
        'date' => 'date',
        'is_rotation_generated' => 'boolean',
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
