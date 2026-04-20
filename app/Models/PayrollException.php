<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $payroll_run_id
 * @property string|null $type
 * @property string|null $description
 * @property bool $resolved_flag
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\PayrollRun|null $payrollRun
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class PayrollException extends Model
{
    use HasFactory;

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
