<?php

namespace App\Models;

use App\Contracts\Signable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $EmpNo
 * @property string|null $document_type
 * @property string|null $purpose
 * @property string $status
 * @property Carbon|null $requested_on
 * @property Carbon|null $processed_on
 * @property Carbon|null $released_on
 * @property string|null $processed_by
 * @property string|null $released_by
 * @property string|null $hr_notes
 * @property string|null $signature_status
 * @property int|null $signature_reviewed_by
 * @property Carbon|null $signature_reviewed_at
 * @property string|null $signature_review_remarks
 * @property int|null $signed_by
 * @property Carbon|null $signed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder
 */
class DocumentRequest extends Model implements Signable
{
    use HasFactory;

    protected $fillable = [
        'EmpNo',
        'document_type',
        'purpose',
        'status',
        'requested_on',
        'processed_on',
        'released_on',
        'processed_by',
        'released_by',
        'hr_notes',
        'signature_status',
        'signature_reviewed_by',
        'signature_reviewed_at',
        'signature_review_remarks',
        'signed_by',
        'signed_at',
    ];

    protected $casts = [
        'requested_on' => 'datetime',
        'processed_on' => 'datetime',
        'released_on' => 'datetime',
        'signature_reviewed_at' => 'datetime',
        'signed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'EmpNo', 'EmpNo');
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type', 'name');
    }

    public function signatureReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signature_reviewed_by');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    public function latestEsignatureSigning(): MorphOne
    {
        return $this->morphOne(EsignatureSigning::class, 'signable')->latestOfMany();
    }

    public function esignatureSignings(): MorphMany
    {
        return $this->morphMany(EsignatureSigning::class, 'signable');
    }

    public function esignatureOwner(): User
    {
        return $this->employee;
    }

    public function esignaturePrintUrl(): string
    {
        return route('front-desk.print-request', $this->id);
    }
}
