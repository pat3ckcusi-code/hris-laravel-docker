<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DtrExcuse extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'excuse_type',
        'is_full_day',
        'excuse_am_in',
        'excuse_am_out',
        'excuse_pm_in',
        'excuse_pm_out',
        'reason',
        'filed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_full_day' => 'boolean',
            'excuse_am_in' => 'boolean',
            'excuse_am_out' => 'boolean',
            'excuse_pm_in' => 'boolean',
            'excuse_pm_out' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by_user_id');
    }
}
