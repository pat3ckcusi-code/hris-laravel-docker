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
 * @property string|null $deduction_category
 * @property string|null $deduction_type
 * @property string|null $provider
 * @property string|null $mandatory_key
 * @property string|null $computation_type
 * @property array|null $mandatory_config
 * @property bool $is_active
 * @property array|null $eligible_employee_types
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
        'provider',
        'mandatory_key',
        'computation_type',
        'mandatory_config',
        'is_active',
        'eligible_employee_types',
        'description',
        'formula',
    ];

    protected $casts = [
        'mandatory_config' => 'array',
        'is_active' => 'boolean',
        'eligible_employee_types' => 'array',
    ];

    public function employeeDeductions()
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    public function loans()
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * Whether this row can have a Rate Configuration (computation_type/
     * mandatory_config) at all - the 4 system mandatory rows always can;
     * an "other" row can too, even while still individually-assigned
     * (computation_type null), since opening Rate Configuration is how it
     * switches into Standing Rate mode in the first place. Loan rows and
     * plain uncategorized rows never support this.
     */
    public function supportsRateConfiguration(): bool
    {
        return $this->mandatory_key !== null || $this->deduction_category === 'other';
    }

    /**
     * Whether this row is currently auto-computed for every eligible
     * employee (a system mandatory row, or an "other" row switched into
     * Standing Rate mode) rather than relying on per-employee
     * EmployeeDeduction rows. See "Let 'Other' deduction types use a
     * standing per-type rate, like Mandatory".
     */
    public function isAutoComputed(): bool
    {
        return $this->mandatory_key !== null || ($this->deduction_category === 'other' && $this->computation_type !== null);
    }

    /**
     * The BIR row is no longer bracket-computed at all - Accounting computes
     * withholding tax themselves and it's uploaded monthly (see
     * WithholdingTaxController). Its Rate Configuration/Assign Employee
     * Types/Deactivate UI are all irrelevant now; this row's show page shows
     * a link to the Withholding Tax Table instead. See "Replace computed BIR
     * withholding tax with an Accounting-uploaded monthly table".
     */
    public function isWithholdingTax(): bool
    {
        return $this->mandatory_key === 'bir';
    }
}
