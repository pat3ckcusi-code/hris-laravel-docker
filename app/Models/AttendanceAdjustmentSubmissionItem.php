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
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(AttendanceAdjustmentSubmission::class, 'submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
