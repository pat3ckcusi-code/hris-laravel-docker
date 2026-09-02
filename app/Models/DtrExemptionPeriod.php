<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DTR/biometric exemption window for one employee - the history table
 * behind the single-flag users.dtr_exempt/dtr_exempt_reason/
 * dtr_exempt_effective_date/dtr_exempt_until_date columns, which are now a
 * pure cache of "is an exemption active today" (kept in sync by
 * DtrExemptionService/dtr:sync-exemption-cache), mirroring how
 * users.shift_id caches ShiftAssignment history. A null until_date means
 * open-ended/ongoing, same convention as ShiftAssignment.effective_until.
 *
 * Sole write path is App\Services\DtrExemptionService - never write this
 * model directly.
 */
class DtrExemptionPeriod extends Model
{
    protected $fillable = [
        'user_id',
        'reason',
        'effective_date',
        'until_date',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'until_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCoveringDate(Builder $query, string $date): Builder
    {
        return $query->where('effective_date', '<=', $date)
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('until_date')->orWhere('until_date', '>=', $date);
            });
    }

    /**
     * Any period whose own window overlaps [$from, $to] at all - used to
     * preload the small candidate set a per-date coversDate() check then
     * narrows down, rather than querying per date.
     */
    public function scopeOverlappingRange(Builder $query, string $from, string $to): Builder
    {
        return $query->where('effective_date', '<=', $to)
            ->where(function (Builder $q) use ($from) {
                $q->whereNull('until_date')->orWhere('until_date', '>=', $from);
            });
    }

    public function coversDate(string $date): bool
    {
        return $this->effective_date->toDateString() <= $date
            && ($this->until_date === null || $this->until_date->toDateString() >= $date);
    }
}
