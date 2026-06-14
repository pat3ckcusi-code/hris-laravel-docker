<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $action
 * @property int|null $user_id
 * @property int|null $payroll_run_id
 * @property string|null $details
 * @property Carbon|null $actioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read PayrollRun|null $payrollRun
 *
 * @mixin Builder
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
