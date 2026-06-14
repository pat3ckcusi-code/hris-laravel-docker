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
    ];

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
