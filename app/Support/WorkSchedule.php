<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;

/**
 * The effective shift for a single employee.
 *
 * Every employee defaults to the global standard-day shift from the settings
 * table. An employee assigned a Shift template (User::shift_id) is scored
 * against that template's times instead. This is the single source of an
 * employee's shift, so the penalty code paths (DtrPunchResolver,
 * Form48ExportService::computeSlotPenalties, DtrController::data) never disagree.
 *
 * The five threshold fields map to the four CSC Form 48 slots plus the break
 * classification window:
 *   workStart   = shift start            (Form 48 AM In)
 *   morningEnd  = leave for meal break   (AM Out) — upper bound for "is an arrival punch"
 *   lunchReturn = return from meal break (PM In)
 *   noonEnd     = upper bound of the break-return window
 *   workEnd     = shift end              (PM Out)
 *
 * crossesMidnight is true for a night shift (workEnd <= workStart): its
 * post-midnight reference times and punches live on the day AFTER the shift date.
 */
class WorkSchedule
{
    /** Per-request memo of the global schedule (bulk dept exports iterate many employees). */
    private static ?self $global = null;

    public function __construct(
        public readonly string $workStart,
        public readonly string $lunchReturn,
        public readonly string $workEnd,
        public readonly string $morningEnd,
        public readonly string $noonEnd,
        public readonly bool $crossesMidnight = false,
    ) {}

    /** The system-wide standard-day shift from the settings table (memoized). */
    public static function global(): self
    {
        if (self::$global !== null) {
            return self::$global;
        }

        $s = Setting::first();

        return self::$global = new self(
            workStart: self::hm($s?->work_start) ?? '08:00',
            lunchReturn: self::hm($s?->lunch_return) ?? '13:00',
            workEnd: self::hm($s?->work_end) ?? '17:00',
            morningEnd: self::hm($s?->morning_end) ?? '11:00',
            noonEnd: self::hm($s?->noon_end) ?? '14:00',
            crossesMidnight: false,
        );
    }

    /**
     * The effective schedule for a user: the global standard day unless the
     * user is assigned a Shift template.
     */
    public static function forUser(?User $user): self
    {
        if ($user === null || $user->shift_id === null) {
            return self::global();
        }

        $shift = $user->relationLoaded('shift') ? $user->shift : $user->shift()->first();

        if ($shift === null) {
            return self::global();
        }

        return new self(
            workStart: self::hm($shift->time_in),
            lunchReturn: self::hm($shift->break_in),
            workEnd: self::hm($shift->time_out),
            morningEnd: self::hm($shift->break_out),
            noonEnd: self::hm($shift->time_out),
            crossesMidnight: (bool) $shift->crosses_midnight,
        );
    }

    /** Clear the per-request global memo (used in tests after mutating settings). */
    public static function flushGlobal(): void
    {
        self::$global = null;
    }

    /**
     * The midpoint of the off-period (the non-working gap from workEnd to the
     * next workStart), as 'HH:MM'. This is the single boundary that splits any
     * punch into the shift it belongs to — see shiftDateFor().
     */
    public function offPeriodMidpoint(): string
    {
        $out = self::toMinutes($this->workEnd);
        $in = self::toMinutes($this->workStart);

        // Off-period runs from workEnd to the next workStart.
        $offEnd = $in > $out ? $in : $in + 1440;
        $mid = (int) round(($out + $offEnd) / 2) % 1440;

        return self::fromMinutes($mid);
    }

    /**
     * The logical shift date a punch belongs to. Evening punches (>= midpoint)
     * keep their own logdate; post-midnight punches (< midpoint) fold onto the
     * previous day's shift. For a non-crossing day shift the midpoint sits just
     * after midnight, so every daytime punch maps to its own logdate (today's
     * behaviour).
     */
    public function shiftDateFor(string $logdate, string $logtime): string
    {
        $boundary = $this->offPeriodMidpoint();
        $date = Carbon::parse($logdate)->startOfDay();

        return self::hm($logtime) >= $boundary
            ? $date->toDateString()
            : $date->subDay()->toDateString();
    }

    /**
     * Build the datetime of a reference time (e.g. workStart, workEnd) relative
     * to a shift date. For a crossing shift, reference times in the post-midnight
     * portion roll onto shiftDate + 1.
     */
    public function referenceDateTime(string $shiftDate, string $hhmm): Carbon
    {
        $hhmm = self::hm($hhmm);
        $date = Carbon::parse("$shiftDate $hhmm:00");

        if ($this->crossesMidnight && $hhmm < $this->offPeriodMidpoint()) {
            $date->addDay();
        }

        return $date;
    }

    private static function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', substr($hhmm, 0, 5)), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    private static function fromMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Normalize a stored time (HH:MM or HH:MM:SS) to HH:MM; null/empty → null. */
    private static function hm(?string $time): ?string
    {
        $time = trim((string) $time);

        return $time === '' ? null : substr($time, 0, 5);
    }
}
