<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 * @property int|null $processed_by
 * @property int|null $released_by
 * @property string|null $hr_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder
 */
class DocumentRequest extends Model
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
    ];

    protected $casts = [
        'requested_on' => 'datetime',
        'processed_on' => 'datetime',
        'released_on' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(User::class, 'EmpNo', 'EmpNo');
    }

    public function documentType()
    {
        return $this->belongsTo(DocumentType::class, 'document_type', 'name');
    }
}
