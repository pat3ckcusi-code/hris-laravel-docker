<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $travel_order_num
 * @property string|null $purpose
 * @property string|null $destination
 * @property \Illuminate\Support\Carbon|null $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property string|null $Remarks
 * @property string|null $recommender
 * @property int|null $created_by
 * @property string $status
 * @property string|null $rejection_note
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property int|null $rejected_by
 * @property \Illuminate\Support\Carbon|null $rejected_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class TravelOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'travel_order_num',
        'purpose',
        'destination',
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
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];
}
