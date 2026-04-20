<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property \Illuminate\Support\Carbon|null $holiday_date
 * @property string|null $type
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $creator
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Holiday extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'holiday_date',
        'type',
        'created_by',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
