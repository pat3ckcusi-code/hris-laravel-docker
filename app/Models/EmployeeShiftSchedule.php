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
    protected $fillable = ['user_id', 'date', 'shift_id', 'type', 'created_by', 'is_rotation_generated', 'no_break'];

    protected $casts = [
        'date' => 'date',
        'is_rotation_generated' => 'boolean',
        'no_break' => 'boolean',
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
