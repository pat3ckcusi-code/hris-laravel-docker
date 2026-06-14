<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComputationTableLwp extends Model
{
    protected $table = 'computation_table_lwp';

    protected $primaryKey = 'days_present';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['days_present', 'credit_earned'];

    protected $casts = [
        'days_present' => 'integer',
        'credit_earned' => 'float',
    ];
}
