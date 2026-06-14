<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $title
 * @property int $salary_grade
 * @property int $step
 * @property string $employment_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, EmployeeAssignment> $assignments
 *
 * @mixin Builder
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
