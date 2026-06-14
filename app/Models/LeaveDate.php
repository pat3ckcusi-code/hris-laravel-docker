<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $leave_request_id
 * @property string $leave_date
 * @property bool $is_cancelled
 * @property bool $is_lwop
 * @property int|null $cancelled_by
 * @property string|null $cancel_reason
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeaveRequest|null $leaveRequest
 *
 * @mixin Builder
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
