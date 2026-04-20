<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employee_id
 * @property \Illuminate\Support\Carbon|null $date
 * @property string|null $time_in_am
 * @property string|null $time_out_am
 * @property string|null $time_in_pm
 * @property string|null $time_out_pm
 * @property string|null $status
 * @property int $late_minutes
 * @property int $undertime_minutes
 * @property bool $is_absent
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $employee
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
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
        'late_minutes',
        'undertime_minutes',
        'is_absent',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_absent' => 'boolean',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
