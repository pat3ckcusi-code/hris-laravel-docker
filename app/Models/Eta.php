<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

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
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
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
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
