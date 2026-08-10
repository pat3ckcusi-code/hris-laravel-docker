<?php

namespace App\Models;

use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One employee's already-computed withholding tax for one calendar month,
 * uploaded by the Payroll Manager from a figure Accounting computed
 * themselves - see "Replace computed BIR withholding tax with an
 * Accounting-uploaded monthly table". Authoritative as-is: not gated by the
 * BIR Deduction row's is_active/eligible_employee_types, and not run through
 * PayrollComputationService's bracket engine at all.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $year
 * @property int $month
 * @property float $amount
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $employee
 * @property-read User|null $uploader
 *
 * @mixin Builder
 */
class WithholdingTax extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'year',
        'month',
        'amount',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => EncryptedDecimal::class,
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
