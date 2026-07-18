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
 * @property string|null $item_number
 * @property string|null $department
 * @property int $salary_grade
 * @property int $step
 * @property string $employment_type
 * @property string|null $csc_eligibility
 * @property string|null $education
 * @property string|null $training
 * @property string|null $experience
 * @property string|null $competency
 * @property bool $is_historical
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, EmployeeAssignment> $assignments
 * @property-read Collection<int, EmployeeAssignment> $activeAssignments
 *
 * @mixin Builder
 */
class Plantilla extends Model
{
    use HasFactory;

    public const ELIGIBILITY_OPTIONS = [
        'professional' => 'Professional',
        'sub_professional' => 'Sub-Professional',
        'none' => 'No Required CSC',
    ];

    protected $fillable = [
        'title',
        'item_number',
        'department',
        'salary_grade',
        'step',
        'employment_type',
        'csc_eligibility',
        'education',
        'training',
        'experience',
        'competency',
        'is_historical',
    ];

    protected $casts = [
        'is_historical' => 'boolean',
    ];

    public function assignments()
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function activeAssignments()
    {
        return $this->hasMany(EmployeeAssignment::class)->whereNull('end_date');
    }
}
