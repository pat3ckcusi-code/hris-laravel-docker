<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employee_id
 * @property \Illuminate\Support\Carbon|null $date
 * @property string|null $type
 * @property bool $lwop_flag
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $employee
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class LeaveRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'date',
        'type',
        'lwop_flag',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'lwop_flag' => 'boolean',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
