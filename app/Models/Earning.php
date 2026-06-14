<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string|null $description
 * @property bool $recurring
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, EmployeeEarning> $employeeEarnings
 *
 * @mixin Builder
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
