<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

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
