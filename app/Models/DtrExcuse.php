<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DtrExcuse extends Model
{
    protected $fillable = [
        'user_id',
        'date',
        'excuse_type',
        'is_full_day',
        'excuse_am_in',
        'excuse_am_out',
        'excuse_pm_in',
        'excuse_pm_out',
        'reason',
        'filed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_full_day' => 'boolean',
            'excuse_am_in' => 'boolean',
            'excuse_am_out' => 'boolean',
            'excuse_pm_in' => 'boolean',
            'excuse_pm_out' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by_user_id');
    }

    /**
     * Slot keys ('am_in'|'am_out'|'pm_in'|'pm_out') this excuse covers, for
     * threading into DtrPunchResolver so it doesn't mis-slot a later punch
     * into a slot that's known to have no real punch.
     *
     * @return array<int, string>
     */
    public function excludedSlotKeys(): array
    {
        if ($this->is_full_day) {
            return ['am_in', 'am_out', 'pm_in', 'pm_out'];
        }

        return array_values(array_filter([
            $this->excuse_am_in ? 'am_in' : null,
            $this->excuse_am_out ? 'am_out' : null,
            $this->excuse_pm_in ? 'pm_in' : null,
            $this->excuse_pm_out ? 'pm_out' : null,
        ]));
    }

    /**
     * @return array{icon: string, color: string, bg: string, label: string}
     */
    public static function typeConfig(string $type): array
    {
        return match ($type) {
            'power_interruption' => ['icon' => 'fa-bolt', 'color' => '#d97706', 'bg' => '#fef3c7', 'label' => 'Power Interruption'],
            'system_failure' => ['icon' => 'fa-server', 'color' => '#dc2626', 'bg' => '#fee2e2', 'label' => 'System Failure'],
            'weather_disturbance' => ['icon' => 'fa-cloud-showers-heavy', 'color' => '#2563eb', 'bg' => '#dbeafe', 'label' => 'Force Majeure / Weather'],
            'emergency' => ['icon' => 'fa-triangle-exclamation', 'color' => '#9f1239', 'bg' => '#fecdd3', 'label' => 'Emergency'],
            default => ['icon' => 'fa-ellipsis-h', 'color' => '#6b7280', 'bg' => '#f3f4f6', 'label' => 'Other'],
        };
    }
}
