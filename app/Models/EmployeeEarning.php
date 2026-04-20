<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $earnings_id
 * @property float $amount
 * @property bool $recurring
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $employee
 * @property-read \App\Models\Earning|null $earning
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class EmployeeEarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'earnings_id',
        'amount',
        'recurring',
    ];

    protected function casts(): array
    {
        return [
            'recurring' => 'boolean',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function earning()
    {
        return $this->belongsTo(Earning::class, 'earnings_id');
    }
}
