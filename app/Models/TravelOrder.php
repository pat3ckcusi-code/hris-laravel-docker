<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $travel_order_num
 * @property string|null $purpose
 * @property string|null $destination
 * @property string|null $per_diem
 * @property string|null $appropriation
 * @property string|null $report_to
 * @property Carbon|null $date_of_last_travel
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property string|null $Remarks
 * @property string|null $recommender
 * @property int|null $created_by
 * @property string $status
 * @property string|null $rejection_note
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $cancellation_reason
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder
 */
class TravelOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'travel_order_num',
        'purpose',
        'destination',
        'per_diem',
        'appropriation',
        'report_to',
        'date_of_last_travel',
        'start_date',
        'end_date',
        'Remarks',
        'recommender',
        'created_by',
        'status',
        'rejection_note',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'cancellation_reason',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'date_of_last_travel' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];
}
