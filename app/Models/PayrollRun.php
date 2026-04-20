<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $period
 * @property \Illuminate\Support\Carbon|null $period_start
 * @property \Illuminate\Support\Carbon|null $period_end
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property int|null $created_by
 * @property int|null $approved_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $creator
 * @property-read \App\Models\User|null $approver
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PayrollDetail> $details
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PayrollException> $exceptions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ApprovalLog> $approvalLogs
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payslip> $payslips
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PayrollAuditLog> $auditLogs
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class PayrollRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'period',
        'period_start',
        'period_end',
        'status',
        'locked_at',
        'created_by',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'locked_at' => 'datetime',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function details()
    {
        return $this->hasMany(PayrollDetail::class);
    }

    public function exceptions()
    {
        return $this->hasMany(PayrollException::class);
    }

    public function approvalLogs()
    {
        return $this->hasMany(ApprovalLog::class);
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
