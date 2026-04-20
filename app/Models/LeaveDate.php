<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $leave_request_id
 * @property string $leave_date
 * @property bool $is_cancelled
 * @property bool $is_lwop
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\LeaveRequest|null $leaveRequest
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class LeaveDate extends Model
{
    use HasFactory;

    protected $table = 'leave_dates';

    protected $fillable = [
        'leave_request_id',
        'leave_date',
        'is_cancelled',
        'is_lwop',
        'cancelled_by',
        'cancel_reason',
        'cancelled_at',
    ];

    public function leaveRequest()
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}
