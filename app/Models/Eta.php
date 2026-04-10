<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

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
