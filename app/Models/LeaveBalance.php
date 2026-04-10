<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
