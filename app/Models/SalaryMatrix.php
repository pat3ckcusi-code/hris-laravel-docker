<?php

namespace App\Models;

use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sg
 * @property int $step
 * @property int $year
 * @property Carbon $effective_date
 * @property string|null $ordinance_reference
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
        'effective_date',
        'ordinance_reference',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'sg' => 'integer',
            'step' => 'integer',
            'year' => 'integer',
            'effective_date' => 'date',
            'amount' => EncryptedDecimal::class,
        ];
    }

    /**
     * effective_date is the authoritative versioning key (it can fall
     * mid-year, unlike year); year is kept as a convenience/display field.
     * Whichever one a caller sets, derive the other so both always agree.
     */
    protected static function booted(): void
    {
        static::saving(function (self $matrix) {
            if (! $matrix->effective_date && $matrix->year) {
                $matrix->effective_date = Carbon::create((int) $matrix->year, 1, 1);
            } elseif ($matrix->effective_date && ! $matrix->year) {
                $matrix->year = $matrix->effective_date->year;
            }
        });
    }
}
