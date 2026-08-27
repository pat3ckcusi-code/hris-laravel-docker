<?php

namespace App\Support;

use App\Models\EmployeeShiftSchedule;
use App\Models\Setting;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The effective shift for a single employee.
 *
 * Every employee defaults to the global standard-day shift from the settings
 * table. An employee assigned a Shift template (User::shift_id) is scored
 * against that template's times instead. forUser() reads User::shift_id, a
 * denormalized "today" cache kept in sync by ShiftAssignmentService; date-aware
 * callers (forUserOnDate()/isWorkday()) resolve the shift_assignments row that
 * actually covers that date, so a past or future shift change is reflected
 * correctly even if it differs from what's cached for today. Together these are
 * the single source of an employee's shift, so the penalty code paths
 * (DtrPunchResolver, Form48ExportService::computeSlotPenalties, DtrController::data)
 * never disagree.
 *
 * The five threshold fields map to the four CSC Form 48 slots plus the break
 * classification window:
 *   workStart   = shift start            (Form 48 AM In)
 *   morningEnd  = leave for meal break   (AM Out) - upper bound for "is an arrival punch"
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

    /** Per-request memo of shift-assignment history, keyed by user_id (bulk dept exports iterate many employees). */
    private static ?Collection $shiftAssignments = null;

    public function __construct(
        public readonly string $workStart,
        public readonly string $lunchReturn,
        public readonly string $workEnd,
        public readonly string $morningEnd,
        public readonly string $noonEnd,
        public readonly bool $crossesMidnight = false,
        public readonly bool $noBreak = false,
        public readonly bool $isStandardDay = false,
        public readonly string $punchRequirement = 'both',
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
            isStandardDay: true,
            punchRequirement: 'both',
        );
    }

    /**
     * The effective schedule for a user on a specific date.
     *
     * Checks $preloaded (a date-string-keyed Collection of EmployeeShiftSchedule)
     * before querying the database, so bulk loops can pre-load once and avoid N+1.
     * Falls back to forUser() when no per-date assignment exists.
     *
     * Returns forUser() unchanged when the assignment is a rest day (shift_id null);
     * callers should check isRestDay() first and skip punch resolution for those dates.
     *
     * @param  Collection<string, EmployeeShiftSchedule>|null  $preloaded
     */
    public static function forUserOnDate(User $user, Carbon $date, ?Collection $preloaded = null): self
    {
        $dateStr = $date->toDateString();

        if ($preloaded !== null) {
            $assignment = $preloaded->get($dateStr);
        } else {
            $assignment = EmployeeShiftSchedule::where('user_id', $user->id)
                ->where('date', $dateStr)
                ->with('shift')
                ->first();
        }

        if ($assignment !== null && $assignment->shift_id !== null) {
            $shift = $assignment->relationLoaded('shift') ? $assignment->shift : $assignment->shift()->first();

            if ($shift !== null) {
                // Unlike a resolved ShiftAssignment row, a per-date override
                // has its own no_break/punch_requirement columns (added
                // directly to employee_shift_schedules, not shift_assignments)
                // since it has no shift_assignments row of its own to read from.
                return self::fromShift($shift, (bool) $assignment->no_break, (string) ($assignment->punch_requirement ?? 'both'));
            }
        }

        // An explicit "Standard Day" override on this date deliberately ignores
        // the Shift Assignment history default - it exists specifically so a
        // date can be forced to standard hours even while the employee has an
        // ongoing shift_assignments row covering it.
        if ($assignment !== null && $assignment->shift_id === null && $assignment->type === 'standard') {
            return self::global();
        }

        $historical = self::resolveShiftAssignment($user, $date);

        if ($historical !== null) {
            return $historical->shift_id === null ? self::global() : self::fromAssignment($historical);
        }

        // Day-scoped assignments exist for this date range (e.g. an MWF+TTH
        // split), just none of them apply to this particular day-of-week -
        // there's nothing meaningful to return here since isWorkday() (which
        // callers must check first) already reports this as a non-workday.
        if (self::hasAnyAssignmentCoveringDate($user, $date)) {
            return self::global();
        }

        return self::forUser($user);
    }

    /**
     * The shift_assignments row covering $date for $user AND applying to that
     * date's day-of-week, if any (either from the per-request memo warmed by
     * preloadShiftAssignments(), or a direct query for single-employee call
     * sites). Returns null when no dated, day-matching assignment exists, so
     * callers fall back further (see hasAnyAssignmentCoveringDate()).
     */
    private static function resolveShiftAssignment(User $user, Carbon $date): ?ShiftAssignment
    {
        $rows = self::assignmentRowsFor($user);

        return $rows->first(fn (ShiftAssignment $row) => self::coversDate($row, $date) && $row->appliesOnDate($date));
    }

    /**
     * True when $user has an assignment covering $date by date range alone,
     * regardless of day-of-week match - distinguishes "no assignment history
     * at all" (falls back to the global Standard Day default, unchanged
     * behavior) from "has day-scoped assignments, but none cover this
     * weekday" (a deliberate non-workday, e.g. Saturday for an MWF+TTH-only
     * employee).
     */
    private static function hasAnyAssignmentCoveringDate(User $user, Carbon $date): bool
    {
        return self::assignmentRowsFor($user)->contains(fn (ShiftAssignment $row) => self::coversDate($row, $date));
    }

    /**
     * A warmed memo only ever omits a user it was never asked about (it
     * groups exactly the IDs passed to preloadShiftAssignments()), so falling
     * back to a live query for an unlisted user is always safe - it can't
     * mean "no rows," only "wasn't part of this warm-up."
     *
     * @return Collection<int, ShiftAssignment>
     */
    private static function assignmentRowsFor(User $user): Collection
    {
        if (self::$shiftAssignments !== null && self::$shiftAssignments->has($user->id)) {
            return self::$shiftAssignments->get($user->id);
        }

        return ShiftAssignment::forUser($user->id)->with('shift')->get();
    }

    private static function coversDate(ShiftAssignment $row, Carbon $date): bool
    {
        return $row->effective_from->lte($date)
            && ($row->effective_until === null || $row->effective_until->gte($date));
    }

    /**
     * Warm the per-request shift-assignment memo for a bulk loop, avoiding an
     * N+1 query per employee per date. Call once before iterating; forgetting
     * to call it just falls back to a per-user query, so it's an optimization,
     * not a correctness requirement.
     *
     * @param  iterable<int>  $userIds
     */
    public static function preloadShiftAssignments(iterable $userIds): void
    {
        self::$shiftAssignments = ShiftAssignment::whereIn('user_id', collect($userIds)->all())
            ->with('shift')
            ->orderBy('effective_from')
            ->get()
            ->groupBy('user_id');
    }

    /** Clear the per-request shift-assignment memo (used in tests after seeding new rows). */
    public static function flushShiftAssignmentMemo(): void
    {
        self::$shiftAssignments = null;
    }

    /**
     * Low-level builder from a bare Shift, with no per-assignment context -
     * $noBreak/$punchRequirement default to false/'both' since a template no
     * longer carries its own real values for either. Used directly only by
     * the EmployeeShiftSchedule per-date-override path (no shift_assignments
     * row exists there) and as forUser()'s defensive fallback. Everywhere a
     * real ShiftAssignment is in hand, use fromAssignment() instead so
     * no_break/punch_requirement resolve correctly.
     */
    private static function fromShift(Shift $shift, bool $noBreak = false, string $punchRequirement = 'both'): self
    {
        return new self(
            workStart: self::hm($shift->time_in),
            lunchReturn: self::hm($shift->break_in) ?? self::hm($shift->time_out),
            workEnd: self::hm($shift->time_out),
            morningEnd: self::hm($shift->break_out) ?? self::hm($shift->time_out),
            noonEnd: self::hm($shift->time_out),
            crossesMidnight: (bool) $shift->crosses_midnight,
            noBreak: $noBreak,
            punchRequirement: $punchRequirement,
        );
    }

    /** Builds from a resolved ShiftAssignment row, reading no_break/punch_requirement from the row itself. */
    private static function fromAssignment(ShiftAssignment $assignment): self
    {
        return self::fromShift($assignment->shift, (bool) $assignment->no_break, (string) ($assignment->punch_requirement ?? 'both'));
    }

    /**
     * Adjust this schedule for a work-suspension cutoff time (e.g. a typhoon
     * dismissal at 3:00 PM). A cutoff at/before morningEnd suspends the whole
     * day (all four Form 48 slots excluded, same as a full-day DtrExcuse); at/
     * before lunchReturn suspends only the afternoon (pm_in/pm_out excluded,
     * AM slots unaffected); after lunchReturn it only caps workEnd, so a PM
     * Out at/after the cutoff resolves as on-time while an actual early
     * departure before it is still undertime - excludedSlots alone can't
     * express that since it only means "no punch expected here at all," not
     * "the expected time moved earlier." $suspensionTime null means a
     * full-day suspension (equivalent to a '00:00' cutoff).
     *
     * @return array{0: self, 1: array<string, array{0:string,1:string}|null>}
     */
    public function applySuspension(?string $suspensionTime): array
    {
        $cutoff = self::hm($suspensionTime) ?? '00:00';

        if (self::toMinutes($cutoff) <= self::toMinutes($this->morningEnd)) {
            return [$this, array_fill_keys(['am_in', 'am_out', 'pm_in', 'pm_out'], null)];
        }

        if (self::toMinutes($cutoff) <= self::toMinutes($this->lunchReturn)) {
            return [$this, array_fill_keys(['pm_in', 'pm_out'], null)];
        }

        if (self::toMinutes($cutoff) >= self::toMinutes($this->workEnd)) {
            return [$this, []]; // cutoff at/after the normal end - no effect
        }

        return [new self(
            workStart: $this->workStart,
            lunchReturn: $this->lunchReturn,
            workEnd: $cutoff,
            morningEnd: $this->morningEnd,
            noonEnd: $this->noonEnd,
            crossesMidnight: $this->crossesMidnight,
            noBreak: $this->noBreak,
            isStandardDay: $this->isStandardDay,
            punchRequirement: $this->punchRequirement,
        ), []];
    }

    /**
     * True when the employee is scheduled off on $date (assignment row with shift_id
     * = null and type 'rest' - excludes 'field_work', 'wfh', 'standard', and
     * 'field_work_unconfirmed', which are also shift_id = null but represent
     * working days, not a day off - see EmployeeShiftSchedule's class docblock
     * for what 'field_work_unconfirmed' means).
     *
     * @param  Collection<string, EmployeeShiftSchedule>|null  $preloaded
     */
    public static function isRestDay(User $user, Carbon $date, ?Collection $preloaded = null): bool
    {
        $dateStr = $date->toDateString();

        if ($preloaded !== null) {
            $assignment = $preloaded->get($dateStr);
        } else {
            $assignment = EmployeeShiftSchedule::where('user_id', $user->id)
                ->where('date', $dateStr)
                ->first();
        }

        return $assignment !== null && $assignment->shift_id === null
            && ! in_array($assignment->type, ['field_work', 'wfh', 'standard', 'field_work_unconfirmed'], true);
    }

    /**
     * True when $user is scheduled to work on $date: an explicit per-date
     * override always wins (a rest-day row = no; an assigned-shift, field_work,
     * wfh, or standard-day row = yes); otherwise falls back to the user's
     * assigned shift's weekly work-days pattern, or Mon-Fri if no shift is
     * assigned.
     *
     * @param  Collection<string, EmployeeShiftSchedule>|null  $preloaded
     */
    public static function isWorkday(User $user, Carbon $date, ?Collection $preloaded = null): bool
    {
        $dateStr = $date->toDateString();

        $assignment = $preloaded !== null
            ? $preloaded->get($dateStr)
            : EmployeeShiftSchedule::where('user_id', $user->id)->where('date', $dateStr)->first();

        if ($assignment !== null) {
            return ! ($assignment->shift_id === null && ! in_array($assignment->type, ['field_work', 'wfh', 'standard', 'field_work_unconfirmed'], true));
        }

        $historical = self::resolveShiftAssignment($user, $date);

        if ($historical !== null) {
            return $historical->shift_id === null ? $date->isWeekday() : $historical->worksOnDate($date);
        }

        // Day-scoped assignments cover this date range (e.g. an MWF+TTH
        // split), just none apply to this day-of-week - a deliberate
        // non-workday, not a fall-through to the global default.
        if (self::hasAnyAssignmentCoveringDate($user, $date)) {
            return false;
        }

        // No shift_assignments row covers this date at all (a transient
        // stale-cache case - users.shift_id is normally always backed by a
        // covering row). Work Days no longer lives on the Shift template, so
        // there's no per-employee pattern left to consult here; fall back to
        // the same Mon-Fri default used when no shift was ever assigned.
        return $date->isWeekday();
    }

    /**
     * True when the employee is on field work on $date (assignment row with type = 'field_work').
     *
     * @param  Collection<string, EmployeeShiftSchedule>|null  $preloaded
     */
    public static function isFieldWork(User $user, Carbon $date, ?Collection $preloaded = null): bool
    {
        $dateStr = $date->toDateString();

        if ($preloaded !== null) {
            $assignment = $preloaded->get($dateStr);
        } else {
            $assignment = EmployeeShiftSchedule::where('user_id', $user->id)
                ->where('date', $dateStr)
                ->first();
        }

        return $assignment !== null && $assignment->type === 'field_work';
    }

    /**
     * True when the employee is working from home on $date (assignment row with type = 'wfh').
     *
     * @param  Collection<string, EmployeeShiftSchedule>|null  $preloaded
     */
    public static function isWfh(User $user, Carbon $date, ?Collection $preloaded = null): bool
    {
        $dateStr = $date->toDateString();

        if ($preloaded !== null) {
            $assignment = $preloaded->get($dateStr);
        } else {
            $assignment = EmployeeShiftSchedule::where('user_id', $user->id)
                ->where('date', $dateStr)
                ->first();
        }

        return $assignment !== null && $assignment->type === 'wfh';
    }

    /**
     * True when $date's absence was retroactively voided by
     * WeeklyPunchPairReconciliationService (a Field Work Pair week that
     * resolved incomplete - either it fell before the week's real check-in
     * day, or the whole week was voided because Friday's check-out never
     * happened; see that class's docblock for the full rule). This is a
     * real, consequence-bearing absence - isWorkday() is true for it, unlike
     * an ordinary rest day/gap - but display layers need to tell it apart
     * from an actual normal working day, since resolutionSource() has no
     * shift/hours to describe for it (shiftName resolves null, same as a
     * plain 'rest' override) and it would otherwise render misleadingly as
     * "Standard Day" or "Rest Day" instead of the real absence it is.
     *
     * @param  Collection<string, EmployeeShiftSchedule>|null  $preloaded
     */
    public static function isFieldWorkPairVoidedAbsence(User $user, Carbon $date, ?Collection $preloaded = null): bool
    {
        $dateStr = $date->toDateString();
        $assignment = $preloaded !== null
            ? $preloaded->get($dateStr)
            : EmployeeShiftSchedule::where('user_id', $user->id)->where('date', $dateStr)->first();

        return $assignment !== null && $assignment->type === 'field_work_unconfirmed';
    }

    /**
     * True when isWorkday() is false for $date SPECIFICALLY because an
     * is_field_work_pair ShiftAssignment covers the date range but doesn't
     * apply to this weekday (e.g. Tue/Wed/Thu, or a weekend, within a
     * Monday-in/Friday-out Field Work Shift week) - not an ordinary rest day
     * or an unrelated day-of-week gap (e.g. an MWF+TTH concurrent split's off
     * days). Display-only, like resolutionSource() below - DTR/payroll
     * resolution itself doesn't care why a date isn't a workday, only that
     * it isn't (isWorkday()/isRestDay() are untouched by this method).
     *
     * @param  Collection<string, EmployeeShiftSchedule>|null  $preloadedOverrides
     */
    public static function isFieldWorkPairGapDay(User $user, Carbon $date, ?Collection $preloadedOverrides = null): bool
    {
        $dateStr = $date->toDateString();
        $override = $preloadedOverrides !== null
            ? $preloadedOverrides->get($dateStr)
            : EmployeeShiftSchedule::where('user_id', $user->id)->where('date', $dateStr)->first();

        if ($override !== null || self::resolveShiftAssignment($user, $date) !== null) {
            return false;
        }

        return self::assignmentRowsFor($user)
            ->filter(fn (ShiftAssignment $row) => self::coversDate($row, $date))
            ->contains(fn (ShiftAssignment $row) => (bool) $row->shift?->is_field_work_pair);
    }

    /**
     * Read-only reporting helper (e.g. the per-employee resolved-schedule
     * calendar) describing WHICH layer decided $date's schedule and what
     * shift name that layer names - never consulted by DTR/payroll
     * resolution itself, which only cares about the resolved VALUE (see
     * forUserOnDate()/isWorkday()), not which layer produced it. Also flags
     * when an EmployeeShiftSchedule override is silently shadowing a
     * ShiftAssignment row that would otherwise apply - the exact situation
     * that makes the Shift Assignment screen look "active" while a
     * different outcome actually governs the date.
     *
     * @param  Collection<string, EmployeeShiftSchedule>|null  $preloadedOverrides
     * @return array{source: 'override'|'assignment'|'default', shiftName: ?string, shadowedAssignmentShiftName: ?string}
     */
    public static function resolutionSource(User $user, Carbon $date, ?Collection $preloadedOverrides = null): array
    {
        $dateStr = $date->toDateString();
        $override = $preloadedOverrides !== null
            ? $preloadedOverrides->get($dateStr)
            : EmployeeShiftSchedule::where('user_id', $user->id)->where('date', $dateStr)->with('shift')->first();

        $historical = self::resolveShiftAssignment($user, $date);
        $historicalShiftName = $historical !== null
            ? ($historical->shift_id === null ? 'Standard Day' : $historical->shift?->name)
            : null;

        if ($override !== null) {
            $shift = $override->shift_id !== null
                ? ($override->relationLoaded('shift') ? $override->shift : $override->shift()->first())
                : null;

            $shiftName = match (true) {
                $override->shift_id !== null => $shift?->name,
                $override->type === 'standard' => 'Standard Day',
                $override->type === 'wfh' => 'Work From Home',
                default => null, // rest / field_work - no shift hours apply
            };

            // A rotation-generated override was written by the same action
            // that wrote the assignment it would otherwise appear to shadow -
            // that's not a real conflict between two independently-edited
            // screens, so don't surface the shadow warning for it.
            $shadowedAssignmentShiftName = $override->is_rotation_generated ? null : $historicalShiftName;

            return ['source' => 'override', 'shiftName' => $shiftName, 'shadowedAssignmentShiftName' => $shadowedAssignmentShiftName];
        }

        if ($historical !== null) {
            return ['source' => 'assignment', 'shiftName' => $historicalShiftName, 'shadowedAssignmentShiftName' => null];
        }

        // Day-scoped assignments cover this date range (e.g. an MWF+TTH
        // split), just none apply to this day-of-week - still governed by
        // the employee's Shift Assignment history, not "no shift assigned".
        if (self::hasAnyAssignmentCoveringDate($user, $date)) {
            return ['source' => 'assignment', 'shiftName' => null, 'shadowedAssignmentShiftName' => null];
        }

        return ['source' => 'default', 'shiftName' => 'Standard Day', 'shadowedAssignmentShiftName' => null];
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

        // users.shift_id is a denormalized "today" cache of whichever
        // shift_assignments row currently governs - resolve that row too, so
        // no_break (which now lives on the row, not the Shift template) is
        // read correctly. Falls back to a bare full-break Shift build if no
        // matching row is found (a stale-cache edge case).
        $today = Carbon::today();
        $assignment = self::assignmentRowsFor($user)->first(
            fn (ShiftAssignment $row) => $row->shift_id === $shift->id && self::coversDate($row, $today) && $row->appliesOnDate($today)
        );

        return $assignment !== null ? self::fromAssignment($assignment) : self::fromShift($shift);
    }

    /** Clear the per-request global memo (used in tests after mutating settings). */
    public static function flushGlobal(): void
    {
        self::$global = null;
    }

    /**
     * The logical shift date a punch belongs to: punches at/after the boundary
     * keep their own logdate; punches before it belong to the PREVIOUS day's
     * shift (relevant for a crossing shift's post-midnight tail end).
     *
     * The boundary is today's workStart minus half the off-period duration -
     * an absolute datetime, anchored to today's arrival time, rather than a
     * bare 'HH:MM' comparison. That distinction matters: comparing bare
     * time-of-day strings only works when the off-period's midpoint happens
     * to land in the early morning of the FOLLOWING day (true whenever
     * workStart + workEnd >= 24h, e.g. an 08:00-17:00 shift). A shift where
     * that sum is under 24h (e.g. 07:00-16:00) puts the bare midpoint late in
     * the SAME evening instead, which would make every normal morning
     * arrival compare as "before the boundary" and incorrectly fold onto the
     * previous day. Anchoring to a real datetime avoids that entirely.
     */
    public function shiftDateFor(string $logdate, string $logtime): string
    {
        $date = Carbon::parse($logdate)->startOfDay();
        $punch = Carbon::parse("$logdate ".self::hm($logtime));

        $shiftDuration = $this->crossesMidnight
            ? 1440 - self::toMinutes($this->workStart) + self::toMinutes($this->workEnd)
            : self::toMinutes($this->workEnd) - self::toMinutes($this->workStart);

        $todayStart = $date->copy()->addMinutes(self::toMinutes($this->workStart));
        $boundary = $todayStart->copy()->subMinutes((int) round((1440 - $shiftDuration) / 2));

        return $punch->gte($boundary) ? $date->toDateString() : $date->copy()->subDay()->toDateString();
    }

    /**
     * Build the datetime of a reference time (e.g. workStart, workEnd) relative
     * to a shift date. For a crossing shift, reference times in the post-midnight
     * portion roll onto shiftDate + 1.
     *
     * $isShiftStart must be true for the workStart reference itself. This is the
     * only way to tell it apart from the other references when a shift spans
     * exactly 24h: workStart, morningEnd, lunchReturn and workEnd are then all
     * numerically the same clock value, so the value alone can't say which one
     * is the anchor (never rolls) versus a point elapsed 24h after it.
     */
    public function referenceDateTime(string $shiftDate, string $hhmm, bool $isShiftStart = false): Carbon
    {
        $hhmm = self::hm($hhmm);
        $date = Carbon::parse("$shiftDate $hhmm:00");

        if ($isShiftStart || ! $this->crossesMidnight) {
            return $date;
        }

        $elapsed = self::toMinutes($hhmm) - self::toMinutes($this->workStart);
        if ($elapsed <= 0) {
            $date->addDay();
        }

        return $date;
    }

    /**
     * Calendar date (Y-m-d) a given Form 48 slot's reference time actually falls
     * on for a shift starting on $shiftDate. Always $shiftDate itself for am_in
     * (the anchor, never rolls) and for every slot on a non-crossing shift; rolls
     * to $shiftDate + 1 for am_out/pm_in/pm_out whenever this schedule
     * crossesMidnight and that slot's time is in the post-midnight portion -
     * mirrors referenceDateTime()'s own rule, just returning the date alone.
     */
    public function slotDate(string $shiftDate, string $slot): string
    {
        $time = match ($slot) {
            'am_in' => $this->workStart,
            'am_out' => $this->morningEnd,
            'pm_in' => $this->lunchReturn,
            'pm_out' => $this->workEnd,
        };

        return $this->referenceDateTime($shiftDate, $time, $slot === 'am_in')->toDateString();
    }

    private static function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_pad(explode(':', substr($hhmm, 0, 5)), 2, '0');

        return ((int) $h) * 60 + (int) $m;
    }

    /** Normalize a stored time (HH:MM or HH:MM:SS) to HH:MM; null/empty → null. */
    private static function hm(?string $time): ?string
    {
        $time = trim((string) $time);

        return $time === '' ? null : substr($time, 0, 5);
    }
}
