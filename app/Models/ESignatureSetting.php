<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ESignatureSetting extends Model
{
    protected $table = 'esignature_settings';

    protected $fillable = [
        'user_id',
        'signature_path',
        'certificate_path',
        'root_ca_path',
        'intermediate_paths',
        'include_name',
        'include_date',
    ];

    protected $casts = [
        'intermediate_paths' => 'array',
        'include_name' => 'boolean',
        'include_date' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
