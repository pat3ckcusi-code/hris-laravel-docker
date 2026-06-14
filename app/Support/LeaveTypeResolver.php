<?php

namespace App\Support;

/**
 * Maps a free-text leave_type label to its canonical balance column code.
 *
 * Usage: LeaveTypeResolver::fromLabel($leave->leave_type)  → 'VL' | 'SL' | ... | null
 */
class LeaveTypeResolver
{
    private const LABEL_MAP = [
        'VL' => ['vacation', 'vl'],
        'SL' => ['sick', 'sl'],
        'WLNS' => ['wellness', 'wlns'],
        'SPL' => ['special privilege', 'special', 'spl', 'privilege'],
        'SP' => ['solo parent', 'solo'],
        'CTO' => ['cto', 'compensatory'],
    ];

    /**
     * Return the canonical leave code ('VL', 'SL', etc.) for a given label,
     * or null when the label does not match any known type.
     */
    public static function fromLabel(string $label): ?string
    {
        $lower = strtolower(trim($label));

        foreach (self::LABEL_MAP as $code => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * All known leave type codes, in display order.
     *
     * @return string[]
     */
    public static function allCodes(): array
    {
        return array_keys(self::LABEL_MAP);
    }
}
