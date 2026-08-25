<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Generic tracking row for one PNPKI signing attempt against any Signable
 * document (currently only LeaveRequest). Deliberately decoupled from
 * leave_requests itself so a future document type (Travel Order, Office
 * Order) can reuse the same signing pipeline without new columns on every
 * table - see CLAUDE.md's E-Signature Config section for why this
 * generic-from-day-one shape was chosen over a leave-specific pair of columns.
 */
class EsignatureSigning extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'signable_type',
        'signable_id',
        'field_name',
        'requested_by',
        'status',
        'unsigned_path',
        'signed_path',
        'error_message',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected $hidden = ['unsigned_path', 'signed_path'];

    public function signable(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markCompleted(string $signedPath): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'signed_path' => $signedPath,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $message,
            'failed_at' => now(),
        ]);
    }
}
