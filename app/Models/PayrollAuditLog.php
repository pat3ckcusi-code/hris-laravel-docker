<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string|null $action
 * @property int|null $user_id
 * @property int|null $payroll_run_id
 * @property string|null $details
 * @property \Illuminate\Support\Carbon|null $actioned_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\PayrollRun|null $payrollRun
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class PayrollAuditLog extends Model
{
    use HasFactory;

    protected $table = 'payroll_audit_logs';

    protected $fillable = [
        'action',
        'user_id',
        'payroll_run_id',
        'details',
        'actioned_at',
    ];

    protected function casts(): array
    {
        return [
            'actioned_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
