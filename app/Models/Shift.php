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
 * Work Days and No Break (2-punch) are NOT template properties - they're
 * decided per employee/period on the shift_assignments row (see
 * App\Models\ShiftAssignment), so the same template's clock times can be
 * scheduled differently for different employees.
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
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'is_active' => 'boolean',
            'is_global' => 'boolean',
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

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'shift_id');
    }
}
