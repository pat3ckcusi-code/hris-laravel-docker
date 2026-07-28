<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $period
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string $status
 * @property array|null $eligible_employee_types
 * @property Carbon|null $locked_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 * @property-read Collection<int, PayrollDetail> $details
 * @property-read Collection<int, PayrollException> $exceptions
 * @property-read Collection<int, Payslip> $payslips
 * @property-read Collection<int, PayrollAuditLog> $auditLogs
 *
 * @mixin Builder
 */
class PayrollRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'period',
        'period_start',
        'period_end',
        'status',
        'eligible_employee_types',
        'locked_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'eligible_employee_types' => 'array',
            'locked_at' => 'datetime',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function details()
    {
        return $this->hasMany(PayrollDetail::class);
    }

    public function exceptions()
    {
        return $this->hasMany(PayrollException::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(PayrollAuditLog::class);
    }
}
