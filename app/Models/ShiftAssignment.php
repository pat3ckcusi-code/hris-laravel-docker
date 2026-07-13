<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row in an employee's shift-history timeline: shift_id applies from
 * effective_from through effective_until (null = open-ended/current).
 * Rows are never deleted once superseded, only truncated - see
 * ShiftAssignmentService for how overlapping rows are split on write.
 *
 * Two separate day-of-week arrays live on this row, with DELIBERATELY
 * OPPOSITE null semantics - do not conflate them:
 *   - days_of_week: which of this employee's possibly-several CONCURRENT
 *     rows governs a given date (e.g. an MWF row + a TTH row). Null means
 *     "no restriction" - this row applies to every date in its
 *     effective_from/until range regardless of weekday. See appliesOnDate().
 *     This is why WorkSchedule::forUserOnDate() can still resolve a
 *     voluntary Saturday punch against an employee's actual assigned shift
 *     even when that shift doesn't normally work Saturdays.
 *   - work_days: whether a given date is actually a SCHEDULED workday for
 *     this assignment (feeds WorkSchedule::isWorkday(), payroll/LWOP
 *     absence counting). Null means "defaults to Mon-Fri". See worksOnDate().
 * A date can therefore be "governed" by a row (days_of_week matches) while
 * NOT being a scheduled workday (work_days excludes it) - that's intentional,
 * not a bug.
 *
 * That said, the two fields can never DIVERGE in stored data: whenever
 * days_of_week is non-null, ShiftAssignmentService::assign() always forces
 * work_days to equal it. A day this row doesn't govern can never be "worked"
 * under it no matter what's submitted, so an independently-set work_days
 * pattern wider than days_of_week would be silently ignored for the days it
 * claims to add - see ShiftAssignmentService::assign() for where this is
 * enforced.
 */
class ShiftAssignment extends Model
{
    /** Mon-Fri, Carbon's dayOfWeek numbering (0=Sunday..6=Saturday). */
    public const DEFAULT_WORK_DAYS = [1, 2, 3, 4, 5];

    private const DAY_LABELS = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];

    protected $fillable = [
        'user_id', 'shift_id', 'days_of_week', 'work_days', 'no_break', 'effective_from', 'effective_until', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'no_break' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /**
     * Custom cast (rather than a plain 'array' cast) so every value is
     * normalized to actual ints on both read and write. Request input (HTML
     * checkboxes, query strings) always arrives as strings - without this,
     * appliesOnDate()'s strict in_array() comparison against Carbon's integer
     * dayOfWeek would silently never match anything submitted through a real
     * form, even though direct-PHP-array calls (e.g. in tests) look fine.
     */
    protected function daysOfWeek(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : array_map('intval', json_decode($value, true)),
            set: fn (?array $value) => $value === null ? null : json_encode(array_values(array_unique(array_map('intval', $value)))),
        );
    }

    /** Same normalization as daysOfWeek() above, and for the same reason. */
    protected function workDays(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : array_map('intval', json_decode($value, true)),
            set: fn (?array $value) => $value === null ? null : json_encode(array_values(array_unique(array_map('intval', $value)))),
        );
    }

    /**
     * True when this row GOVERNS $date - i.e. is the one to use out of this
     * employee's possibly-several concurrent rows (see class docblock). Null
     * days_of_week = every day. This is NOT "is $date a scheduled workday" -
     * see worksOnDate() for that.
     */
    public function appliesOnDate(Carbon $date): bool
    {
        return $this->days_of_week === null || in_array($date->dayOfWeek, $this->days_of_week, true);
    }

    /**
     * True when a later assign() call fully swallowed this row before it
     * ever took effect: ShiftAssignmentService::assign() never deletes a
     * future-dated row it truncates, it instead sets effective_until to
     * $newFrom - 1 day - which lands BEFORE this row's own effective_from
     * when the row hadn't started yet, producing a permanently unmatchable
     * (inverted) range on purpose, as a way to keep the row in the table
     * without it ever being able to resolve as effective again. Callers
     * displaying effective_from/until as a date range should check this
     * first rather than show the raw, backwards-looking pair.
     */
    public function isSuperseded(): bool
    {
        return $this->effective_until !== null && $this->effective_until->lt($this->effective_from);
    }

    /**
     * True when $date is a SCHEDULED workday under this assignment's own
     * Work Days pattern (null defaults to Mon-Fri). This is NOT "does this
     * row govern $date" - see appliesOnDate() for that. Only meaningful once
     * appliesOnDate() has already selected this as the governing row.
     */
    public function worksOnDayOfWeek(int $dayOfWeek): bool
    {
        return in_array($dayOfWeek, $this->work_days ?: self::DEFAULT_WORK_DAYS, true);
    }

    /** True when this assignment is scheduled to work on $date's calendar day-of-week. */
    public function worksOnDate(Carbon $date): bool
    {
        return $this->worksOnDayOfWeek($date->dayOfWeek);
    }

    /** Compact "Mon-Fri" / "Mon-Sat" / "Every day" / custom-list label for this row's own Work Days. */
    public function workDaysLabel(): string
    {
        return self::daysOfWeekLabel($this->work_days ?: self::DEFAULT_WORK_DAYS);
    }

    /**
     * Same compact label as workDaysLabel(), for any array of 0-6 day-of-week
     * values - used for both work_days and days_of_week arrays. Null (no
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

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /** Rows whose window covers $date (effective_from <= date <= effective_until, or effective_until is null). */
    public function scopeEffectiveOn(Builder $query, Carbon $date): Builder
    {
        $dateStr = $date->toDateString();

        return $query->where('effective_from', '<=', $dateStr)
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $dateStr));
    }
}
