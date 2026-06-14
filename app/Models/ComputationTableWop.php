<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComputationTableWop extends Model
{
    protected $table = 'computation_table_wop';

    protected $primaryKey = 'abs_wop';

    public $incrementing = false;

    protected $keyType = 'float';

    public $timestamps = false;

    protected $fillable = ['abs_wop', 'vl_earned'];

    protected $casts = [
        'abs_wop' => 'float',
        'vl_earned' => 'float',
    ];
}
