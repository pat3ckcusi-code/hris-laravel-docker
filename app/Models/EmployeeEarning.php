<?php

namespace App\Models;

use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $earnings_id
 * @property float $amount
 * @property bool $recurring
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $employee
 * @property-read Earning|null $earning
 *
 * @mixin Builder
 */
class EmployeeEarning extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'earnings_id',
        'amount',
        'amount_type',
        'percentage',
        'recurring',
    ];

    protected function casts(): array
    {
        return [
            'amount' => EncryptedDecimal::class,
            'recurring'  => 'boolean',
            'percentage' => 'float',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function earning()
    {
        return $this->belongsTo(Earning::class, 'earnings_id');
    }
}
