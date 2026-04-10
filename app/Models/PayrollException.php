<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
