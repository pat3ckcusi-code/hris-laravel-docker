<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $actor_user_id
 * @property string|null $module
 * @property string|null $action
 * @property string|null $target_type
 * @property int|null $target_id
 * @property array|null $details
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $actor
 *
 * @mixin Builder
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
