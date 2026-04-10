<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
