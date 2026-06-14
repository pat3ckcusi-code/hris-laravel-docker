<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $payroll_run_id
 * @property int $approver_id
 * @property string $status
 * @property Carbon|null $actioned_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PayrollRun|null $payrollRun
 * @property-read User|null $approver
 *
 * @mixin Builder
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
