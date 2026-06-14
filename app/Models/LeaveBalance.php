<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property float|null $VL
 * @property float|null $SL
 * @property float|null $WLNS
 * @property float|null $SPL
 * @property float|null $CTO
 * @property float|null $SP
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @mixin Builder
 */
class LeaveBalance extends Model
{
    use HasFactory;

    protected $table = 'leave_balances';

    protected $fillable = [
        'user_id',
        'VL',
        'SL',
        'WLNS',
        'SPL',
        'CTO',
        'SP',
    ];

    protected function casts(): array
    {
        return [
            'VL' => 'float',
            'SL' => 'float',
            'WLNS' => 'float',
            'SPL' => 'float',
            'CTO' => 'float',
            'SP' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
