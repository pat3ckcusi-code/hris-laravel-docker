<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named, reusable work-shift template. Assigned to employees via
 * users.shift_id. The four times map to the four CSC Form 48 slots
 * (in / break-out / break-in / out). A night shift has crosses_midnight = true
 * (time_out <= time_in), meaning it ends on the following calendar day.
 *
 * @property int $id
 * @property string $name
 * @property string $time_in
 * @property string $break_out
 * @property string $break_in
 * @property string $time_out
 * @property bool $crosses_midnight
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder
 */
class Shift extends Model
{
    protected $fillable = [
        'name',
        'time_in',
        'break_out',
        'break_in',
        'time_out',
        'crosses_midnight',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'crosses_midnight' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** A shift crosses midnight when it ends at or before it starts (HH:MM compare). */
    public static function isCrossMidnight(string $timeIn, string $timeOut): bool
    {
        return substr($timeOut, 0, 5) <= substr($timeIn, 0, 5);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(User::class, 'shift_id');
    }
}
