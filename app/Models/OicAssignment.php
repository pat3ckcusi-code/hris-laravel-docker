<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OicAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'dept_id',
        'role',
        'appointed_by',
        'start_date',
        'end_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'dept_id', 'Dept_id');
    }

    public function appointedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appointed_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today);
    }
}
