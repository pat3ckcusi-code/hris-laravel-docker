<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One month's billed balance/monthly payment for a Loan - a real, queryable
 * per-month snapshot written by LoanBillingImportService, distinct from
 * Loan's own current balance/monthly_payment (which always reflects the
 * latest billing only).
 *
 * @property int $id
 * @property int $loan_id
 * @property Carbon $billing_month
 * @property float $balance
 * @property float $monthly_payment
 * @property int|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Loan $loan
 * @property-read User|null $uploader
 *
 * @mixin Builder
 */
class LoanBillingHistory extends Model
{
    use HasFactory;

    protected $table = 'loan_billing_history';

    protected $fillable = [
        'loan_id',
        'billing_month',
        'balance',
        'monthly_payment',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'billing_month' => 'date',
            'balance' => 'float',
            'monthly_payment' => 'float',
        ];
    }

    public function loan()
    {
        return $this->belongsTo(Loan::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
