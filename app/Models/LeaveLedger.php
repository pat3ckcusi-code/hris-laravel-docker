<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveLedger extends Model
{
    protected $table = 'leave_ledger';

    const UPDATED_AT = null;

    public const TYPES = [
        'CREDIT_EARNED',
        'CREDIT_EARNED_WOP',
        'LEAVE_USED',
        'LEAVE_CANCELLED',
        'MONETIZED',
        'TERMINAL_LEAVE',
        'MANUAL_ADJUSTMENT',
        'TRANSFER_IN',
        'TRANSFER_OUT',
        'COMMUTED',
        'LWOP_DEDUCTION',
        'OPENING_BALANCE',
        'ATTENDANCE_DEDUCTION',
    ];

    protected $fillable = [
        'user_id',
        'transaction_date',
        'transaction_type',
        'leave_type',
        'days_present',
        'abs_wop_days',
        'debit_vl',
        'debit_sl',
        'credit_vl',
        'credit_sl',
        'vl_balance_after',
        'sl_balance_after',
        'reference_id',
        'reference_type',
        'remarks',
        'created_by',
        'is_system',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'days_present' => 'float',
        'abs_wop_days' => 'float',
        'debit_vl' => 'float',
        'debit_sl' => 'float',
        'credit_vl' => 'float',
        'credit_sl' => 'float',
        'vl_balance_after' => 'float',
        'sl_balance_after' => 'float',
        'is_system' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
