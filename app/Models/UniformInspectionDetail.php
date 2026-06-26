<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UniformInspectionDetail extends Model
{
    public const VALID_VIOLATION_TYPES = [
        'No Uniform',
        'Incomplete Uniform',
        'Wrong Uniform',
        'Untidy/Improper Wearing',
    ];

    protected $fillable = [
        'uniform_inspection_id',
        'employee_id',
        'violation_type',
        'offense_number',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'offense_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (UniformInspectionDetail $model) {
            if (! in_array($model->violation_type, self::VALID_VIOLATION_TYPES, true)) {
                throw new \InvalidArgumentException(
                    "Invalid violation_type '{$model->violation_type}'."
                );
            }
        });
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(UniformInspection::class, 'uniform_inspection_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
