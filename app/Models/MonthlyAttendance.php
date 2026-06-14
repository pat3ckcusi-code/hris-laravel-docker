<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyAttendance extends Model
{
    protected $table = 'monthly_attendance';

    protected $fillable = [
        'user_id',
        'year',
        'month',
        'days_present',
        'abs_wop_days',
        'computed_vl',
        'computed_sl',
        'processed_at',
        'processed_by',
    ];

    protected $casts = [
        'days_present' => 'float',
        'abs_wop_days' => 'float',
        'computed_vl' => 'float',
        'computed_sl' => 'float',
        'processed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
