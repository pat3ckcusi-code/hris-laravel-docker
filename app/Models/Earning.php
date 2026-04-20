<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string|null $description
 * @property bool $recurring
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmployeeEarning> $employeeEarnings
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Earning extends Model
{
    use HasFactory;

    protected $table = 'earnings';

    protected $fillable = [
        'type',
        'description',
        'recurring',
    ];

    protected function casts(): array
    {
        return [
            'recurring' => 'boolean',
        ];
    }

    public function employeeEarnings()
    {
        return $this->hasMany(EmployeeEarning::class, 'earnings_id');
    }
}
