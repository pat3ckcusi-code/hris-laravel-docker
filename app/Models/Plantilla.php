<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plantilla extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'salary_grade',
        'step',
        'employment_type',
    ];

    public function assignments()
    {
        return $this->hasMany(EmployeeAssignment::class);
    }
}
