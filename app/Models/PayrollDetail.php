<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property int $days_worked
 * @property int $late_minutes
 * @property int $undertime_minutes
 * @property int $absent_days
 * @property float $basic_salary
 * @property float $earnings
 * @property float $deductions
 * @property float $lwop_deduction
 * @property float $loan_deduction
 * @property float $net_pay
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PayrollRun|null $payrollRun
 * @property-read \App\Models\User|null $employee
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class PayrollDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'days_worked',
        'late_minutes',
        'undertime_minutes',
        'absent_days',
        'basic_salary',
        'earnings',
        'deductions',
        'lwop_deduction',
        'loan_deduction',
        'net_pay',
    ];

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
