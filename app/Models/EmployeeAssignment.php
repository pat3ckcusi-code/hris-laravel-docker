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
}
