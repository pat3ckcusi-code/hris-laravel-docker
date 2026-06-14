<?php

namespace App\Support;

/**
 * Centralises role-string normalisation so controllers, services, and middleware
 * all use the same logic instead of duplicating private normalizeRole() methods.
 *
 * Usage:
 *   RoleNormalizer::normalize('HR-Manager')  → 'hr manager'
 *   RoleNormalizer::rawExpression()          → SQL fragment for use in whereRaw()
 */
class RoleNormalizer
{
    /**
     * Normalize a role string: lowercase, replace hyphens/underscores with spaces,
     * collapse repeated spaces.
     */
    public static function normalize(string $role): string
    {
        $lower = strtolower(trim($role));
        $replaced = str_replace(['_', '-'], ' ', $lower);

        return (string) preg_replace('/\s+/', ' ', $replaced);
    }

    /**
     * Return the MySQL expression that normalises the given column the same way.
     *
     * Example:
     *   ->whereRaw(RoleNormalizer::rawExpression() . " = ?", ['hr manager'])
     *   ->whereRaw(RoleNormalizer::rawExpression('users.access_level') . " = 'employee'")
     */
    public static function rawExpression(string $column = 'access_level'): string
    {
        return "LOWER(REPLACE(REPLACE({$column}, '-', ' '), '_', ' '))";
    }
}
