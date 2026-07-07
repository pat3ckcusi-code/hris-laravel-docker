<?php

namespace App\Support;

/**
 * Application-wide constants for the HRIS system.
 *
 * Centralising these prevents magic strings and numbers from being scattered
 * across controllers, services, and Blade templates. Use the constants here
 * rather than inline literals so any future change requires only one edit.
 */
class HrisConstants
{
    // ── Leave type codes ───────────────────────────────────────────────────

    /** Canonical codes used in leave_balances columns and leave_type comparisons. */
    public const LEAVE_TYPES = ['VL', 'SL', 'WLNS', 'SPL', 'CTO', 'SP'];

    // ── Employee types ──────────────────────────────────────────────────────

    /** Canonical `users.employee_type` values, in Title Case. */
    public const EMPLOYEE_TYPES = ['Permanent', 'Elected Officials', 'Co-Terminus', 'Casual', 'Job Orders', 'Contractual'];

    // ── Workforce planning ─────────────────────────────────────────────────

    /** Service-anniversary milestones (years) tracked for recognition. */
    public const MILESTONE_YEARS = [10, 15, 20, 25, 30];

    // ── Alerts / stale thresholds ──────────────────────────────────────────

    /** Number of days after which a pending request is considered stale. */
    public const STALE_REQUEST_DAYS = 3;

    // ── Payroll ────────────────────────────────────────────────────────────

    /** Standard government working days per month (CSC / DBM basis). */
    public const PAYROLL_WORKING_DAYS = 22;

    // ── Roles ──────────────────────────────────────────────────────────────

    /**
     * Normalised role strings (lowercase, spaces).
     * Always compare via RoleNormalizer::normalize() to handle hyphen/underscore variants.
     */
    public const ROLE_EMPLOYEE = 'employee';

    public const ROLE_DEPARTMENT_HEAD = 'department head';

    public const ROLE_ADMINISTRATIVE_OFFICER = 'administrative officer';

    public const ROLE_HR_MANAGER = 'hr manager';

    public const ROLE_LEAVE_MANAGER = 'leave manager';

    public const ROLE_RECORDS_MANAGER = 'records manager';

    public const ROLE_FRONT_DESK = 'front desk';

    public const ROLE_MAYOR = 'mayor';

    public const ROLE_PAYROLL_OFFICER = 'payroll officer';

    /** All roles that hold administrative authority over leave requests. */
    public const ADMIN_LEAVE_ROLES = [
        self::ROLE_DEPARTMENT_HEAD,
        self::ROLE_ADMINISTRATIVE_OFFICER,
        self::ROLE_HR_MANAGER,
    ];
}
