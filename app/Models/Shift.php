<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

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
 * @property int $id
 * @property string $name
 * @property string $time_in
 * @property string|null $break_out
 * @property string|null $break_in
 * @property string $time_out
 * @property bool $crosses_midnight
 * @property bool $no_break
 * @property bool $is_active
 * @property bool $is_global
 * @property array $work_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder
 */
class Shift extends Model
{
    /** Mon-Fri, Carbon's dayOfWeek numbering (0=Sunday..6=Saturday). */
    public const DEFAULT_WORK_DAYS = [1, 2, 3, 4, 5];

    private const DAY_LABELS = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    protected $fillable = [
        'name',
        'time_in',
        'break_out',
        'break_in',
        'time_out',
        'crosses_midnight',
        'no_break',
        'is_active',
        'is_global',
        'work_days',
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'no_break' => 'boolean',
            'is_active' => 'boolean',
            'is_global' => 'boolean',
            'work_days' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $shift) {
            if (empty($shift->work_days)) {
                $shift->work_days = self::DEFAULT_WORK_DAYS;
            }
        });
    }

    /** True when this shift is scheduled to work on the given Carbon day-of-week (0=Sun..6=Sat). */
    public function worksOnDayOfWeek(int $dayOfWeek): bool
    {
        return in_array($dayOfWeek, $this->work_days ?: self::DEFAULT_WORK_DAYS, true);
    }

    /** True when this shift is scheduled to work on $date's calendar day-of-week. */
    public function worksOnDate(Carbon $date): bool
    {
        return $this->worksOnDayOfWeek($date->dayOfWeek);
    }

    /** Compact "Mon-Fri" / "Mon-Sat" / "Every day" / custom-list label for the templates table. */
    public function workDaysLabel(): string
    {
        return self::daysOfWeekLabel($this->work_days ?: self::DEFAULT_WORK_DAYS);
    }

    /**
     * Same compact label as workDaysLabel(), for any array of 0-6 day-of-week
     * values - e.g. a shift_assignments row's days_of_week scope. Null (no
     * restriction, applies every day) passes through as null so callers can
     * distinguish "unscoped" from "every day was explicitly listed."
     */
    public static function daysOfWeekLabel(?array $days): ?string
    {
        if ($days === null) {
            return null;
        }

        // Defensive int cast: request input (checkboxes, query strings)
        // always arrives as strings, and match() below compares strictly.
        $days = collect($days)->map(fn ($d) => (int) $d)->unique()->sort()->values()->all();

        return match ($days) {
            [1, 2, 3, 4, 5] => 'Mon-Fri',
            [1, 2, 3, 4, 5, 6] => 'Mon-Sat',
            [0, 1, 2, 3, 4, 5, 6] => 'Every day',
            default => collect($days)->map(fn ($d) => self::DAY_LABELS[$d])->implode(', '),
        };
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
