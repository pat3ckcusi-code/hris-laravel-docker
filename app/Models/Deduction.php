<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string|null $description
 * @property string|null $formula
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmployeeDeduction> $employeeDeductions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Loan> $loans
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Deduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
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
