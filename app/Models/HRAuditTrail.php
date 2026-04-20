<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $actor_user_id
 * @property string|null $module
 * @property string|null $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property array|null $details
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User|null $actor
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class HRAuditTrail extends Model
{
    use HasFactory;

    protected $table = 'hr_audit_trails';

    protected $fillable = [
        'actor_user_id',
        'module',
        'action',
        'target_type',
        'target_id',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
