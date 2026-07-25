<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $key
 * @property string $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Plantilla> $plantillas
 *
 * @mixin Builder
 */
class CscEligibilityOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
    ];

    /**
     * Loose reference by string value, not a real FK (plantillas.csc_eligibility
     * has no DB-level foreign key) - used as the in-use delete guard in
     * CscEligibilityOptionsController::destroy() and for the usage-count
     * column on the index listing.
     */
    public function plantillas()
    {
        return $this->hasMany(Plantilla::class, 'csc_eligibility', 'key');
    }
}
