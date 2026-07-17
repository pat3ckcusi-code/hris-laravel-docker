<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceAdjustmentSubmissionItem extends Model
{
    protected $fillable = [
        'submission_id',
        'user_id',
        'month',
        'year',
        'emp_no',
        'name',
        'department',
        'position',
        'employee_type',
        'unfiled_count',
        'tardiness_count',
        'tardiness_minutes',
        'undertime_count',
        'undertime_minutes',
        'remarks',
        'processed_status',
        'processed_by',
        'processed_at',
        'deducted_days',
        'action_remarks',
    ];

    protected function casts(): array
    {
        return [
            'deducted_days' => 'float',
            'processed_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AttendanceAdjustmentSubmission::class, 'submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function scopePending($query)
    {
        return $query->where('processed_status', 'pending');
    }
}
