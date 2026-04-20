<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $EmpNo
 * @property float|null $VL
 * @property float|null $SL
 * @property float|null $WLNS
 * @property float|null $SPL
 * @property float|null $CTO
 * @property float|null $SP
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class LeaveBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'EmpNo',
        'VL',
        'SL',
        'WLNS',
        'SPL',
        'CTO',
        'SP',
    ];

    public function user()
    {
        return $this->hasOne(User::class, 'EmpNo', 'EmpNo');
    }
}
