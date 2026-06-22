<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $type
 * @property string|null $description
 * @property string|null $formula
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, EmployeeDeduction> $employeeDeductions
 * @property-read Collection<int, Loan> $loans
 *
 * @mixin Builder
 */
class Deduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'deduction_category',
        'deduction_type',
        'description',
        'formula',
    ];

    public function employeeDeductions()
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }
}
