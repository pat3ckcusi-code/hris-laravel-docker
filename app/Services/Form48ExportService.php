<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\EmployeeShiftSchedule;
use App\Models\Eta;
use App\Models\LeaveDate;
use App\Models\Locator;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkSuspension;
use App\Services\Attendance\ExcludedSlotPunchRecovery;
use App\Services\Attendance\WeeklyPunchPairReconciliationService;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class Form48ExportService
{
    public function __construct(
        private readonly DtrPunchResolver $punchResolver,
        private readonly ShiftPunchGrouper $punchGrouper,
        private readonly WeeklyPunchPairReconciliationService $punchPairReconciliation,
        private readonly ExcludedSlotPunchRecovery $excludedSlotPunchRecovery,
    ) {}

    // Row where day 1 lives: 11 + 1 = 12.
    private const DATA_ROW_OFFSET = 11;

    // Parallel arrays indexed 0–3, one per side-by-side copy of the form.
    private const NAME_CELLS = ['B3',  'J3',  'R3',  'Z3'];   // employee name

    private const POS_CELLS = ['B4',  'J4',  'R4',  'Z4'];   // designation (position)

    private const MONTH_CELLS = ['E5',  'M5',  'U5',  'AC5'];  // "For the Month of"

    private const SIGN_CELLS = ['B48', 'J48', 'R48', 'Z48'];  // certification signature

    private const AM_IN_COLS = ['C',  'K',  'S',  'AA'];

    private const AM_OUT_COLS = ['D',  'L',  'T',  'AB'];

    private const PM_IN_COLS = ['E',  'M',  'U',  'AC'];

    private const PM_OUT_COLS = ['F',  'N',  'V',  'AD'];

    private const UT_HRS_COLS = ['G',  'O',  'W',  'AE'];

    private const UT_MIN_COLS = ['H',  'P',  'X',  'AF'];

    private const TOT_HRS_CELLS = ['G43', 'O43', 'W43', 'AE43'];

    private const TOT_MIN_CELLS = ['H43', 'P43', 'X43', 'AF43'];

    // Columns that get merged/labelled on Saturday and Sunday rows.
    private const WKND_FROM_COLS = ['C',  'K',  'S',  'AA'];

    private const WKND_TO_COLS = ['F',  'N',  'V',  'AD'];

    // ── PUBLIC API ────────────────────────────────────────────────────────────

    /**
     * Build the $records[day] array from dtrs (primary) with an
     * attendance_logs fallback for days that have raw punches but no DTR row.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buildRecords(int $userId, string $from, string $to): array
    {
        $records = [];

        $user = User::find($userId);

        // Warm the shift-assignment-history memo once so the per-date
        // WorkSchedule calls below (here and inside punchGrouper->group()) stay O(1).
        WorkSchedule::preloadShiftAssignments([$userId]);

        // Primary: use the computed DTR rows (accurate AM/PM split + penalties).
        // A stored 0 falls back to the same imputed missing-punch estimate
        // DtrController's DTR page already shows (DtrPunchResolver::imputedLateMinutes()/
        // imputedUndertimeMinutes()) - otherwise this export silently disagrees with the
        // on-screen DTR whenever a sibling punch proves presence but the anchor punch is
        // missing (e.g. AM Out exists, AM In doesn't). Both helpers already no-op back to
        // 0 unless their exact precondition holds, so calling them unconditionally here is
        // harmless on an ordinary complete/on-time day.
        foreach (Dtr::where('employee_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get() as $dtr) {
            $day = (int) $dtr->date->day;
            $dateStr = $dtr->date->format('Y-m-d');
            $daySchedule = WorkSchedule::forUserOnDate($user, $dtr->date);
            $records[$day] = [
                'date' => $dateStr,
                'am_in' => $dtr->time_in_am,
                'am_out' => $dtr->time_out_am,
                'pm_in' => $dtr->time_in_pm,
                'pm_out' => $dtr->time_out_pm,
                'tardiness' => $dtr->late_minutes ?: $this->punchResolver->imputedLateMinutes(
                    $dtr->time_in_am, $dtr->time_out_am, $dtr->time_in_pm, $dtr->time_out_pm, $dateStr, $daySchedule
                ),
                'undertime' => $dtr->undertime_minutes ?: $this->punchResolver->imputedUndertimeMinutes(
                    $dtr->time_in_am, $dtr->time_out_am, $dtr->time_in_pm, $dtr->time_out_pm, $dateStr, $daySchedule
                ),
            ];
        }

        // Fallback: for shifts with attendance_logs but no DTR, derive the slots
        // via the shared grouper + resolver (same logic the import uses). Widen
        // the fetch by a day on each side so night shifts at the range edges are
        // complete.
        $schedule = WorkSchedule::forUserOnDate($user, Carbon::parse($from));
        $pad = $schedule->crossesMidnight ? 1 : 0;

        $logs = AttendanceLog::where('user_id', $userId)
            ->whereBetween('logdate', [
                Carbon::parse($from)->subDays($pad)->toDateString(),
                Carbon::parse($to)->addDays($pad)->toDateString(),
            ])
            ->orderBy('logdate')
            ->orderBy('logtime')
            ->get();

        // Excused/locator-covered slots have no real punch expected - keeps this
        // fallback in agreement with PersonnelLogImportService's resolution.
        $excuseMap = $this->buildExcuseMap($userId, $from, $to);
        $locatorSlotMap = $this->buildLocatorSlotWindowMap($userId, $from, $to);
        $suspensionMap = $this->buildSuspensionMap($from, $to);

        foreach ($this->punchGrouper->group($user, $logs) as $date => $punches) {
            if ($date < $from || $date > $to) {
                continue;   // shift outside the requested range
            }
            $day = (int) Carbon::parse($date)->day;
            if (isset($records[$day])) {
                continue;   // DTR entry already covers this shift
            }

            // A Field Work-style in_only date (Monday, or a reconciliation-
            // written mid-week check-in override) whose week was voided by
            // WeeklyPunchPairReconciliationService must never resurrect its
            // real punch here from attendance_logs (which is never deleted) -
            // otherwise this export would silently disagree with the
            // deliberately-deleted dtrs row everywhere else in the app shows
            // this date as Absent. out_only (Friday) is never voided, so this
            // only ever needs to gate the in_only case.
            $dateSchedule = WorkSchedule::forUserOnDate($user, Carbon::parse($date));
            if ($dateSchedule->punchRequirement === 'in_only'
                && $this->punchPairReconciliation->dateWasVoided($user, Carbon::parse($date))) {
                continue;
            }

            $daySchedule = $schedule;
            $suspensionSlots = [];
            if (($suspension = $suspensionMap[$day] ?? null) !== null && ! $user->isFrontlineExempt()) {
                [$daySchedule, $suspensionSlots] = $schedule->applySuspension($suspension->suspension_time);
            }

            $excludedSlots = array_merge(
                array_fill_keys(($excuseMap[$day] ?? null)?->excludedSlotKeys() ?? [], null),
                $locatorSlotMap[$date] ?? [],
                $suspensionSlots
            );
            $resolved = $this->punchResolver->resolve($punches, $date, $daySchedule, $excludedSlots);

            $records[$day] = [
                'date' => $date,
                'am_in' => $resolved['am_in'],
                'am_out' => $resolved['am_out'],
                'pm_in' => $resolved['pm_in'],
                'pm_out' => $resolved['pm_out'],
                'tardiness' => $resolved['late_minutes'] ?: $this->punchResolver->imputedLateMinutes(
                    $resolved['am_in'], $resolved['am_out'], $resolved['pm_in'], $resolved['pm_out'], $date, $daySchedule
                ),
                'undertime' => $resolved['undertime_minutes'] ?: $this->punchResolver->imputedUndertimeMinutes(
                    $resolved['am_in'], $resolved['am_out'], $resolved['pm_in'], $resolved['pm_out'], $date, $daySchedule
                ),
            ];
        }

        return $records;
    }

    /**
     * Build a day-of-month → leave entry map for the given user and period.
     * Only approved, non-cancelled leave dates are included. 'days' < 1 means
     * a half-day leave, which must not hide a real punch for the half of the
     * day actually worked - see the per-slot fallback in fillDailyRows().
     *
     * @return array<int, array{code: string, days: float}> e.g. [3 => ['code' => 'VL', 'days' => 1.0], 10 => ['code' => 'SL', 'days' => 0.5]]
     */
    public function buildLeaveMap(int $userId, string $from, string $to): array
    {
        $map = [];

        LeaveDate::query()
            ->join('leave_requests', 'leave_dates.leave_request_id', '=', 'leave_requests.id')
            ->where('leave_requests.user_id', $userId)
            ->where('leave_requests.status', 'approved')
            ->where('leave_dates.is_cancelled', false)
            ->whereBetween('leave_dates.leave_date', [$from, $to])
            ->select('leave_dates.leave_date', 'leave_dates.is_lwop', 'leave_dates.days', 'leave_requests.leave_type', 'leave_requests.details_others_type')
            ->get()
            ->each(function ($row) use (&$map): void {
                $day = (int) Carbon::parse($row->leave_date)->day;
                $map[$day] = [
                    'code' => $row->is_lwop ? 'LWOP' : self::toLeaveCode($row->leave_type, $row->details_others_type),
                    'days' => (float) $row->days,
                ];
            });

        return $map;
    }

    /** Map full leave-type text to CSC Form 48 abbreviation. */
    public static function toLeaveCode(?string $leaveType, ?string $othersType = null): string
    {
        $lt = $leaveType ?? '';

        return match (true) {
            str_contains($lt, 'Vacation') => 'VL',
            str_contains($lt, 'Sick') => 'SL',
            str_contains($lt, 'Wellness') => 'WLNS',
            str_contains($lt, 'Compensatory') => 'CTO',
            str_contains($lt, 'Special Privilege') => 'SPL',
            str_contains($lt, 'Solo Parent') => 'SP',
            str_contains($lt, 'Maternity') => 'ML',
            str_contains($lt, 'Paternity') => 'PL',
            str_contains($lt, 'Forced') => 'FL',
            str_contains($lt, 'Mandatory') => 'FL',
            str_contains(strtolower($lt), 'others') => $othersType ?: 'OTHERS',
            default => strtoupper(trim($lt)) ?: 'LEAVE',
        };
    }

    /**
     * Build a day-of-month → true map for all days covered by approved ETA records.
     * An ETA spans from departure_date to arrival_date (inclusive); if arrival_date
     * is null it covers only the departure_date.
     *
     * @return array<int, true> e.g. [5 => true, 6 => true, 7 => true]
     */
    public function buildEtaMap(int $userId, string $from, string $to): array
    {
        $map = [];

        Eta::where('user_id', $userId)
            ->where('status', 'approved')
            ->where('departure_date', '<=', $to)
            ->where(function ($q) use ($from): void {
                $q->whereNull('arrival_date')->orWhere('arrival_date', '>=', $from);
            })
            ->get(['departure_date', 'arrival_date'])
            ->each(function ($eta) use (&$map, $from, $to): void {
                $start = Carbon::parse($eta->departure_date)->max(Carbon::parse($from));
                $end = $eta->arrival_date
                    ? Carbon::parse($eta->arrival_date)->min(Carbon::parse($to))
                    : Carbon::parse($eta->departure_date);

                for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                    $map[$d->day] = true;
                }
            });

        return $map;
    }

    /**
     * Build a day-of-month → DtrExcuse map for the given user and period.
     *
     * @return array<int, DtrExcuse>
     */
    public function buildExcuseMap(int $userId, string $from, string $to): array
    {
        $map = [];

        DtrExcuse::where('user_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->get()
            ->each(function ($excuse) use (&$map): void {
                $map[(int) Carbon::parse($excuse->date)->day] = $excuse;
            });

        return $map;
    }

    /**
     * Build a day-of-month → WorkSuspension map for the period (company-wide,
     * no user scoping needed). Used the same way as buildExcuseMap() to keep
     * the attendance_logs fallback path in agreement with
     * PersonnelLogImportService's resolution - see WorkSchedule::applySuspension().
     */
    public function buildSuspensionMap(string $from, string $to): array
    {
        $map = [];

        WorkSuspension::whereBetween('suspension_date', [$from, $to])
            ->get()
            ->each(function (WorkSuspension $suspension) use (&$map): void {
                $map[(int) Carbon::parse($suspension->suspension_date)->day] = $suspension;
            });

        return $map;
    }

    /**
     * Build a day-of-month → excluded-slot-keys map for company-wide work
     * suspensions that actually exclude at least one Form 48 slot. Mirrors
     * DtrController::data()'s per-row applySuspension() resolution and its
     * `!empty($suspensionSlots)` gate - a cutoff-only suspension (caps workEnd,
     * excludes nothing) needs no label here, only the schedule adjustment
     * buildRecords() already applies upstream.
     *
     * @return array<int, array<string, true>>
     */
    private function buildSuspensionSlotsMap(int $userId, string $from, string $to): array
    {
        $map = [];
        $user = User::find($userId);
        if (! $user || $user->isFrontlineExempt()) {
            return $map;
        }

        WorkSuspension::whereBetween('suspension_date', [$from, $to])
            ->get()
            ->each(function (WorkSuspension $suspension) use (&$map, $user): void {
                $date = Carbon::parse($suspension->suspension_date);
                $schedule = WorkSchedule::forUserOnDate($user, $date);
                [, $suspensionSlots] = $schedule->applySuspension($suspension->suspension_time);
                if (! empty($suspensionSlots)) {
                    $map[(int) $date->day] = $suspensionSlots;
                }
            });

        return $map;
    }

    /**
     * Build a day-of-month → slot-coverage map for approved locators on each day.
     * Multiple locators on the same day are OR-merged; departure/arrival form the
     * union window (earliest departure, latest arrival) used for punch redistribution.
     *
     * Coverage is resolved per-date via Locator::coveredSlotKeys() against that
     * date's actual WorkSchedule - the same function and schedule resolution
     * PersonnelLogImportService uses when excluding slots at DTR-write time, so
     * the export and the underlying dtrs row can never disagree on which slot is
     * "covered".
     *
     * @return array<int, array{covers_am_in: bool, covers_am_out: bool, covers_pm_in: bool, covers_pm_out: bool, departure: string, arrival: string}>
     */
    public function buildLocatorMap(int $userId, string $from, string $to): array
    {
        $map = [];
        $user = User::find($userId);
        $assignments = EmployeeShiftSchedule::where('user_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->with('shift')
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        Locator::where('user_id', $userId)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$from, $to])
            ->get(['travel_date', 'intended_departure_time', 'intended_arrival_time'])
            ->each(function ($locator) use (&$map, $user, $assignments): void {
                $day = (int) Carbon::parse($locator->travel_date)->day;
                $dep = substr((string) $locator->intended_departure_time, 0, 5);
                $arr = substr((string) $locator->intended_arrival_time, 0, 5);
                $schedule = WorkSchedule::forUserOnDate($user, Carbon::parse($locator->travel_date), $assignments);

                $cur = $map[$day] ?? [
                    'covers_am_in' => false,
                    'covers_am_out' => false,
                    'covers_pm_in' => false,
                    'covers_pm_out' => false,
                    'departure' => $dep,
                    'arrival' => $arr,
                ];

                foreach (Locator::coveredSlotKeys($dep, $arr, $schedule) as $slotKey) {
                    $cur["covers_{$slotKey}"] = true;
                }

                // Union the travel window so punch redistribution spans all locators for the day.
                $cur['departure'] = min($cur['departure'], $dep);
                $cur['arrival'] = max($cur['arrival'], $arr);

                $map[$day] = $cur;
            });

        return $map;
    }

    /**
     * Build a date-string → slot-key → exclusion window map for a user's approved
     * locators in range, for the DtrPunchResolver fallback in fill(). Unlike
     * buildLocatorMap()'s day-level departure/arrival union (kept as-is for
     * display), this unions per SLOT - two same-day locators covering different
     * slots keep their own distinct windows instead of borrowing each other's -
     * mirroring PersonnelLogImportService::buildLocatorSlotMap() so import and
     * this export fallback can never disagree on a covered slot's window.
     *
     * @return array<string, array<string, array{0:string,1:string}>>
     */
    private function buildLocatorSlotWindowMap(int $userId, string $from, string $to): array
    {
        $map = [];
        $user = User::find($userId);
        $assignments = EmployeeShiftSchedule::where('user_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->with('shift')
            ->get()
            ->keyBy(fn ($a) => $a->date->toDateString());

        Locator::where('user_id', $userId)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$from, $to])
            ->get(['travel_date', 'intended_departure_time', 'intended_arrival_time'])
            ->each(function ($locator) use (&$map, $user, $assignments): void {
                $dateStr = Carbon::parse($locator->travel_date)->format('Y-m-d');
                $schedule = WorkSchedule::forUserOnDate($user, Carbon::parse($locator->travel_date), $assignments);
                $windows = Locator::coveredSlotWindows(
                    (string) $locator->intended_departure_time,
                    (string) $locator->intended_arrival_time,
                    $schedule
                );

                foreach ($windows as $slotKey => $window) {
                    $existing = $map[$dateStr][$slotKey] ?? null;
                    $map[$dateStr][$slotKey] = $existing === null
                        ? $window
                        : [min($existing[0], $window[0]), max($existing[1], $window[1])];
                }
            });

        return $map;
    }

    /**
     * Build a day-of-month → true map for scheduled rest days (null shift_id assignment).
     * Used by fill() to label those days as "Rest Day" instead of treating them as absences.
     *
     * @return array<int, true>
     */
    public function buildRestDayMap(int $userId, string $from, string $to): array
    {
        $map = [];

        EmployeeShiftSchedule::where('user_id', $userId)
            ->whereNull('shift_id')
            ->where('type', 'rest')
            ->whereBetween('date', [$from, $to])
            ->get(['date'])
            ->each(function ($a) use (&$map): void {
                $map[(int) $a->date->day] = true;
            });

        return $map;
    }

    public function buildFieldWorkMap(int $userId, string $from, string $to): array
    {
        $map = [];

        EmployeeShiftSchedule::where('user_id', $userId)
            ->where('type', 'field_work')
            ->whereBetween('date', [$from, $to])
            ->get(['date'])
            ->each(function ($a) use (&$map): void {
                $map[(int) $a->date->day] = true;
            });

        return $map;
    }

    public function buildWfhMap(int $userId, string $from, string $to): array
    {
        $map = [];

        EmployeeShiftSchedule::where('user_id', $userId)
            ->where('type', 'wfh')
            ->whereBetween('date', [$from, $to])
            ->get(['date'])
            ->each(function ($a) use (&$map): void {
                $map[(int) $a->date->day] = true;
            });

        return $map;
    }

    /**
     * Build a day-of-month → office_order_num map for office orders covering this user.
     * Expands each order to every day from issued_date through effective_date (or
     * just issued_date if effective_date isn't set), clamped to the period.
     *
     * @return array<int, string> e.g. [15 => '2026 - 003']
     */
    public function buildOfficeOrderMap(int $userId, string $from, string $to): array
    {
        $map = [];
        $user = User::find($userId);
        if (! $user || ! $user->EmpNo) {
            return $map;
        }

        $rangeStart = Carbon::parse($from);
        $rangeEnd = Carbon::parse($to);

        DB::table('office_orders')
            ->join('office_order_employees', 'office_orders.id', '=', 'office_order_employees.office_order_id')
            ->where('office_order_employees.emp_no', $user->EmpNo)
            ->where('office_orders.status', '!=', 'Cancelled')
            ->where('office_orders.issued_date', '<=', $to)
            ->where(function ($q) use ($from): void {
                $q->where('office_orders.effective_date', '>=', $from)
                    ->orWhere(function ($q2) use ($from): void {
                        $q2->whereNull('office_orders.effective_date')
                            ->where('office_orders.issued_date', '>=', $from);
                    });
            })
            ->select('office_orders.office_order_num', 'office_orders.effective_date', 'office_orders.issued_date')
            ->get()
            ->each(function ($o) use (&$map, $rangeStart, $rangeEnd): void {
                if (! $o->issued_date) {
                    return;
                }
                $cursor = Carbon::parse($o->issued_date)->startOfDay();
                $until = Carbon::parse($o->effective_date ?? $o->issued_date)->startOfDay();
                $cursor = $cursor->lt($rangeStart) ? $rangeStart->copy() : $cursor;
                $until = $until->gt($rangeEnd) ? $rangeEnd->copy() : $until;
                for (; $cursor->lte($until); $cursor->addDay()) {
                    $map[(int) $cursor->day] = $o->office_order_num;
                }
            });

        return $map;
    }

    /**
     * Build a day-of-month → travel_order_num map for approved travel orders
     * covering this user. Mirrors buildOfficeOrderMap()'s query shape and
     * priority tier - Travel Order structurally mirrors Office Order elsewhere
     * in this codebase (see DtrController::data()'s identical travelOrderDateMap).
     *
     * @return array<int, string> e.g. [15 => 'TO-2026-003']
     */
    public function buildTravelOrderMap(int $userId, string $from, string $to): array
    {
        $map = [];
        $user = User::find($userId);
        if (! $user || ! $user->EmpNo) {
            return $map;
        }

        $rangeStart = Carbon::parse($from);
        $rangeEnd = Carbon::parse($to);

        DB::table('travel_orders')
            ->join('travel_order_employees', 'travel_orders.id', '=', 'travel_order_employees.travel_order_id')
            ->where('travel_order_employees.emp_no', $user->EmpNo)
            ->where('travel_orders.status', 'Approved')
            ->where('travel_orders.start_date', '<=', $to)
            ->where('travel_orders.end_date', '>=', $from)
            ->select('travel_orders.travel_order_num', 'travel_orders.start_date', 'travel_orders.end_date')
            ->get()
            ->each(function ($o) use (&$map, $rangeStart, $rangeEnd): void {
                $cursor = Carbon::parse($o->start_date)->startOfDay();
                $until = Carbon::parse($o->end_date)->startOfDay();
                $cursor = $cursor->lt($rangeStart) ? $rangeStart->copy() : $cursor;
                $until = $until->gt($rangeEnd) ? $rangeEnd->copy() : $until;
                for (; $cursor->lte($until); $cursor->addDay()) {
                    $map[(int) $cursor->day] = $o->travel_order_num;
                }
            });

        return $map;
    }

    /**
     * Resolve a day's display slots against a locator's coverage.
     *
     * Biometric punches always take priority: a slot shows its real punch when
     * one exists, regardless of locator coverage. "LOCATOR" is only a fallback
     * for a covered slot that has no real punch - DtrPunchResolver is locator-aware
     * (see PersonnelLogImportService::upsertDtrRecords()) so punches are already
     * in their correct slot by the time they reach here.
     *
     * Made public static so DtrController can call it for the DataTable preview.
     *
     * @param  array{covers_am_in: bool, covers_am_out: bool, covers_pm_in: bool, covers_pm_out: bool, departure: string, arrival: string}  $locator
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null} [amIn, amOut, pmIn, pmOut]
     */
    public static function resolveLocatorSlots(
        ?string $rawAmIn,
        ?string $rawAmOut,
        ?string $rawPmIn,
        ?string $rawPmOut,
        array $locator
    ): array {
        $raw = [$rawAmIn, $rawAmOut, $rawPmIn, $rawPmOut];
        $covered = [
            $locator['covers_am_in'],
            $locator['covers_am_out'],
            $locator['covers_pm_in'],
            $locator['covers_pm_out'],
        ];

        return array_map(
            fn (?string $v, bool $isCovered) => ($v !== null && $v !== '')
                ? substr($v, 0, 5)
                : ($isCovered ? 'LOCATOR' : null),
            $raw,
            $covered
        );
    }

    /**
     * Recompute tardiness + undertime from RESOLVED slot values (after locator redistribution).
     * Mirrors the general shape of the App\Services\Attendance calculators (late from IN
     * events, undertime from OUT events) per slot, so that only the specific slot covered
     * by the locator loses its penalty - not all tardiness at once. Deliberately NOT a
     * byte-for-byte match: this only recomputes am_in/pm_in/pm_out (no am_out undertime
     * term - a locator never displaces a break-out punch) and bounds pm_in lateness at
     * noonEnd rather than workEnd. Both are pre-existing, intentional divergences from
     * UndertimeCalculator/LateCalculator; this method exists purely to redraw the display
     * after locator substitution, never to write dtrs columns.
     *
     * @return array{0: int, 1: int} [tardiness_minutes, undertime_minutes]
     */
    public static function computeSlotPenalties(
        string $date,
        string $amIn,
        string $pmIn,
        string $pmOut,
        WorkSchedule $schedule
    ): array {
        $tardiness = 0;
        $undertime = 0;

        if ($amIn !== '' && $amIn !== 'LOCATOR' && $amIn !== 'EXCUSED' && $amIn !== 'SUSPENDED') {
            $hm = substr($amIn, 0, 5);
            if ($hm > $schedule->workStart && $hm < $schedule->morningEnd) {
                $tardiness += (int) Carbon::parse("$date $schedule->workStart")->diffInMinutes(Carbon::parse("$date $hm"));
            }
        }

        if ($pmIn !== '' && $pmIn !== 'LOCATOR' && $pmIn !== 'EXCUSED' && $pmIn !== 'SUSPENDED') {
            $hm = substr($pmIn, 0, 5);
            if ($hm > $schedule->lunchReturn && $hm < $schedule->noonEnd) {
                $tardiness += (int) Carbon::parse("$date $schedule->lunchReturn")->diffInMinutes(Carbon::parse("$date $hm"));
            }
        }

        if ($pmOut !== '' && $pmOut !== 'LOCATOR' && $pmOut !== 'EXCUSED' && $pmOut !== 'SUSPENDED') {
            $hm = substr($pmOut, 0, 5);
            $pmOutLower = $schedule->noBreak ? $schedule->workStart : $schedule->lunchReturn;
            if ($hm >= $pmOutLower && $hm < $schedule->workEnd) {
                $undertime += (int) Carbon::parse("$date $hm")->diffInMinutes(Carbon::parse("$date $schedule->workEnd"));
            }
        }

        return [$tardiness, $undertime];
    }

    /**
     * Populate all cells on the Form 48 sheet.
     *
     * @param  array<int, array<string, mixed>>  $records  Keyed by day-of-month (1–31)
     * @param  array<int, array{code: string, days: float}>  $leaveMap  From buildLeaveMap()
     * @param  array<int, true>  $etaMap  From buildEtaMap()
     */
    public function fill(
        Worksheet $sheet,
        array $records,
        User $employee,
        string $monthYear,
        string $from,
        array $leaveMap = [],
        array $etaMap = [],
        array $locatorMap = [],
        array $restDayMap = [],
        array $fieldWorkMap = [],
        array $excuseMap = [],
        array $officeOrderMap = [],
        array $wfhMap = [],
        array $travelOrderMap = []
    ): void {
        $name = $this->formatName($employee);
        $designation = trim($employee->designation ?? '');
        $schedule = WorkSchedule::forUser($employee);
        // Mirrors fillDailyRows()'s own year/month derivation from $from - this is a
        // monthly form, so the suspension lookup always spans that whole month.
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $suspensionSlotsMap = $this->buildSuspensionSlotsMap($employee->id, $from, $to);

        // Recover real biometric punches that a DtrExcuse/WorkSuspension's
        // unconditional slot exclusion swallowed before they could ever reach
        // the display fallback below - see ExcludedSlotPunchRecovery's own
        // docblock for why this can't be done with a naive unmatched_logs
        // sequential fill. $excuseMap/$suspensionSlotsMap are day-of-month
        // indexed; the recovery service needs real dates.
        $excludedSlotsByDate = [];
        $periodStart = Carbon::parse($from);
        $dayToDate = fn (int $day): string => Carbon::createFromDate((int) $periodStart->year, (int) $periodStart->month, $day)->format('Y-m-d');
        foreach ($excuseMap as $day => $excuse) {
            if (($keys = $excuse->excludedSlotKeys()) !== []) {
                $excludedSlotsByDate[$dayToDate($day)] = array_fill_keys($keys, null);
            }
        }
        foreach ($suspensionSlotsMap as $day => $slots) {
            $dateStr = $dayToDate($day);
            $excludedSlotsByDate[$dateStr] = array_merge($excludedSlotsByDate[$dateStr] ?? [], $slots);
        }
        $recoveredByDate = $this->excludedSlotPunchRecovery->recover($employee, $from, $to, $excludedSlotsByDate);
        $recoveredMap = [];
        foreach ($recoveredByDate as $dateStr => $slots) {
            $recoveredMap[Carbon::parse($dateStr)->day] = $slots;
        }

        $this->fillHeader($sheet, $name, $designation, $monthYear);
        $this->fillDailyRows($sheet, $records, $from, $leaveMap, $etaMap, $locatorMap, $schedule, $restDayMap, $fieldWorkMap, $excuseMap, $officeOrderMap, $wfhMap, $travelOrderMap, $suspensionSlotsMap, $recoveredMap);

        // Exclude rest days, leave days, and field work/WFH/ETA/OO/TO days with fewer
        // than 4 punches from the total (mirrors fillDailyRows()'s punch-count gating
        // for those same day types - a day with all 4 slots punched counts like any
        // normal day). For locator days, zero the penalty for each covered slot that
        // lacks a punch. For excused/suspended slots, substitute 'EXCUSED' so
        // computeSlotPenalties() skips them.
        $totalMins = 0;
        foreach ($records as $day => $r) {
            if (isset($restDayMap[$day])) {
                continue;
            }
            if (isset($leaveMap[$day])) {
                if (($leaveMap[$day]['days'] ?? 1.0) >= 1.0) {
                    continue;
                }
                // Half-day leave: recompute penalties from only the slots without a
                // real punch (leave-covered) excluded - mirrors the excuse branch
                // below, so the half actually worked still contributes to the total.
                $coversAmIn = empty($r['am_in'] ?? null);
                $coversPmIn = empty($r['pm_in'] ?? null);
                $coversPmOut = empty($r['pm_out'] ?? null);
                [$tardiness, $undertime] = self::computeSlotPenalties(
                    $r['date'],
                    $coversAmIn ? '' : ($r['am_in'] ?? ''),
                    $coversPmIn ? '' : ($r['pm_in'] ?? ''),
                    $coversPmOut ? '' : ($r['pm_out'] ?? ''),
                    $schedule
                );
                $totalMins += $tardiness + $undertime;

                continue;
            }
            if ((isset($fieldWorkMap[$day]) || isset($wfhMap[$day])) && ! isset($etaMap[$day])
                && ! isset($officeOrderMap[$day]) && ! isset($travelOrderMap[$day])
                && ! isset($excuseMap[$day]) && ! isset($suspensionSlotsMap[$day]) && ! isset($locatorMap[$day])) {
                $fwPunches = count(array_filter([
                    $r['am_in'] ?? null, $r['am_out'] ?? null,
                    $r['pm_in'] ?? null, $r['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                if ($fwPunches < 4) {
                    continue;
                }
            }
            if (isset($etaMap[$day])) {
                $etaPunches = count(array_filter([
                    $r['am_in'] ?? null, $r['am_out'] ?? null,
                    $r['pm_in'] ?? null, $r['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                if ($etaPunches < 4) {
                    continue;
                }
            }
            if (isset($officeOrderMap[$day])) {
                $ooPunches = count(array_filter([
                    $r['am_in'] ?? null, $r['am_out'] ?? null,
                    $r['pm_in'] ?? null, $r['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                if ($ooPunches < 4) {
                    continue;
                }
            }
            if (isset($travelOrderMap[$day])) {
                $toPunches = count(array_filter([
                    $r['am_in'] ?? null, $r['am_out'] ?? null,
                    $r['pm_in'] ?? null, $r['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                if ($toPunches < 4) {
                    continue;
                }
            }
            if (isset($excuseMap[$day])) {
                $excuse = $excuseMap[$day];
                $exAmIn = ($excuse->excuse_am_in || $excuse->is_full_day) ? 'EXCUSED' : ($r['am_in'] ?? '');
                $exPmIn = ($excuse->excuse_pm_in || $excuse->is_full_day) ? 'EXCUSED' : ($r['pm_in'] ?? '');
                $exPmOut = ($excuse->excuse_pm_out || $excuse->is_full_day) ? 'EXCUSED' : ($r['pm_out'] ?? '');
                [$tardiness, $undertime] = self::computeSlotPenalties($r['date'], $exAmIn, $exPmIn, $exPmOut, $schedule);
                $totalMins += $tardiness + $undertime;

                continue;
            }
            if (isset($suspensionSlotsMap[$day])) {
                $slots = $suspensionSlotsMap[$day];
                $sAmIn = array_key_exists('am_in', $slots) ? 'EXCUSED' : ($r['am_in'] ?? '');
                $sPmIn = array_key_exists('pm_in', $slots) ? 'EXCUSED' : ($r['pm_in'] ?? '');
                $sPmOut = array_key_exists('pm_out', $slots) ? 'EXCUSED' : ($r['pm_out'] ?? '');
                [$tardiness, $undertime] = self::computeSlotPenalties($r['date'], $sAmIn, $sPmIn, $sPmOut, $schedule);
                $totalMins += $tardiness + $undertime;

                continue;
            }
            if (isset($locatorMap[$day])) {
                [$lAmIn, , $lPmIn, $lPmOut] = self::resolveLocatorSlots(
                    $r['am_in'] ?? null, $r['am_out'] ?? null,
                    $r['pm_in'] ?? null, $r['pm_out'] ?? null,
                    $locatorMap[$day]
                );
                [$tardiness, $undertime] = self::computeSlotPenalties(
                    $r['date'], $lAmIn ?? '', $lPmIn ?? '', $lPmOut ?? '', $schedule
                );
            } else {
                $tardiness = $r['tardiness'] ?? 0;
                $undertime = $r['undertime'] ?? 0;
            }
            $totalMins += $tardiness + $undertime;
        }
        $this->fillTotals($sheet, $totalMins);
        $this->fillCertification($sheet, $name, $designation);

        // Freeze the finished sheet (must run after all values are written).
        $this->lockSheet($sheet);
    }

    /**
     * Apply full, password-protected sheet protection. Every cell is locked by
     * default in the template and we unlock nothing, so all data ranges
     * (C–F, K–N, S–V, AA–AD included) stay read-only without the password.
     */
    private function lockSheet(Worksheet $sheet): void
    {
        $s = Setting::first();
        $enabled = $s?->excel_protection_enabled ?? (bool) env('EXCEL_EXPORT_PROTECTION_ENABLED', true);
        if (! $enabled) {
            return;
        }
        $password = $s?->excel_sheet_password ?? env('EXCEL_EXPORT_SHEET_PASSWORD', '');
        $sheet->getProtection()
            ->setSheet(true)
            ->setPassword((string) $password);
    }

    /**
     * Format name as "LASTNAME, Firstname M." - same convention as the rest of HRIS.
     */
    public function formatName(User $employee): string
    {
        $first = trim($employee->first_name ?? '');
        $last = trim($employee->last_name ?? '');
        $middle = trim($employee->middle_name ?? '');
        $mi = $middle !== '' ? strtoupper(mb_substr($middle, 0, 1)).'.' : '';

        return trim(implode(' ', array_filter([
            $last.($last ? ',' : ''),
            $first,
            $mi,
        ])));
    }

    // ── PRIVATE ───────────────────────────────────────────────────────────────

    /**
     * Writes a "real punch times for filled slots, remaining (assumed
     * sequential am_in → am_out → pm_in → pm_out) empty slots merged into one
     * $label cell" row - the same merge pattern the Office Order branch below
     * uses, shared here for Field Work/WFH so all three don't duplicate it.
     *
     * @param  array<string, mixed>|null  $rec
     */
    private function writeSequentialPartialPunchLabel(Worksheet $sheet, int $row, ?array $rec, int $punchCount, string $label): void
    {
        $fmt = fn (?string $t): string => ($t !== null && $t !== '') ? substr($t, 0, 5) : '';

        foreach (range(0, 3) as $i) {
            $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
            $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');

            match ($punchCount) {
                0 => (function () use ($sheet, $i, $row, $label): void {
                    $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                    try {
                        $sheet->mergeCells($range);
                    } catch (\Throwable) {
                    }
                    $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, $label);
                    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                })(),

                1 => (function () use ($sheet, $i, $row, $rec, $fmt, $label): void {
                    $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $fmt($rec['am_in'] ?? null));
                    $sheet->getStyle(self::AM_IN_COLS[$i].$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $range = self::AM_OUT_COLS[$i].$row.':'.self::PM_OUT_COLS[$i].$row;
                    try {
                        $sheet->mergeCells($range);
                    } catch (\Throwable) {
                    }
                    $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $label);
                    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                })(),

                2 => (function () use ($sheet, $i, $row, $rec, $fmt, $label): void {
                    $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $fmt($rec['am_in'] ?? null));
                    $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $fmt($rec['am_out'] ?? null));
                    foreach ([self::AM_IN_COLS[$i], self::AM_OUT_COLS[$i]] as $col) {
                        $sheet->getStyle($col.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                    $range = self::PM_IN_COLS[$i].$row.':'.self::PM_OUT_COLS[$i].$row;
                    try {
                        $sheet->mergeCells($range);
                    } catch (\Throwable) {
                    }
                    $sheet->setCellValue(self::PM_IN_COLS[$i].$row, $label);
                    $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                })(),

                default => (function () use ($sheet, $i, $row, $rec, $fmt, $label): void {
                    // 3 punches: show am_in, am_out, pm_in; pm_out = $label
                    $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $fmt($rec['am_in'] ?? null));
                    $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $fmt($rec['am_out'] ?? null));
                    $sheet->setCellValue(self::PM_IN_COLS[$i].$row, $fmt($rec['pm_in'] ?? null));
                    $sheet->setCellValue(self::PM_OUT_COLS[$i].$row, $label);
                    foreach ([
                        self::AM_IN_COLS[$i], self::AM_OUT_COLS[$i],
                        self::PM_IN_COLS[$i], self::PM_OUT_COLS[$i],
                    ] as $col) {
                        $sheet->getStyle($col.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                })(),
            };
        }
    }

    private function fillHeader(
        Worksheet $sheet,
        string $name,
        string $designation,
        string $monthYear
    ): void {
        foreach (self::NAME_CELLS as $i => $cell) {
            $sheet->setCellValue($cell, $name);
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        // Designation goes in the row directly below the name field (row 4).
        // The "Name" label at D4/L4/R4/Z4 is a template caption; B4/J4/R4/Z4 are empty.
        if ($designation !== '') {
            foreach (self::POS_CELLS as $cell) {
                $sheet->setCellValue($cell, $designation);
                $sheet->getStyle($cell)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_LEFT);
            }
        }

        foreach (self::MONTH_CELLS as $cell) {
            $sheet->setCellValue($cell, $monthYear);
            $sheet->getStyle($cell)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
    }

    private function fillDailyRows(
        Worksheet $sheet,
        array $records,
        string $from,
        array $leaveMap,
        array $etaMap,
        array $locatorMap,
        WorkSchedule $schedule,
        array $restDayMap = [],
        array $fieldWorkMap = [],
        array $excuseMap = [],
        array $officeOrderMap = [],
        array $wfhMap = [],
        array $travelOrderMap = [],
        array $suspensionSlotsMap = [],
        array $recoveredMap = []
    ): void {
        $date = Carbon::parse($from);
        $year = (int) $date->year;
        $month = (int) $date->month;
        $daysInMonth = (int) $date->daysInMonth;

        // Reusable HH:MM:SS → HH:MM formatter.
        $fmt = fn (?string $t): string => ($t !== null && $t !== '') ? substr($t, 0, 5) : '';

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $row = self::DATA_ROW_OFFSET + $day;
            $dayDate = Carbon::createFromDate($year, $month, $day);

            $rec = $records[$day] ?? null;
            $isWeekend = $dayDate->isSaturday() || $dayDate->isSunday();
            $leaveCode = $leaveMap[$day]['code'] ?? null;

            // A crossing shift's checkout physically happens on the day AFTER
            // $day - none of the leave/ETA/OO/excuse checks above (or below)
            // look at the day after $day, only $day itself, so a shift whose
            // only explanation for a missing checkout is a whole-day ETA/Office
            // Order/full-day Leave filed for the *next* day was invisible here
            // (e.g. a 24-on/24-off shift starting the day before an Office Order
            // that pulled the employee straight into an all-day event instead of
            // back to post to punch out). $travelOrderMap/$suspensionSlotsMap are
            // now available at this layer for the regular per-day branches below,
            // but this next-day fallback intentionally isn't extended to them -
            // only these three sources are checked - matches the equivalent fix
            // in DtrController::data()/AttendanceMonitoringExportService. Since
            // crossesMidnight always means workEnd's clock value is <= workStart's
            // (that's the definition of a crossing shift), the checkout always
            // resolves to exactly $day + 1 - no per-slot date math needed here.
            $pmOutCoveredNextDay = false;
            $pmOutFallbackLabel = null;
            if ($schedule->crossesMidnight && $day < $daysInMonth) {
                $nextDay = $day + 1;
                if (isset($etaMap[$nextDay])) {
                    $pmOutCoveredNextDay = true;
                    $pmOutFallbackLabel = 'ETA';
                } elseif (isset($officeOrderMap[$nextDay])) {
                    $pmOutCoveredNextDay = true;
                    $pmOutFallbackLabel = 'Office Order';
                } elseif (($leaveMap[$nextDay]['days'] ?? 0) >= 1.0) {
                    $pmOutCoveredNextDay = true;
                    $pmOutFallbackLabel = $leaveMap[$nextDay]['code'];
                }
            }

            // Per-date shift rest day: always shows "Rest Day", even if a stale DTR record exists.
            if (isset($restDayMap[$day])) {
                foreach (range(0, 3) as $i) {
                    $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                    try {
                        $sheet->mergeCells($range);
                    } catch (\Throwable) {
                    }
                    $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, 'Rest Day');
                    $sheet->getStyle($range)->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                    $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');
                }

                continue;
            }

            // Field work day: same sequential partial-punch merge pattern as Office Order
            // below - real times for filled slots, remaining empty slots merged into one
            // "Field Work" cell, so a real biometric punch is never discarded. Only when no
            // real, approved event (leave/ETA/OO/TO/excuse/suspension/locator) also covers
            // this date - those take priority, same as the ordering already established
            // below for ETA/OO/TO/excuse/suspension/locator relative to each other. If all
            // 4 slots are present, fall through to the normal write below.
            if (isset($fieldWorkMap[$day]) && ! isset($leaveMap[$day]) && ! isset($etaMap[$day])
                && ! isset($officeOrderMap[$day]) && ! isset($travelOrderMap[$day])
                && ! isset($excuseMap[$day]) && ! isset($suspensionSlotsMap[$day]) && ! isset($locatorMap[$day])) {
                $punchCount = $rec ? count(array_filter([
                    $rec['am_in'] ?? null, $rec['am_out'] ?? null,
                    $rec['pm_in'] ?? null, $rec['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== '')) : 0;

                if ($punchCount < 4) {
                    $this->writeSequentialPartialPunchLabel($sheet, $row, $rec, $punchCount, 'Field Work');

                    continue;
                }
                // punchCount === 4: fall through to normal write below.
            }

            // Work-from-home day: same sequential partial-punch merge pattern as field
            // work above. Same priority rule - real events (leave/ETA/OO/TO/excuse/
            // suspension/locator) take precedence over this label when both cover the
            // same date. If all 4 slots are present, fall through to the normal write below.
            if (isset($wfhMap[$day]) && ! isset($leaveMap[$day]) && ! isset($etaMap[$day])
                && ! isset($officeOrderMap[$day]) && ! isset($travelOrderMap[$day])
                && ! isset($excuseMap[$day]) && ! isset($suspensionSlotsMap[$day]) && ! isset($locatorMap[$day])) {
                $punchCount = $rec ? count(array_filter([
                    $rec['am_in'] ?? null, $rec['am_out'] ?? null,
                    $rec['pm_in'] ?? null, $rec['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== '')) : 0;

                if ($punchCount < 4) {
                    $this->writeSequentialPartialPunchLabel($sheet, $row, $rec, $punchCount, 'Work From Home');

                    continue;
                }
                // punchCount === 4: fall through to normal write below.
            }

            // Approved leave: a real punch always wins over the leave code, even on
            // a full-day leave date - biometric attendance takes priority first, and
            // the leave code only fills a slot with no real punch (same convention
            // as the Excuse branch below). "Which half" isn't stored anywhere for a
            // half-day leave, so it's inferred from the punch data itself. A
            // half-day leave still recomputes tardiness/undertime from only the
            // slots without a real punch excluded, so the half actually worked
            // still charges genuine lateness/undertime instead of being
            // blanket-zeroed - a full-day leave keeps zeroing both regardless of any
            // incidental punch, since no work obligation exists that day at all. A
            // leave with zero real punches at all (full- or half-day) falls back to
            // the same merged, whole-day rendering, since there's no real data to
            // preserve either way.
            if ($leaveCode !== null) {
                $isFullDayLeave = ($leaveMap[$day]['days'] ?? 1.0) >= 1.0;
                $punchCount = $rec ? count(array_filter([
                    $rec['am_in'] ?? null, $rec['am_out'] ?? null,
                    $rec['pm_in'] ?? null, $rec['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== '')) : 0;

                if ($punchCount === 0) {
                    foreach (range(0, 3) as $i) {
                        $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                        try {
                            $sheet->mergeCells($range);
                        } catch (\Throwable) {
                        }
                        $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, $leaveCode);
                        $sheet->getStyle($range)->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        // No tardiness/undertime on a fully leave-covered day.
                        $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                        $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');
                    }

                    continue;
                }

                $amIn = $fmt($rec['am_in'] ?? null) ?: $leaveCode;
                $amOut = $fmt($rec['am_out'] ?? null) ?: $leaveCode;
                $pmIn = $fmt($rec['pm_in'] ?? null) ?: $leaveCode;
                $pmOut = $fmt($rec['pm_out'] ?? null) ?: $leaveCode;

                if ($isFullDayLeave) {
                    // Full-day leave still excuses the whole day even with an
                    // incidental punch - no work obligation exists for any slot,
                    // so no penalty applies.
                    $tardiness = $undertime = 0;
                } else {
                    [$tardiness, $undertime] = self::computeSlotPenalties(
                        $rec['date'],
                        $fmt($rec['am_in'] ?? null) ?: '',
                        $fmt($rec['pm_in'] ?? null) ?: '',
                        $fmt($rec['pm_out'] ?? null) ?: '',
                        $schedule
                    );
                }
                $mins = $tardiness + $undertime;
                $hVal = (int) floor($mins / 60);
                $mVal = $mins % 60;

                foreach (range(0, 3) as $i) {
                    $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $amIn);
                    $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $amOut);
                    $sheet->setCellValue(self::PM_IN_COLS[$i].$row, $pmIn);
                    $sheet->setCellValue(self::PM_OUT_COLS[$i].$row, $pmOut);
                    $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, $hVal ?: '');
                    $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, $mVal ?: '');
                    foreach ([
                        self::AM_IN_COLS[$i],  self::AM_OUT_COLS[$i],
                        self::PM_IN_COLS[$i],  self::PM_OUT_COLS[$i],
                    ] as $col) {
                        $sheet->getStyle($col.$row)->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    }
                }

                continue;
            }

            // Approved ETA: fill missing time slots with "ETA".
            // If all 4 slots are present, fall through to the normal write below.
            if (isset($etaMap[$day])) {
                $punchCount = $rec ? count(array_filter([
                    $rec['am_in'] ?? null, $rec['am_out'] ?? null,
                    $rec['pm_in'] ?? null, $rec['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== '')) : 0;

                if ($punchCount < 4) {
                    if ($punchCount === 0) {
                        // No punches at all - merge and label the row "ETA".
                        foreach (range(0, 3) as $i) {
                            $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                            try {
                                $sheet->mergeCells($range);
                            } catch (\Throwable) {
                            }
                            $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, 'ETA');
                            $sheet->getStyle($range)->getAlignment()
                                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                            $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                            $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');
                        }
                    } else {
                        // 1–3 punches - show actual times for filled slots, "ETA" for empty ones.
                        $amIn = $fmt($rec['am_in'] ?? null) ?: 'ETA';
                        $amOut = $fmt($rec['am_out'] ?? null) ?: 'ETA';
                        $pmIn = $fmt($rec['pm_in'] ?? null) ?: 'ETA';
                        $pmOut = $fmt($rec['pm_out'] ?? null) ?: 'ETA';

                        foreach (range(0, 3) as $i) {
                            $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $amIn);
                            $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $amOut);
                            $sheet->setCellValue(self::PM_IN_COLS[$i].$row, $pmIn);
                            $sheet->setCellValue(self::PM_OUT_COLS[$i].$row, $pmOut);
                            $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                            $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');
                            foreach ([
                                self::AM_IN_COLS[$i],  self::AM_OUT_COLS[$i],
                                self::PM_IN_COLS[$i],  self::PM_OUT_COLS[$i],
                            ] as $col) {
                                $sheet->getStyle($col.$row)->getAlignment()
                                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                            }
                        }
                    }

                    continue;
                }
                // punchCount === 4: fall through to normal write below.
            }

            // Office Order: priority below ETA, above Excuse.
            // Slots fill sequentially (am_in → am_out → pm_in → pm_out).
            // 0 punches → merge all 4 cells, write "Office Order".
            // 1 punch   → show am_in; merge remaining 3 cells, write "Office Order".
            // 2 punches → show am_in + am_out; merge remaining 2 cells, write "Office Order".
            // 3 punches → show am_in + am_out + pm_in; pm_out cell = "Office Order".
            // 4 punches → fall through to normal write below.
            if (isset($officeOrderMap[$day])) {
                $punchCount = $rec ? count(array_filter([
                    $rec['am_in'] ?? null, $rec['am_out'] ?? null,
                    $rec['pm_in'] ?? null, $rec['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== '')) : 0;

                if ($punchCount < 4) {
                    foreach (range(0, 3) as $i) {
                        $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                        $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');

                        match ($punchCount) {
                            0 => (function () use ($sheet, $i, $row): void {
                                $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                                try {
                                    $sheet->mergeCells($range);
                                } catch (\Throwable) {
                                }
                                $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, 'Office Order');
                                $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                            })(),

                            1 => (function () use ($sheet, $i, $row, $rec, $fmt): void {
                                $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $fmt($rec['am_in'] ?? null));
                                $sheet->getStyle(self::AM_IN_COLS[$i].$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                                $range = self::AM_OUT_COLS[$i].$row.':'.self::PM_OUT_COLS[$i].$row;
                                try {
                                    $sheet->mergeCells($range);
                                } catch (\Throwable) {
                                }
                                $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, 'Office Order');
                                $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                            })(),

                            2 => (function () use ($sheet, $i, $row, $rec, $fmt): void {
                                $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $fmt($rec['am_in'] ?? null));
                                $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $fmt($rec['am_out'] ?? null));
                                foreach ([self::AM_IN_COLS[$i], self::AM_OUT_COLS[$i]] as $col) {
                                    $sheet->getStyle($col.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                                }
                                $range = self::PM_IN_COLS[$i].$row.':'.self::PM_OUT_COLS[$i].$row;
                                try {
                                    $sheet->mergeCells($range);
                                } catch (\Throwable) {
                                }
                                $sheet->setCellValue(self::PM_IN_COLS[$i].$row, 'Office Order');
                                $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                            })(),

                            default => (function () use ($sheet, $i, $row, $rec, $fmt): void {
                                // 3 punches: show am_in, am_out, pm_in; pm_out = "Office Order"
                                $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $fmt($rec['am_in'] ?? null));
                                $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $fmt($rec['am_out'] ?? null));
                                $sheet->setCellValue(self::PM_IN_COLS[$i].$row, $fmt($rec['pm_in'] ?? null));
                                $sheet->setCellValue(self::PM_OUT_COLS[$i].$row, 'Office Order');
                                foreach ([
                                    self::AM_IN_COLS[$i], self::AM_OUT_COLS[$i],
                                    self::PM_IN_COLS[$i], self::PM_OUT_COLS[$i],
                                ] as $col) {
                                    $sheet->getStyle($col.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                                }
                            })(),
                        };
                    }

                    continue;
                }
                // punchCount === 4: fall through to normal write below.
            }

            // Travel Order: same priority tier as Office Order, sitting right after
            // it - mirrors DtrController::data()'s identical placement. Same
            // sequential partial-punch merge pattern as Field Work/WFH above.
            if (isset($travelOrderMap[$day])) {
                $punchCount = $rec ? count(array_filter([
                    $rec['am_in'] ?? null, $rec['am_out'] ?? null,
                    $rec['pm_in'] ?? null, $rec['pm_out'] ?? null,
                ], fn ($v) => $v !== null && $v !== '')) : 0;

                if ($punchCount < 4) {
                    $this->writeSequentialPartialPunchLabel($sheet, $row, $rec, $punchCount, 'Travel Order');

                    continue;
                }
                // punchCount === 4: fall through to normal write below.
            }

            // Approved excuse: suppress penalties for excused slots; show 'EXCUSED' for missing ones.
            // Priority below ETA, OO, and TO; above suspension and locator.
            if (isset($excuseMap[$day])) {
                $excuse = $excuseMap[$day];
                // Effective values fold in any recovered punch (a real biometric
                // punch a DtrExcuse's unconditional exclusion swallowed into
                // unmatched_logs/time_in_ot/time_out_ot before it could ever reach
                // $rec - see ExcludedSlotPunchRecovery) so a recovered-but-otherwise
                // punchless day still routes into the per-slot branch below instead
                // of the whole-day merge, which reads $rec alone and would
                // otherwise never see the recovery.
                $recovered = $recoveredMap[$day] ?? [];
                $effAmIn = $rec['am_in'] ?? $recovered['am_in'] ?? null;
                $effAmOut = $rec['am_out'] ?? $recovered['am_out'] ?? null;
                $effPmIn = $rec['pm_in'] ?? $recovered['pm_in'] ?? null;
                $effPmOut = $rec['pm_out'] ?? $recovered['pm_out'] ?? null;
                $punchCount = count(array_filter(
                    [$effAmIn, $effAmOut, $effPmIn, $effPmOut],
                    fn ($v) => $v !== null && $v !== ''
                ));

                if ($punchCount === 0) {
                    // No punches at all - merge and label the row "EXCUSED".
                    foreach (range(0, 3) as $i) {
                        $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                        try {
                            $sheet->mergeCells($range);
                        } catch (\Throwable) {
                        }
                        $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, 'EXCUSED');
                        $sheet->getStyle($range)->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                        $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');
                    }
                } else {
                    // Has some punches (real or recovered): show the actual time
                    // where one exists, 'EXCUSED' for a genuinely missing excused slot.
                    $amIn = ($excuse->excuse_am_in || $excuse->is_full_day) ? ($fmt($effAmIn) ?: 'EXCUSED') : $fmt($effAmIn);
                    $amOut = ($excuse->excuse_am_out || $excuse->is_full_day) ? ($fmt($effAmOut) ?: 'EXCUSED') : $fmt($effAmOut);
                    $pmIn = ($excuse->excuse_pm_in || $excuse->is_full_day) ? ($fmt($effPmIn) ?: 'EXCUSED') : $fmt($effPmIn);
                    $pmOut = ($excuse->excuse_pm_out || $excuse->is_full_day) ? ($fmt($effPmOut) ?: 'EXCUSED') : $fmt($effPmOut);

                    // Penalty inputs are deliberately blind to any recovered value -
                    // always the sentinel for an excused slot regardless of what's
                    // displayed, so a recovered clock time can never feed a real
                    // tardiness/undertime figure. No am_out shadow is needed since
                    // computeSlotPenalties() never takes an am_out term at all.
                    $amInPenalty = ($excuse->excuse_am_in || $excuse->is_full_day) ? 'EXCUSED' : $fmt($rec['am_in'] ?? null);
                    $pmInPenalty = ($excuse->excuse_pm_in || $excuse->is_full_day) ? 'EXCUSED' : $fmt($rec['pm_in'] ?? null);
                    $pmOutPenalty = ($excuse->excuse_pm_out || $excuse->is_full_day) ? 'EXCUSED' : $fmt($rec['pm_out'] ?? null);
                    [$tardiness, $undertime] = self::computeSlotPenalties($rec['date'], $amInPenalty, $pmInPenalty, $pmOutPenalty, $schedule);
                    $mins = $tardiness + $undertime;
                    $hVal = (int) floor($mins / 60);
                    $mVal = $mins % 60;

                    foreach (range(0, 3) as $i) {
                        $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $amIn);
                        $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $amOut);
                        $sheet->setCellValue(self::PM_IN_COLS[$i].$row, $pmIn);
                        $sheet->setCellValue(self::PM_OUT_COLS[$i].$row, $pmOut);
                        $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, $hVal ?: '');
                        $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, $mVal ?: '');
                        foreach ([
                            self::AM_IN_COLS[$i],  self::AM_OUT_COLS[$i],
                            self::PM_IN_COLS[$i],  self::PM_OUT_COLS[$i],
                        ] as $col) {
                            $sheet->getStyle($col.$row)->getAlignment()
                                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        }
                    }
                }

                continue;
            }

            // Work Suspension: priority below Excuse, above Locator - mirrors
            // DtrController::data()'s identical placement. Only suspensions that
            // actually exclude at least one slot reach $suspensionSlotsMap (see
            // buildSuspensionSlotsMap()) - a cutoff-only suspension with no
            // exclusion needs no label here, only the schedule adjustment already
            // applied upstream in buildRecords().
            if (isset($suspensionSlotsMap[$day])) {
                $slots = $suspensionSlotsMap[$day];
                // Same effective-values treatment as the Excuse branch above - see
                // its comment for why this is required, not just a nicety.
                $recovered = $recoveredMap[$day] ?? [];
                $effAmIn = $rec['am_in'] ?? $recovered['am_in'] ?? null;
                $effAmOut = $rec['am_out'] ?? $recovered['am_out'] ?? null;
                $effPmIn = $rec['pm_in'] ?? $recovered['pm_in'] ?? null;
                $effPmOut = $rec['pm_out'] ?? $recovered['pm_out'] ?? null;
                $punchCount = count(array_filter(
                    [$effAmIn, $effAmOut, $effPmIn, $effPmOut],
                    fn ($v) => $v !== null && $v !== ''
                ));

                if ($punchCount === 0) {
                    foreach (range(0, 3) as $i) {
                        $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                        try {
                            $sheet->mergeCells($range);
                        } catch (\Throwable) {
                        }
                        $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, 'SUSPENDED');
                        $sheet->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                        $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');
                    }
                } else {
                    $amIn = array_key_exists('am_in', $slots) ? ($fmt($effAmIn) ?: 'SUSPENDED') : $fmt($effAmIn);
                    $amOut = array_key_exists('am_out', $slots) ? ($fmt($effAmOut) ?: 'SUSPENDED') : $fmt($effAmOut);
                    $pmIn = array_key_exists('pm_in', $slots) ? ($fmt($effPmIn) ?: 'SUSPENDED') : $fmt($effPmIn);
                    $pmOut = array_key_exists('pm_out', $slots) ? ($fmt($effPmOut) ?: 'SUSPENDED') : $fmt($effPmOut);

                    // Penalty inputs stay blind to any recovered value, same
                    // reasoning as the Excuse branch above.
                    $amInPenalty = array_key_exists('am_in', $slots) ? 'SUSPENDED' : $fmt($rec['am_in'] ?? null);
                    $pmInPenalty = array_key_exists('pm_in', $slots) ? 'SUSPENDED' : $fmt($rec['pm_in'] ?? null);
                    $pmOutPenalty = array_key_exists('pm_out', $slots) ? 'SUSPENDED' : $fmt($rec['pm_out'] ?? null);
                    [$tardiness, $undertime] = self::computeSlotPenalties($rec['date'], $amInPenalty, $pmInPenalty, $pmOutPenalty, $schedule);
                    $mins = $tardiness + $undertime;
                    $hVal = (int) floor($mins / 60);
                    $mVal = $mins % 60;

                    foreach (range(0, 3) as $i) {
                        $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $amIn);
                        $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $amOut);
                        $sheet->setCellValue(self::PM_IN_COLS[$i].$row, $pmIn);
                        $sheet->setCellValue(self::PM_OUT_COLS[$i].$row, $pmOut);
                        $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, $hVal ?: '');
                        $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, $mVal ?: '');
                        foreach ([
                            self::AM_IN_COLS[$i], self::AM_OUT_COLS[$i],
                            self::PM_IN_COLS[$i], self::PM_OUT_COLS[$i],
                        ] as $col) {
                            $sheet->getStyle($col.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        }
                    }
                }

                continue;
            }

            // Locator for this day (may be null).
            $locator = $locatorMap[$day] ?? null;

            // A weekend day with NO punches is merged and labelled, as before.
            // A weekend day that HAS punches falls through and is written exactly
            // like a weekday, so no actual employee logs are ever dropped.
            if ($isWeekend && ! $rec) {
                $label = $dayDate->isSaturday() ? 'Saturday' : 'Sunday';
                foreach (range(0, 3) as $i) {
                    $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                    try {
                        $sheet->mergeCells($range);
                    } catch (\Throwable) {
                        // Cell was already merged - skip.
                    }
                    $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, $label);
                    $sheet->getStyle($range)->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                continue;
            }

            // No biometric/manual record for this day.
            // If there is a locator, write "LOCATOR" in covered slots; otherwise skip.
            if (! $rec) {
                if ($locator !== null) {
                    foreach (range(0, 3) as $i) {
                        $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $locator['covers_am_in'] ? 'LOCATOR' : '');
                        $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $locator['covers_am_out'] ? 'LOCATOR' : '');
                        $sheet->setCellValue(self::PM_IN_COLS[$i].$row, $locator['covers_pm_in'] ? 'LOCATOR' : '');
                        $sheet->setCellValue(self::PM_OUT_COLS[$i].$row, $locator['covers_pm_out'] ? 'LOCATOR' : '');
                        $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                        $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');
                        foreach ([
                            self::AM_IN_COLS[$i],  self::AM_OUT_COLS[$i],
                            self::PM_IN_COLS[$i],  self::PM_OUT_COLS[$i],
                        ] as $col) {
                            $sheet->getStyle($col.$row)->getAlignment()
                                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        }
                    }
                }

                continue;
            }

            // Redistribute punches around the locator window so that a punch
            // occurring after official-travel arrival is not mis-assigned to a
            // covered slot by the sequential DtrPunchResolver logic.
            if ($locator !== null) {
                [$amIn, $amOut, $pmIn, $pmOut] = self::resolveLocatorSlots(
                    $rec['am_in'] ?? null, $rec['am_out'] ?? null,
                    $rec['pm_in'] ?? null, $rec['pm_out'] ?? null,
                    $locator
                );
                $amIn = $amIn ?? '';
                $amOut = $amOut ?? '';
                $pmIn = $pmIn ?? '';
                $pmOut = $pmOut ?? ($pmOutCoveredNextDay ? $pmOutFallbackLabel : '');
                // Recompute per-slot penalties: the pre-stored DtrPunchResolver values
                // are unreliable on locator days (sequential assignment mis-positions
                // punches), and the old OR logic zeroed ALL tardiness even when only
                // one slot was covered.
                [$tardiness, $undertime] = self::computeSlotPenalties($rec['date'], $amIn, $pmIn, $pmOut, $schedule);
                if ($pmOutCoveredNextDay) {
                    $undertime = 0;
                }
            } else {
                $amIn = $fmt($rec['am_in']);
                $amOut = $fmt($rec['am_out']);
                $pmIn = $fmt($rec['pm_in']);
                $pmOut = $fmt($rec['pm_out']);
                if ($pmOut === '' && $pmOutCoveredNextDay) {
                    $pmOut = $pmOutFallbackLabel;
                }
                $tardiness = $rec['tardiness'] ?? 0;
                $undertime = $pmOutCoveredNextDay ? 0 : ($rec['undertime'] ?? 0);
            }

            // The undertime columns always carry a number - 0 when clean, never blank.
            $mins = $tardiness + $undertime;
            $hVal = (int) floor($mins / 60);
            $mVal = $mins % 60;

            // Per-cell red font: only the slot that caused the penalty turns red,
            // matching the DataTable behaviour (avoids coloring an early AM In red
            // just because PM In return was late).
            $cellHm = fn (string $v): ?string => $v !== '' && $v !== 'LOCATOR' && $v !== 'EXCUSED' && strlen($v) >= 5
                ? substr($v, 0, 5)
                : null;
            $amInHm = $cellHm($amIn);
            $pmInHm = $cellHm($pmIn);
            $pmOutHm = $cellHm($pmOut);
            $redAmIn = $amInHm !== null && $amInHm > $schedule->workStart && $amInHm < $schedule->morningEnd;
            $redPmIn = $pmInHm !== null && $pmInHm > $schedule->lunchReturn && $pmInHm < $schedule->noonEnd;
            $redPmOut = $pmOutHm !== null && $pmOutHm >= $schedule->lunchReturn && $pmOutHm < $schedule->workEnd;

            foreach (range(0, 3) as $i) {
                $sheet->setCellValue(self::AM_IN_COLS[$i].$row, $amIn);
                $sheet->setCellValue(self::AM_OUT_COLS[$i].$row, $amOut);
                $sheet->setCellValue(self::PM_IN_COLS[$i].$row, $pmIn);
                $sheet->setCellValue(self::PM_OUT_COLS[$i].$row, $pmOut);
                $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, $hVal);
                $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, $mVal);

                foreach ([
                    self::AM_IN_COLS[$i],  self::AM_OUT_COLS[$i],
                    self::PM_IN_COLS[$i],  self::PM_OUT_COLS[$i],
                    self::UT_HRS_COLS[$i], self::UT_MIN_COLS[$i],
                ] as $col) {
                    $sheet->getStyle($col.$row)->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                }

                if ($redAmIn) {
                    $sheet->getStyle(self::AM_IN_COLS[$i].$row)->getFont()->getColor()->setARGB('FFDC2626');
                }
                if ($redPmIn) {
                    $sheet->getStyle(self::PM_IN_COLS[$i].$row)->getFont()->getColor()->setARGB('FFDC2626');
                }
                if ($redPmOut) {
                    $sheet->getStyle(self::PM_OUT_COLS[$i].$row)->getFont()->getColor()->setARGB('FFDC2626');
                }
                if ($mins > 0) {
                    $sheet->getStyle(self::UT_HRS_COLS[$i].$row)->getFont()->getColor()->setARGB('FFDC2626');
                    $sheet->getStyle(self::UT_MIN_COLS[$i].$row)->getFont()->getColor()->setARGB('FFDC2626');
                }
            }
        }
    }

    private function fillTotals(Worksheet $sheet, int $totalMins): void
    {
        $totH = $totalMins > 0 ? (int) floor($totalMins / 60) : '';
        $totM = $totalMins > 0 ? $totalMins % 60 : '';

        foreach (range(0, 3) as $i) {
            $sheet->setCellValue(self::TOT_HRS_CELLS[$i], $totH);
            $sheet->setCellValue(self::TOT_MIN_CELLS[$i], $totM);
        }
    }

    private function fillCertification(
        Worksheet $sheet,
        string $name,
        string $designation
    ): void {
        $sigText = $designation !== '' ? "{$name}\n{$designation}" : $name;

        foreach (self::SIGN_CELLS as $cell) {
            $sheet->setCellValue($cell, $sigText);
            $sheet->getStyle($cell)
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_BOTTOM)
                ->setWrapText(true);
        }
    }
}
