<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $payroll_run_id
 * @property int $approver_id
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $actioned_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PayrollRun|null $payrollRun
 * @property-read \App\Models\User|null $approver
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class ApprovalLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'approver_id',
        'status',
        'actioned_at',
    ];

    protected function casts(): array
    {
        return [
            'actioned_at' => 'datetime',
        ];
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
