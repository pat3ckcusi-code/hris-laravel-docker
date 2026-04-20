<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $sg
 * @property int $step
 * @property int $year
 * @property float $amount
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
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
}
