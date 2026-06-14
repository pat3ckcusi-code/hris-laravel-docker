<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sg
 * @property int $step
 * @property int $year
 * @property float $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder
 */
class SalaryMatrix extends Model
{
    use HasFactory;

    protected $table = 'salary_matrices';

    protected $fillable = [
        'sg',
        'step',
        'year',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'sg' => 'integer',
            'step' => 'integer',
            'year' => 'integer',
            'amount' => 'float',
        ];
    }
}
