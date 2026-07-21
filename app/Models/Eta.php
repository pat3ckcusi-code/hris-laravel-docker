<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $departure_date
 * @property string|null $arrival_date
 * @property string|null $destination
 * @property string|null $purpose
 * @property string|null $purpose_details
 * @property string $status
 * @property int|null $approved_by
 * @property string|null $approved_role
 * @property Carbon|null $approved_at
 * @property string|null $cancellation_status
 * @property string|null $cancellation_reason
 * @property string|null $cancellation_remarks
 * @property Carbon|null $cancellation_requested_at
 * @property Carbon|null $cancellation_reviewed_at
 * @property int|null $cancellation_requested_by
 * @property int|null $cancellation_reviewed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @mixin Builder
 */
class Eta extends Model
{
    use HasFactory;

    protected $table = 'eta';

    protected $fillable = [
        'user_id',
        'departure_date',
        'arrival_date',
        'destination',
        'purpose',
        'purpose_details',
        'status',
        'cancellation_status',
        'cancellation_reason',
        'cancellation_remarks',
        'cancellation_requested_at',
        'cancellation_reviewed_at',
        'cancellation_requested_by',
        'cancellation_reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'arrival_date' => 'date',
            'approved_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
            'cancellation_reviewed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancellationRequestedBy()
    {
        return $this->belongsTo(User::class, 'cancellation_requested_by');
    }

    public function cancellationReviewedBy()
    {
        return $this->belongsTo(User::class, 'cancellation_reviewed_by');
    }
}
