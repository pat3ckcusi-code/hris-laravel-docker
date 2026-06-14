<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
            'balance' => 'float',
            'monthly_payment' => 'float',
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
}
