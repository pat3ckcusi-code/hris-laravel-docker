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
 * @property bool $is_abolished
 * @property Carbon|null $abolished_at
 * @property int|null $abolished_by
 * @property string|null $abolished_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, EmployeeAssignment> $assignments
 * @property-read Collection<int, EmployeeAssignment> $activeAssignments
 * @property-read User|null $abolishedBy
 *
 * @mixin Builder
 */
class Plantilla extends Model
{
    use HasFactory;

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
        'is_abolished',
        'abolished_at',
        'abolished_by',
        'abolished_reason',
    ];

    protected $casts = [
        'is_historical' => 'boolean',
        'is_abolished' => 'boolean',
        'abolished_at' => 'datetime',
    ];

    public function assignments()
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    public function activeAssignments()
    {
        return $this->hasMany(EmployeeAssignment::class)->current();
    }

    public function abolishedBy()
    {
        return $this->belongsTo(User::class, 'abolished_by');
    }

    /** [key => label] map for the CSC Eligibility dropdown/filter/label lookups - admin-managed via csc_eligibility_options. */
    public static function eligibilityOptions(): array
    {
        return CscEligibilityOption::orderBy('id')->pluck('label', 'key')->all();
    }
}
