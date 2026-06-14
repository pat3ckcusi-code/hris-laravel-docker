<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $emp_no
 * @property Carbon $logdate
 * @property string $logtime
 * @property string|null $logtype
 * @property string|null $text
 * @property string|null $device_name
 * @property string|null $in_out
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @mixin Builder
 */
class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'emp_no',
        'logdate',
        'logtime',
        'logtype',
        'text',
        'device_name',
        'in_out',
    ];

    protected function casts(): array
    {
        return [
            'logdate' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
