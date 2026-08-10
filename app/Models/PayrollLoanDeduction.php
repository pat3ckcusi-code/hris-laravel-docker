<?php

namespace App\Models;

use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A single loan-balance decrement applied by locking a PayrollRun - a real,
 * queryable per-run snapshot written by PayrollComputationService::applyLoanDeductions(),
 * distinct from LoanBillingHistory (which records external billing uploads, not payroll activity).
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $payroll_detail_id
 * @property int $loan_id
 * @property float $amount
 * @property float $balance_before
 * @property float $balance_after
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PayrollRun $payrollRun
 * @property-read PayrollDetail $payrollDetail
 * @property-read Loan $loan
 *
 * @mixin Builder
 */
class PayrollLoanDeduction extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'payroll_detail_id',
        'loan_id',
        'amount',
        'balance_before',
        'balance_after',
    ];

    protected function casts(): array
    {
        return [
            'amount' => EncryptedDecimal::class,
            'balance_before' => EncryptedDecimal::class,
            'balance_after' => EncryptedDecimal::class,
        ];
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function payrollDetail()
    {
        return $this->belongsTo(PayrollDetail::class);
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }
}
