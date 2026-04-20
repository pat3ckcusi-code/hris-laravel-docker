<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property int $salary_grade
 * @property int $step
 * @property string $employment_type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmployeeAssignment> $assignments
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
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
