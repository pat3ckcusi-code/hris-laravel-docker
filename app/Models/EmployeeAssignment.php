<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property int|null $pds_id
 * @property int $plantilla_id
 * @property int $step Personal to this stint - distinct from plantilla.step, which is the position's own fixed catalog value
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $employee
 * @property-read Plantilla|null $plantilla
 * @property-read Pds|null $pds
 *
 * @mixin Builder
 */
class EmployeeAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'pds_id',
        'plantilla_id',
        'step',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function plantilla()
    {
        return $this->belongsTo(Plantilla::class);
    }

    public function pds()
    {
        return $this->belongsTo(Pds::class, 'pds_id');
    }

    /**
     * True when a later promote()/store() call fully swallowed this row
     * before it ever took effect: end_date got truncated to a date before
     * this row's own start_date, which only happens when start_date was
     * still in the future at truncation time. Callers displaying this row's
     * date range should check this first rather than show the raw,
     * backwards-looking pair - mirrors ShiftAssignment::isSuperseded().
     */
    public function isSuperseded(): bool
    {
        return $this->end_date !== null && $this->end_date->lt($this->start_date);
    }

    /**
     * Rows whose date range covers $onDate (default today) - open-ended
     * (end_date null) or fixed-term, both count as "in effect". A superseded
     * row (end_date < start_date) can never satisfy this: chaining
     * start_date <= $onDate <= end_date < start_date is a contradiction, so
     * no separate exclusion is needed.
     */
    public function scopeCurrent(Builder $query, ?string $onDate = null): Builder
    {
        $date = $onDate ?? Carbon::today()->toDateString();

        return $query->where('start_date', '<=', $date)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date));
    }

    /** Instance-side equivalent of scopeCurrent(), for already-loaded collections (Blade). */
    public function isCurrent(?Carbon $onDate = null): bool
    {
        $date = $onDate ?? Carbon::today();

        return $this->start_date !== null
            && $this->start_date->lte($date)
            && (! $this->end_date || $this->end_date->gte($date));
    }

    /**
     * Rows that haven't concluded as of $onDate (default today) - broader
     * than scopeCurrent(): also catches a not-yet-started assignment (future
     * start_date), since that's a real "the employee already has something
     * queued up" state a new assignment/promotion still needs to close out
     * or preempt. Excludes already-superseded (inverted end_date < start_date)
     * rows so a prior truncation is never re-touched by a later one.
     */
    public function scopeNotEnded(Builder $query, ?string $onDate = null): Builder
    {
        $date = $onDate ?? Carbon::today()->toDateString();

        return $query->where(function (Builder $q) use ($date) {
            $q->whereNull('end_date')
                ->orWhere(function (Builder $q2) use ($date) {
                    $q2->where('end_date', '>=', $date)->whereColumn('end_date', '>=', 'start_date');
                });
        });
    }
}
