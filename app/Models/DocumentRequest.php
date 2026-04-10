<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
