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
        'VL' => ['vacation', 'vl', 'mandatory', 'forced'],
        'SL' => ['sick', 'sl'],
        'WLNS' => ['wellness', 'wlns'],
        'SPL' => ['special privilege', 'special', 'spl', 'privilege'],
        'SP' => ['solo parent', 'solo'],
        'CTO' => ['cto', 'compensatory'],
    ];

    /**
     * Leave types that count every calendar day but never deduct from any
     * leave_balances column, regardless of a substring collision with the
     * keywords above (e.g. "Special Leave (Gynecological)" contains
     * "special", "Rehabilitation Privilege" contains "privilege" — both
     * would otherwise falsely resolve to SPL).
     */
    public const NON_DEDUCTIBLE_TYPES = [
        'Maternity Leave',
        'Special Leave (Gynecological)',
        'Study / Examination Leave',
        'Rehabilitation Privilege',
    ];

    /**
     * Return the canonical leave code ('VL', 'SL', etc.) for a given label,
     * or null when the label does not match any known type.
     */
    public static function fromLabel(string $label): ?string
    {
        $lower = strtolower(trim($label));

        foreach (self::NON_DEDUCTIBLE_TYPES as $type) {
            if ($lower === strtolower($type)) {
                return null;
            }
        }

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
