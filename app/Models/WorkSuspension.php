<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkSuspension extends Model
{
    use HasFactory;

    protected $fillable = [
        'suspension_date',
        'suspension_time',
        'reason',
        'type',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'suspension_date' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isFullDay(): bool
    {
        return $this->suspension_time === null;
    }

    /**
     * @return array{icon: string, color: string, bg: string, label: string}
     */
    public static function typeConfig(string $type): array
    {
        return match ($type) {
            'weather' => ['icon' => 'fa-cloud-showers-heavy', 'color' => '#2563eb', 'bg' => '#dbeafe', 'label' => 'Weather / Typhoon'],
            'event' => ['icon' => 'fa-bullhorn', 'color' => '#9f1239', 'bg' => '#fecdd3', 'label' => 'Urgent Event'],
            default => ['icon' => 'fa-ellipsis-h', 'color' => '#6b7280', 'bg' => '#f3f4f6', 'label' => 'Other'],
        };
    }
}
