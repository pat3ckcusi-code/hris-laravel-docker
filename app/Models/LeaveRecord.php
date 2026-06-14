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
 * @property string|null $type
 * @property bool $lwop_flag
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $employee
 *
 * @mixin Builder
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
