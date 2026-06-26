<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UniformInspection extends Model
{
    public const VALID_VIOLATION_TYPES = [
        'No Uniform',
        'Incomplete Uniform',
        'Wrong Uniform',
        'Untidy/Improper Wearing',
    ];

    protected $fillable = [
        'department_id',
        'inspection_date',
        'inspection_time',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (UniformInspection $model) {
            if (empty($model->inspection_date)) {
                throw new \InvalidArgumentException('inspection_date is required.');
            }
            if (empty($model->inspection_time)) {
                throw new \InvalidArgumentException('inspection_time is required.');
            }
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'Dept_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(UniformInspectionDetail::class);
    }
}
