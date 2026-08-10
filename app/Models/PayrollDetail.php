<?php

namespace App\Models;

use App\Casts\EncryptedArray;
use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property float $basic_salary
 * @property int|null $salary_matrix_id
 * @property array|null $basic_salary_breakdown
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
 * @property-read SalaryMatrix|null $salaryMatrix
 *
 * @mixin Builder
 */
class PayrollDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'basic_salary',
        'salary_matrix_id',
        'basic_salary_breakdown',
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
            'basic_salary' => EncryptedDecimal::class,
            'basic_salary_breakdown' => EncryptedArray::class,
            'gross_pay' => EncryptedDecimal::class,
            'earnings' => EncryptedDecimal::class,
            'deductions' => EncryptedDecimal::class,
            'gsis_deduction' => EncryptedDecimal::class,
            'philhealth_deduction' => EncryptedDecimal::class,
            'pagibig_deduction' => EncryptedDecimal::class,
            'bir_deduction' => EncryptedDecimal::class,
            'lwop_deduction' => EncryptedDecimal::class,
            'loan_deduction' => EncryptedDecimal::class,
            'other_deductions' => EncryptedDecimal::class,
            'deduction_breakdown' => EncryptedArray::class,
            'net_pay' => EncryptedDecimal::class,
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

    public function salaryMatrix()
    {
        return $this->belongsTo(SalaryMatrix::class);
    }
}
