<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property Carbon|null $holiday_date
 * @property string|null $type
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 *
 * @mixin Builder
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
