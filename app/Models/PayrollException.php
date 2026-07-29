<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $payroll_run_id
 * @property string|null $type
 * @property string|null $description
 * @property bool $resolved_flag
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PayrollRun|null $payrollRun
 *
 * @mixin Builder
 */
class PayrollException extends Model
{
    use HasFactory;

    /**
     * Type values PayrollComputationService::compute() creates automatically
     * on every recompute - as opposed to a Payroll Manager's manually-logged
     * exception (arbitrary type, via ExceptionsController::store()). compute()
     * clears rows of these types before regenerating them so repeated
     * recomputes of the same run don't accumulate stale duplicates; manual
     * entries are left untouched since they aren't re-derived by compute().
     */
    public const AUTO_TYPES = [
        'no_assignments',
        'lwop_deduction',
        'missing_withholding_tax',
    ];

    protected $table = 'payroll_exceptions';

    protected $fillable = [
        'payroll_run_id',
        'type',
        'description',
        'resolved_flag',
    ];

    protected function casts(): array
    {
        return [
            'resolved_flag' => 'boolean',
        ];
    }

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class);
    }
}
