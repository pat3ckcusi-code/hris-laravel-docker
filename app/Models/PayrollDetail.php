<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

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
 * @property float $other_deductions
 * @property array|null $deduction_breakdown
 * @property float $net_pay
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PayrollRun|null $payrollRun
 * @property-read User|null $employee
 *
 * @mixin Builder
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
        'gross_pay',
        'earnings',
        'deductions',
        'gsis_deduction',
        'philhealth_deduction',
        'pagibig_deduction',
        'bir_deduction',
        'lwop_deduction',
        'loan_deduction',
        'other_deductions',
        'deduction_breakdown',
        'net_pay',
    ];

    protected function casts(): array
    {
        return [
            'days_worked' => 'integer',
            'late_minutes' => 'integer',
            'undertime_minutes' => 'integer',
            'absent_days' => 'integer',
            'basic_salary' => 'float',
            'gross_pay' => 'float',
            'earnings' => 'float',
            'deductions' => 'float',
            'gsis_deduction' => 'float',
            'philhealth_deduction' => 'float',
            'pagibig_deduction' => 'float',
            'bir_deduction' => 'float',
            'lwop_deduction' => 'float',
            'loan_deduction' => 'float',
            'other_deductions' => 'float',
            'deduction_breakdown' => 'array',
            'net_pay' => 'float',
        ];
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
