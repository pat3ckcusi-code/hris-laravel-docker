<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalaryMatrix extends Model
{
    use HasFactory;

    protected $table = 'salary_matrices';

    protected $fillable = [
        'sg',
        'step',
        'year',
        'amount',
    ];
}
