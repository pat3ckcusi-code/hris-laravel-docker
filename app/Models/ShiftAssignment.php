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
 */
class ShiftAssignment extends Model
{
    protected $fillable = ['user_id', 'shift_id', 'days_of_week', 'effective_from', 'effective_until', 'created_by'];

    protected function casts(): array
    {
        return [
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

    /** True when this row applies to $date's day-of-week (null days_of_week = every day). */
    public function appliesOnDate(Carbon $date): bool
    {
        return $this->days_of_week === null || in_array($date->dayOfWeek, $this->days_of_week, true);
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
