<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceAdjustmentSubmission extends Model
{
    protected $fillable = [
        'submitted_by',
        'month',
        'year',
        'employee_type',
        'department_ids',
        'department_label',
        'item_count',
        'skipped_count',
        'status',
        'voided_at',
        'voided_by',
    ];

    protected $casts = [
        'department_ids' => 'array',
        'voided_at' => 'datetime',
    ];

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AttendanceAdjustmentSubmissionItem::class, 'submission_id');
    }
}
