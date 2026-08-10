<?php

namespace App\Models;

use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $deduction_id
 * @property float $balance
 * @property float $monthly_payment
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $employee
 * @property-read Deduction|null $deduction
 * @property-read Collection<int, LoanBillingHistory> $billingHistory
 * @property-read Collection<int, PayrollLoanDeduction> $payrollDeductions
 *
 * @mixin Builder
 */
class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'deduction_id',
        'balance',
        'monthly_payment',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'balance' => EncryptedDecimal::class,
            'monthly_payment' => EncryptedDecimal::class,
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function deduction()
    {
        return $this->belongsTo(Deduction::class);
    }

    public function billingHistory()
    {
        return $this->hasMany(LoanBillingHistory::class)->orderByDesc('billing_month');
    }

    public function payrollDeductions()
    {
        return $this->hasMany(PayrollLoanDeduction::class)->orderByDesc('created_at');
    }
}
