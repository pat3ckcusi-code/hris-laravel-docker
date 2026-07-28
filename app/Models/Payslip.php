<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $employee_id
 * @property int $payroll_run_id
 * @property string|null $pdf_path
 * @property float $basic_salary
 * @property float $gross_pay
 * @property float $mandatory_deductions
 * @property float $loan_deduction
 * @property float $other_deductions
 * @property float $lwop_deduction
 * @property float $total_deductions
 * @property float $net_pay
 * @property array|null $deduction_breakdown
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $employee
 * @property-read PayrollRun|null $payrollRun
 *
 * @mixin Builder
 */
class Payslip extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'payroll_run_id',
        'pdf_path',
        'basic_salary',
        'gross_pay',
        'mandatory_deductions',
        'loan_deduction',
        'other_deductions',
        'lwop_deduction',
        'total_deductions',
        'net_pay',
        'deduction_breakdown',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'float',
            'gross_pay' => 'float',
            'mandatory_deductions' => 'float',
            'loan_deduction' => 'float',
            'other_deductions' => 'float',
            'lwop_deduction' => 'float',
            'total_deductions' => 'float',
            'net_pay' => 'float',
            'deduction_breakdown' => 'array',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
