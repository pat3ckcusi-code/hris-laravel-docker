<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Dtr;
use App\Models\User;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class Form48ExportService
{
    public function __construct(
        private readonly DtrPunchResolver $punchResolver,
    ) {}

    // Password that freezes the exported sheet — it opens and prints normally
    // but cannot be edited without this password.
    private const SHEET_PASSWORD = 'securepassword';

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

        // Fallback: for days with attendance_logs but no DTR, derive AM/PM
        // via the shared DtrPunchResolver (same logic the import uses).
        $logsByDay = AttendanceLog::where('user_id', $userId)
            ->whereBetween('logdate', [$from, $to])
            ->orderBy('logdate')
            ->orderBy('logtime')
            ->get()
            ->groupBy(fn ($log) => $log->logdate->format('Y-m-d'));

        foreach ($logsByDay as $date => $dayLogs) {
            $day = (int) Carbon::parse($date)->day;
            if (isset($records[$day])) {
                continue;   // DTR entry already covers this day
            }

            $resolved = $this->punchResolver->resolve(
                $dayLogs->pluck('logtime')->map(fn ($t) => (string) $t),
                $date
            );

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
     * Populate all cells on the Form 48 sheet.
     *
     * @param  array<int, array<string, mixed>>  $records  Keyed by day-of-month (1–31)
     */
    public function fill(
        Worksheet $sheet,
        array $records,
        User $employee,
        string $monthYear,
        string $from
    ): void {
        $name = $this->formatName($employee);
        $designation = trim($employee->designation ?? '');

        $this->fillHeader($sheet, $name, $designation, $monthYear);
        $this->fillDailyRows($sheet, $records, $from);

        $totalMins = array_sum(array_map(
            fn ($r) => ($r['tardiness'] ?? 0) + ($r['undertime'] ?? 0),
            $records
        ));
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
        $sheet->getProtection()
            ->setSheet(true)
            ->setPassword(self::SHEET_PASSWORD);
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
        string $from
    ): void {
        $date = Carbon::parse($from);
        $year = (int) $date->year;
        $month = (int) $date->month;
        $daysInMonth = (int) $date->daysInMonth;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $row = self::DATA_ROW_OFFSET + $day;
            $dayDate = Carbon::createFromDate($year, $month, $day);

            $rec = $records[$day] ?? null;
            $isWeekend = $dayDate->isSaturday() || $dayDate->isSunday();

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

            if (! $rec) {
                continue;
            }

            // MySQL TIME columns return zero-padded HH:MM:SS strings; substr gives HH:MM.
            $fmt = fn (?string $t): string => ($t !== null && $t !== '') ? substr($t, 0, 5) : '';

            $amIn = $fmt($rec['am_in']);
            $amOut = $fmt($rec['am_out']);
            $pmIn = $fmt($rec['pm_in']);
            $pmOut = $fmt($rec['pm_out']);

            // A record exists for this day, so the undertime columns always carry a
            // number — 0 when there is no tardiness/undertime, never blank.
            $mins = ($rec['tardiness'] ?? 0) + ($rec['undertime'] ?? 0);
            $hVal = (int) floor($mins / 60);
            $mVal = $mins % 60;

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
