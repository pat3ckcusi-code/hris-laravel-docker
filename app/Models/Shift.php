<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, reusable work-shift template. Assigned to employees via
 * users.shift_id. The four times map to the four CSC Form 48 slots
 * (in / break-out / break-in / out). A night shift has crosses_midnight = true
 * (time_out <= time_in), meaning it ends on the following calendar day.
 *
 * A template is either global (is_global = true, visible/selectable by every
 * department, including ones created later) or scoped to the specific
 * departments attached via the departments() pivot.
 *
 * Work Days is NOT a template property - it's decided per employee/period
 * on the shift_assignments row (see App\Models\ShiftAssignment). No Break
 * (2-punch) has a template-level default (no_break) used only to pre-fill
 * the per-employee checkbox on the Shift Assignment / Shift Schedule
 * screens when this template is selected - the actual value used for DTR
 * resolution always comes from the shift_assignments/employee_shift_schedules
 * row, not this column, so the same template's clock times can still be
 * scheduled with or without a break differently per employee. punch_requirement
 * follows the identical "UI-default only" rule - it pre-fills the per-employee
 * Punch Requirement dropdown, but the real value always comes from
 * shift_assignments.punch_requirement / employee_shift_schedules.punch_requirement.
 *
 * is_field_work_pair is different in kind from the two UI-default columns
 * above - it IS authoritative, not a pre-fill. When true, this template
 * represents the whole Monday-check-in/Friday-check-out weekly pattern (see
 * App\Services\Attendance\WeeklyPunchPairReconciliationService), and
 * EmployeeScheduleController assigns it with a fixed, server-enforced
 * days_of_week=[1,5] + punch_requirement in_only/out_only split regardless
 * of what the assignment form submits - break_out/break_in are always null
 * and crosses_midnight is always false for a template flagged this way,
 * since neither concept applies across a weekly (not daily) span.
 *
 * @property int $id
 * @property string $name
 * @property string $time_in
 * @property string|null $break_out
 * @property string|null $break_in
 * @property string $time_out
 * @property bool $crosses_midnight
 * @property bool $is_active
 * @property bool $is_global
 * @property bool $no_break
 * @property string $punch_requirement
 * @property bool $is_field_work_pair
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder
 */
class Shift extends Model
{
    protected $fillable = [
        'name',
        'time_in',
        'break_out',
        'break_in',
        'time_out',
        'crosses_midnight',
        'is_active',
        'is_global',
        'no_break',
        'punch_requirement',
        'is_field_work_pair',
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'is_active' => 'boolean',
            'is_global' => 'boolean',
            'no_break' => 'boolean',
            'is_field_work_pair' => 'boolean',
        ];
    }

    /** Departments this (non-global) template is explicitly scoped to. */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'shift_department', 'shift_id', 'department_id', 'id', 'Dept_id');
    }

    /** Templates selectable by a user in any of the given department IDs: global templates, plus ones explicitly scoped to those departments. */
    public function scopeVisibleToDepartments(Builder $query, iterable $deptIds): Builder
    {
        $ids = collect($deptIds)->all();

        return $query->where(fn (Builder $q) => $q->where('is_global', true)
            ->orWhereHas('departments', fn (Builder $q2) => $q2->whereIn('departments.Dept_id', $ids)));
    }

    /** A shift crosses midnight when it ends at or before it starts (HH:MM compare). */
    public static function isCrossMidnight(string $timeIn, string $timeOut): bool
    {
        return substr($timeOut, 0, 5) <= substr($timeIn, 0, 5);
    }

    /**
     * A shift spans a genuine continuous 24 hours when it starts and ends at
     * the exact same clock time (e.g. a 24-on/24-off duty template). This is
     * the specific case that requires no_break=true on whatever assignment
     * uses it - see ShiftScheduleController's rotation generator, which
     * guards against this template shape being assigned without it.
     */
    public static function isFullDayCrossing(string $timeIn, string $timeOut): bool
    {
        return substr($timeIn, 0, 5) === substr($timeOut, 0, 5);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'shift_id');
    }
}
