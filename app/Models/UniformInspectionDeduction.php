<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UniformInspectionDeduction extends Model
{
    public const STATUS_DEDUCTED = 'deducted';

    public const STATUS_SKIPPED = 'skipped_insufficient';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'uniform_inspection_id',
        'employee_id',
        'status',
        'deducted_days',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'deducted_days' => 'float',
        ];
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
