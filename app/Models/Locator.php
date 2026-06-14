<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $application_type
 * @property string|null $location
 * @property string|null $travel_date
 * @property string|null $intended_departure_time
 * @property string|null $intended_arrival_time
 * @property string|null $detail
 * @property string|null $actual_arrival_time
 * @property string $status
 * @property int|null $approved_by
 * @property string|null $approved_role
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @mixin Builder
 */
class Locator extends Model
{
    use HasFactory;

    protected $table = 'locators';

    protected $fillable = [
        'user_id',
        'application_type',
        'location',
        'travel_date',
        'intended_departure_time',
        'intended_arrival_time',
        'detail',
        'actual_arrival_time',
        'status',
        'cancelled_by',
        'cancelled_at',
        'cancellation_remarks',
    ];

    protected $casts = [
        'travel_date' => 'date',
        'intended_departure_time' => 'string',
        'intended_arrival_time' => 'string',
        'actual_arrival_time' => 'string',
        'cancelled_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
