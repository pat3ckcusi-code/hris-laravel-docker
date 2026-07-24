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
}
