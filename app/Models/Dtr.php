<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property Carbon|null $date
 * @property string|null $time_in_am
 * @property string|null $time_out_am
 * @property string|null $time_in_pm
 * @property string|null $time_out_pm
 * @property string|null $status
 * @property int $late_minutes
 * @property int $undertime_minutes
 * @property float|null $hours_worked decimal hours between matched in/out pairs; null until recomputed
 * @property array|null $unmatched_logs punches no expected event claimed, as H:i:s strings on the shift
 *                                      date (a cross-midnight shift's post-midnight punch belongs to the
 *                                      following calendar day)
 * @property bool $is_absent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $employee
 *
 * @mixin Builder
 */
class Dtr extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'date',
        'time_in_am',
        'time_out_am',
        'time_in_pm',
        'time_out_pm',
        'status',
        'source',
        'late_minutes',
        'undertime_minutes',
        'hours_worked',
        'unmatched_logs',
        'is_absent',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_absent' => 'boolean',
            'hours_worked' => 'float',
            'unmatched_logs' => 'array',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
