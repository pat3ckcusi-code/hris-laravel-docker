<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\EmployeeShiftSchedule;
use App\Models\Eta;
use App\Models\LeaveDate;
use App\Models\Locator;
use App\Models\Setting;
use App\Models\User;
use App\Support\WorkSchedule;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class Form48ExportService
{
    public function __construct(
        private readonly DtrPunchResolver $punchResolver,
        private readonly ShiftPunchGrouper $punchGrouper,
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

        // Primary: use the computed DTR rows (accurate AM/PM split + penalties).
        foreach (Dtr::where('employee_id', $userId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get() as $dtr) {
            $day = (int) $dtr->date->day;
            $records[$day] = [
                'date' => $dtr->date->format('Y-m-d'),
                'am_in' => $dtr->time_in_am,
                'am_out' => $dtr->time_out_am,
                'pm_in' => $dtr->time_in_pm,
                'pm_out' => $dtr->time_out_pm,
                'tardiness' => $dtr->late_minutes ?? 0,
                'undertime' => $dtr->undertime_minutes ?? 0,
            ];
        }

        // Fallback: for shifts with attendance_logs but no DTR, derive the slots
        // via the shared grouper + resolver (same logic the import uses). Widen
        // the fetch by a day on each side so night shifts at the range edges are
        // complete.
        $user = User::find($userId);
        $schedule = WorkSchedule::forUser($user);
        $pad = $schedule->crossesMidnight ? 1 : 0;

        $logs = AttendanceLog::where('user_id', $userId)
            ->whereBetween('logdate', [
                Carbon::parse($from)->subDays($pad)->toDateString(),
                Carbon::parse($to)->addDays($pad)->toDateString(),
            ])
            ->orderBy('logdate')
            ->orderBy('logtime')
            ->get();

        foreach ($this->punchGrouper->group($user, $logs) as $date => $punches) {
            if ($date < $from || $date > $to) {
                continue;   // shift outside the requested range
            }
            $day = (int) Carbon::parse($date)->day;
            if (isset($records[$day])) {
                continue;   // DTR entry already covers this shift
            }

            $resolved = $this->punchResolver->resolve($punches, $date, $schedule);

            $records[$day] = [
                'date' => $date,
                'am_in' => $resolved['am_in'],
                'am_out' => $resolved['am_out'],
                'pm_in' => $resolved['pm_in'],
                'pm_out' => $resolved['pm_out'],
                'tardiness' => $resolved['late_minutes'],
                'undertime' => $resolved['undertime_minutes'],
            ];
        }

        return $records;
    }

    /**
     * Build a day-of-month → leave-code map for the given user and period.
     * Only approved, non-cancelled leave dates are included.
     *
     * @return array<int, string> e.g. [3 => 'VL', 4 => 'VL', 10 => 'SL']
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
            ->select('leave_dates.leave_date', 'leave_dates.is_lwop', 'leave_requests.leave_type')
            ->get()
            ->each(function ($row) use (&$map): void {
                $day = (int) Carbon::parse($row->leave_date)->day;
                $map[$day] = $row->is_lwop ? 'LWOP' : self::toLeaveCode($row->leave_type);
            });

        return $map;
    }

    /** Map full leave-type text to CSC Form 48 abbreviation. */
    public static function toLeaveCode(?string $leaveType): string
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
     * Build a day-of-month → slot-coverage map for approved locators on each day.
     * Multiple locators on the same day are OR-merged; departure/arrival form the
     * union window (earliest departure, latest arrival) used for punch redistribution.
     *
     * Coverage flags (determined by [intended_departure_time, intended_arrival_time]):
     *   covers_am_in  — departure ≤ 08:00 (employee departs before/at work start)
     *   covers_am_out — departure ≤ 12:00 AND arrival ≥ 11:00 (spans the noon slot)
     *   covers_pm_in  — departure ≤ 13:00 AND arrival ≥ 13:00 (spans lunch return)
     *   covers_pm_out — arrival ≥ 17:00 (returns at or after work end)
     *
     * @return array<int, array{covers_am_in: bool, covers_am_out: bool, covers_pm_in: bool, covers_pm_out: bool, departure: string, arrival: string}>
     */
    public function buildLocatorMap(int $userId, string $from, string $to): array
    {
        $map = [];
        $sc = WorkSchedule::forUser(User::find($userId));

        Locator::where('user_id', $userId)
            ->where('status', 'approved')
            ->whereBetween('travel_date', [$from, $to])
            ->get(['travel_date', 'intended_departure_time', 'intended_arrival_time'])
            ->each(function ($locator) use (&$map, $sc): void {
                $day = (int) Carbon::parse($locator->travel_date)->day;
                $dep = substr((string) $locator->intended_departure_time, 0, 5);
                $arr = substr((string) $locator->intended_arrival_time, 0, 5);

                $cur = $map[$day] ?? [
                    'covers_am_in' => false,
                    'covers_am_out' => false,
                    'covers_pm_in' => false,
                    'covers_pm_out' => false,
                    'departure' => $dep,
                    'arrival' => $arr,
                ];

                if ($dep <= $sc->workStart) {
                    $cur['covers_am_in'] = true;
                }
                if ($dep <= '12:00' && $arr >= $sc->morningEnd) {
                    $cur['covers_am_out'] = true;
                }
                if ($dep <= $sc->lunchReturn && $arr >= $sc->lunchReturn) {
                    $cur['covers_pm_in'] = true;
                }
                if ($arr >= $sc->workEnd) {
                    $cur['covers_pm_out'] = true;
                }

                // Union the travel window so punch redistribution spans all locators for the day.
                $cur['departure'] = min($cur['departure'], $dep);
                $cur['arrival'] = max($cur['arrival'], $arr);

                $map[$day] = $cur;
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

    /**
     * Redistribute a day's biometric punches around a locator's travel window.
     *
     * DtrPunchResolver assigns punches sequentially (1st→AM-in, 2nd→AM-out, …)
     * without knowing that a locator covers certain slots.  This method corrects
     * that: punches that occurred BEFORE the locator's departure go to uncovered
     * slots that precede the covered block; punches that occurred AFTER the
     * locator's arrival go to uncovered slots that follow it.  Punches during the
     * travel window are discarded (the employee was officially away).  Covered
     * slots always display "LOCATOR".
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
        $dep = $locator['departure'];
        $arr = $locator['arrival'];

        $covered = [
            $locator['covers_am_in'],
            $locator['covers_am_out'],
            $locator['covers_pm_in'],
            $locator['covers_pm_out'],
        ];

        // Collect and normalise all non-empty slot values to HH:MM, then sort.
        $punches = [];
        foreach ([$rawAmIn, $rawAmOut, $rawPmIn, $rawPmOut] as $v) {
            if ($v !== null && $v !== '') {
                $punches[] = substr((string) $v, 0, 5);
            }
        }
        sort($punches);

        // Split by the locator window; punches during travel are discarded.
        $before = array_values(array_filter($punches, fn ($p) => $p < $dep));
        $after = array_values(array_filter($punches, fn ($p) => $p > $arr));

        // Find the first and last covered slot indices so we know which
        // uncovered slots are "before" vs "after" the official-travel block.
        $firstCovered = PHP_INT_MAX;
        $lastCovered = -1;
        foreach ($covered as $idx => $isCovered) {
            if ($isCovered) {
                if ($idx < $firstCovered) {
                    $firstCovered = $idx;
                }
                if ($idx > $lastCovered) {
                    $lastCovered = $idx;
                }
            }
        }

        $result = [null, null, null, null];
        $bi = 0;
        $ai = 0;

        foreach ($covered as $idx => $isCovered) {
            if ($isCovered) {
                $result[$idx] = 'LOCATOR';
            } elseif ($firstCovered === PHP_INT_MAX || $idx < $firstCovered) {
                // Before (or no) covered block — draw from before-locator punches.
                $result[$idx] = $bi < count($before) ? $before[$bi++] : null;
            } else {
                // After the covered block — draw from after-locator punches.
                $result[$idx] = $ai < count($after) ? $after[$ai++] : null;
            }
        }

        // Safety net: fill any remaining null uncovered slots with leftover punches
        // (edge case: no covered block, or more punches than expected slots).
        $extra = [...array_slice($before, $bi), ...array_slice($after, $ai)];
        $ei = 0;
        foreach ($covered as $idx => $isCovered) {
            if (! $isCovered && $result[$idx] === null && $ei < count($extra)) {
                $result[$idx] = $extra[$ei++];
            }
        }

        return $result;
    }

    /**
     * Recompute tardiness + undertime from RESOLVED slot values (after locator redistribution).
     * Mirrors DtrPunchResolver's time-aware penalty logic per slot so that only the
     * specific slot covered by the locator loses its penalty — not all tardiness at once.
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

        if ($amIn !== '' && $amIn !== 'LOCATOR') {
            $hm = substr($amIn, 0, 5);
            if ($hm > $schedule->workStart && $hm < $schedule->morningEnd) {
                $tardiness += (int) Carbon::parse("$date $schedule->workStart")->diffInMinutes(Carbon::parse("$date $hm"));
            }
        }

        if ($pmIn !== '' && $pmIn !== 'LOCATOR') {
            $hm = substr($pmIn, 0, 5);
            if ($hm > $schedule->lunchReturn && $hm < $schedule->noonEnd) {
                $tardiness += (int) Carbon::parse("$date $schedule->lunchReturn")->diffInMinutes(Carbon::parse("$date $hm"));
            }
        }

        if ($pmOut !== '' && $pmOut !== 'LOCATOR') {
            $hm = substr($pmOut, 0, 5);
            if ($hm >= $schedule->lunchReturn && $hm < $schedule->workEnd) {
                $undertime += (int) Carbon::parse("$date $hm")->diffInMinutes(Carbon::parse("$date $schedule->workEnd"));
            }
        }

        return [$tardiness, $undertime];
    }

    /**
     * Populate all cells on the Form 48 sheet.
     *
     * @param  array<int, array<string, mixed>>  $records  Keyed by day-of-month (1–31)
     * @param  array<int, string>  $leaveMap  From buildLeaveMap()
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
        array $fieldWorkMap = []
    ): void {
        $name = $this->formatName($employee);
        $designation = trim($employee->designation ?? '');
        $schedule = WorkSchedule::forUser($employee);

        $this->fillHeader($sheet, $name, $designation, $monthYear);
        $this->fillDailyRows($sheet, $records, $from, $leaveMap, $etaMap, $locatorMap, $schedule, $restDayMap, $fieldWorkMap);

        // Exclude rest days, leave days, and ETA days with fewer than 4 punches from the total.
        // For locator days, zero the penalty for each covered slot that lacks a punch.
        $totalMins = 0;
        foreach ($records as $day => $r) {
            if (isset($restDayMap[$day]) || isset($fieldWorkMap[$day])) {
                continue;
            }
            if (isset($leaveMap[$day])) {
                continue;
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
     * Format name as "LASTNAME, Firstname M." — same convention as the rest of HRIS.
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
        array $fieldWorkMap = []
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
            $leaveCode = $leaveMap[$day] ?? null;

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

            // Field work day: merge cells and write "Field Work" label.
            if (isset($fieldWorkMap[$day])) {
                foreach (range(0, 3) as $i) {
                    $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                    try {
                        $sheet->mergeCells($range);
                    } catch (\Throwable) {
                    }
                    $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, 'Field Work');
                    $sheet->getStyle($range)->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                    $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');
                }

                continue;
            }

            // Approved leave: merge AM-in → PM-out and write the leave code.
            // Leave takes priority over any biometric/manual punch for that day.
            if ($leaveCode !== null) {
                foreach (range(0, 3) as $i) {
                    $range = self::WKND_FROM_COLS[$i].$row.':'.self::WKND_TO_COLS[$i].$row;
                    try {
                        $sheet->mergeCells($range);
                    } catch (\Throwable) {
                    }
                    $sheet->setCellValue(self::WKND_FROM_COLS[$i].$row, $leaveCode);
                    $sheet->getStyle($range)->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    // No tardiness/undertime on leave days.
                    $sheet->setCellValue(self::UT_HRS_COLS[$i].$row, '');
                    $sheet->setCellValue(self::UT_MIN_COLS[$i].$row, '');
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
                        // No punches at all — merge and label the row "ETA".
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
                        // 1–3 punches — show actual times for filled slots, "ETA" for empty ones.
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
                        // Cell was already merged — skip.
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
                $pmOut = $pmOut ?? '';
                // Recompute per-slot penalties: the pre-stored DtrPunchResolver values
                // are unreliable on locator days (sequential assignment mis-positions
                // punches), and the old OR logic zeroed ALL tardiness even when only
                // one slot was covered.
                [$tardiness, $undertime] = self::computeSlotPenalties($rec['date'], $amIn, $pmIn, $pmOut, $schedule);
            } else {
                $amIn = $fmt($rec['am_in']);
                $amOut = $fmt($rec['am_out']);
                $pmIn = $fmt($rec['pm_in']);
                $pmOut = $fmt($rec['pm_out']);
                $tardiness = $rec['tardiness'] ?? 0;
                $undertime = $rec['undertime'] ?? 0;
            }

            // The undertime columns always carry a number — 0 when clean, never blank.
            $mins = $tardiness + $undertime;
            $hVal = (int) floor($mins / 60);
            $mVal = $mins % 60;

            // Per-cell red font: only the slot that caused the penalty turns red,
            // matching the DataTable behaviour (avoids coloring an early AM In red
            // just because PM In return was late).
            $cellHm = fn (string $v): ?string => $v !== '' && $v !== 'LOCATOR' && strlen($v) >= 5
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
