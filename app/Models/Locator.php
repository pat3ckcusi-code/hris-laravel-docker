<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

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
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
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
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
