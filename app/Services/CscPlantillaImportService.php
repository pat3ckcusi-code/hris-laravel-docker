<?php

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\Plantilla;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Imports the official CSC "Plantilla of Personnel" workbook (one sheet per
 * office) into plantillas + employee_assignments, matching incumbents to
 * users by name. Salary amounts in the file are ignored -the salary matrix
 * is the source of monetary values; the file's SG and step are authoritative.
 *
 * Incumbent appointment dates (original appointment, last promotion) are
 * imported into the matched user's columns when they are valid Excel date
 * serials; malformed text values are counted and skipped. Dates of birth
 * are not imported.
 */
class CscPlantillaImportService
{
    /** Rows scanned from the top of each sheet to find headers. */
    private const HEADER_SCAN_ROWS = 40;

    /** Consecutive empty rows that terminate a sheet's data block. */
    private const EMPTY_ROW_WINDOW = 50;

    /** Hard cap on data rows per sheet (some sheets report bogus dimensions). */
    private const MAX_DATA_ROWS = 500;

    private const SUFFIX_TOKENS = ['JR', 'SR', 'II', 'III', 'IV'];

    private const EMPLOYMENT_TYPE_MAP = [
        'P' => 'permanent',
        'COTP' => 'co-terminus',
        'COT' => 'co-terminus',
        'E' => 'elected_official',
        'C' => 'casual',
        'CT' => 'contractual',
        'T' => 'temporary',
    ];

    /**
     * Column offsets from the base "ITEM NO." column. Most sheets start at
     * column B, but at least one page is shifted to column A, so every read
     * is relative to where the column-index anchor row ("3", "4", "5"...) sits.
     */
    private const COL_OLD_ITEM = 0;

    private const COL_NEW_ITEM = 1;

    private const COL_TITLE = 2;

    private const COL_SG = 5;

    private const COL_STEP = 8;

    private const COL_LAST_NAME = 12;

    private const COL_FIRST_NAME = 13;

    private const COL_MIDDLE_NAME = 14;

    private const COL_ORIG_APPOINTMENT = 16;

    private const COL_LAST_PROMOTION = 17;

    private const COL_STATUS = 18;

    /** Assignments created by the import start at the plantilla fiscal year. */
    private const ASSIGNMENT_START = '2026-01-01';

    /** Replaced/stale assignments are closed the day before the fiscal year. */
    private const ASSIGNMENT_END = '2025-12-31';

    /**
     * @return array{
     *     dry_run: bool,
     *     sheets_processed: int,
     *     items_parsed: int,
     *     vacant_items: int,
     *     plantillas_created: int,
     *     plantillas_updated: int,
     *     plantillas_unchanged: int,
     *     matched: int,
     *     assignments_created: int,
     *     assignments_replaced: int,
     *     assignments_unchanged: int,
     *     stale_assignments_ended: int,
     *     users_synced: int,
     *     users_dates_updated: int,
     *     unmatched_incumbents: array<int, array<string, string>>,
     *     ambiguous: array<int, array<string, string>>,
     *     duplicate_matches: array<int, array<string, string>>,
     *     users_designated_unassigned: array<int, array<string, string>>,
     *     warnings: array<int, string>
     * }
     */
    public function import(string $path, bool $dryRun = false): array
    {
        $report = [
            'dry_run' => $dryRun,
            'sheets_processed' => 0,
            'items_parsed' => 0,
            'vacant_items' => 0,
            'plantillas_created' => 0,
            'plantillas_updated' => 0,
            'plantillas_unchanged' => 0,
            'matched' => 0,
            'assignments_created' => 0,
            'assignments_replaced' => 0,
            'assignments_unchanged' => 0,
            'stale_assignments_ended' => 0,
            'users_synced' => 0,
            'users_dates_updated' => 0,
            'unmatched_incumbents' => [],
            'ambiguous' => [],
            'duplicate_matches' => [],
            'users_designated_unassigned' => [],
            'warnings' => [],
        ];

        $items = $this->parseWorkbook($path, $report);

        DB::beginTransaction();

        try {
            $this->persist($items, $report);

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $report;
    }

    // ──────────────────────────────────────────────
    // Parsing
    // ──────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>> parsed plantilla items
     */
    private function parseWorkbook(string $path, array &$report): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $items = [];
        $seenItemNumbers = [];
        $unparsedDates = 0;

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $sheetName = $sheet->getTitle();
            $anchor = $this->findAnchor($sheet);

            if ($anchor === null) {
                $report['warnings'][] = "Sheet '{$sheetName}': column-index anchor row not found - sheet skipped.";

                continue;
            }

            [$anchorRow, $baseCol] = $anchor;
            $department = $this->findDepartment($sheet, $anchorRow, $baseCol);

            if ($department === null) {
                $department = $sheetName;
                $report['warnings'][] = "Sheet '{$sheetName}': office name header not found - using sheet name.";
            }

            $report['sheets_processed']++;

            $emptyStreak = 0;

            for ($row = $anchorRow + 1; $row <= $anchorRow + self::MAX_DATA_ROWS; $row++) {
                $oldItem = $this->cellValue($sheet, $baseCol + self::COL_OLD_ITEM, $row);

                if (str_starts_with($oldItem, '(19') || stripos($oldItem, 'Total Number of Position Items') !== false) {
                    break;
                }

                $title = $this->cellValue($sheet, $baseCol + self::COL_TITLE, $row);
                $sg = $this->cellValue($sheet, $baseCol + self::COL_SG, $row);
                $lastName = $this->cellValue($sheet, $baseCol + self::COL_LAST_NAME, $row);

                if ($oldItem === '' && $title === '' && $sg === '' && $lastName === '') {
                    if (++$emptyStreak >= self::EMPTY_ROW_WINDOW) {
                        break;
                    }

                    continue;
                }

                $emptyStreak = 0;

                // Section labels ("RECORDS SECTION") and spacer rows have no SG.
                if (! is_numeric($sg)) {
                    continue;
                }

                $salaryGrade = (int) $sg;

                if ($salaryGrade < 1 || $salaryGrade > 33) {
                    $report['warnings'][] = "Sheet '{$sheetName}' row {$row}: salary grade '{$sg}' out of range - row skipped.";

                    continue;
                }

                // The NEW item number is the identity; OLD numbers belong to
                // the previous numbering and may collide with NEW ones, so a
                // missing NEW cell gets a prefixed fallback instead.
                $itemNumber = $this->cellValue($sheet, $baseCol + self::COL_NEW_ITEM, $row);

                if ($itemNumber === '' && $oldItem !== '') {
                    $itemNumber = "OLD-{$oldItem}";
                    $report['warnings'][] = "Sheet '{$sheetName}' row {$row}: no NEW item number - using '{$itemNumber}'.";
                } elseif ($itemNumber === '') {
                    $itemNumber = "{$sheetName}:row{$row}";
                    $report['warnings'][] = "Sheet '{$sheetName}' row {$row}: no item number - synthesized '{$itemNumber}'.";
                }

                if (isset($seenItemNumbers[$itemNumber])) {
                    $report['warnings'][] = "Sheet '{$sheetName}' row {$row}: duplicate item number '{$itemNumber}' (first seen at {$seenItemNumbers[$itemNumber]}) - row skipped.";

                    continue;
                }

                $seenItemNumbers[$itemNumber] = "{$sheetName}:row{$row}";

                $step = $this->cellValue($sheet, $baseCol + self::COL_STEP, $row);
                $step = is_numeric($step) ? max(1, min(8, (int) $step)) : 1;

                $statusCode = strtoupper($this->cellValue($sheet, $baseCol + self::COL_STATUS, $row));
                $employmentType = self::EMPLOYMENT_TYPE_MAP[$statusCode] ?? null;

                if ($employmentType === null) {
                    if ($statusCode !== '') {
                        $report['warnings'][] = "Sheet '{$sheetName}' row {$row}: unknown status code '{$statusCode}' - defaulted to permanent.";
                    }
                    $employmentType = 'permanent';
                }

                $firstName = $this->cellValue($sheet, $baseCol + self::COL_FIRST_NAME, $row);
                $vacant = strcasecmp($lastName, 'Vacant') === 0 || ($lastName === '' && $firstName === '');

                $items[] = [
                    'sheet' => $sheetName,
                    'row' => $row,
                    'item_number' => $itemNumber,
                    'title' => $title,
                    'department' => $department,
                    'salary_grade' => $salaryGrade,
                    'step' => $step,
                    'employment_type' => $employmentType,
                    'vacant' => $vacant,
                    'last_name' => $vacant ? '' : $lastName,
                    'first_name' => $vacant ? '' : $firstName,
                    'middle_name' => $vacant ? '' : $this->cellValue($sheet, $baseCol + self::COL_MIDDLE_NAME, $row),
                    'original_appointment' => $vacant ? null : $this->parseExcelDate(
                        $this->cellValue($sheet, $baseCol + self::COL_ORIG_APPOINTMENT, $row), $unparsedDates
                    ),
                    'last_promotion' => $vacant ? null : $this->parseExcelDate(
                        $this->cellValue($sheet, $baseCol + self::COL_LAST_PROMOTION, $row), $unparsedDates
                    ),
                ];
            }
        }

        $spreadsheet->disconnectWorksheets();

        $report['items_parsed'] = count($items);
        $report['vacant_items'] = count(array_filter($items, fn (array $i) => $i['vacant']));

        if ($unparsedDates > 0) {
            $report['warnings'][] = "{$unparsedDates} appointment/promotion date cell(s) were not valid Excel dates and were skipped.";
        }

        return $items;
    }

    /**
     * Appointment dates in the file are Excel serials; a handful are
     * malformed text ("10/101972") and are skipped rather than guessed.
     */
    private function parseExcelDate(string $value, int &$unparsedDates): ?string
    {
        if ($value === '') {
            return null;
        }

        // Plausible serial range: ~1927 (10000) to ~2064 (60000)
        if (! is_numeric($value) || (float) $value < 10000 || (float) $value > 60000) {
            $unparsedDates++;

            return null;
        }

        return Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
    }

    /**
     * The row of column indices ("3", "4", "5"...) immediately precedes the
     * data block on every page. Header height varies per sheet, the base
     * column is usually B but sometimes A, and index cells may carry stray
     * brackets ("[ 3 ]"), so the match is digits-only per candidate base.
     *
     * @return array{0: int, 1: int}|null [anchor row, base column index]
     */
    private function findAnchor(Worksheet $sheet): ?array
    {
        for ($row = 1; $row <= self::HEADER_SCAN_ROWS; $row++) {
            foreach ([2, 1, 3] as $baseCol) {
                if ($this->digitsOnly($this->cellValue($sheet, $baseCol, $row)) === '3'
                    && $this->digitsOnly($this->cellValue($sheet, $baseCol + self::COL_TITLE, $row)) === '4'
                    && $this->digitsOnly($this->cellValue($sheet, $baseCol + self::COL_SG, $row)) === '5') {
                    return [$row, $baseCol];
                }
            }
        }

        return null;
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    /**
     * The office name sits in the base column of the row whose "(2) Bureau/
     * Agency" label occupies the incumbent-name column; the "(1)" prefix is
     * present on most sheets but missing on some.
     */
    private function findDepartment(Worksheet $sheet, int $anchorRow, int $baseCol): ?string
    {
        for ($row = 1; $row < $anchorRow; $row++) {
            $value = $this->cellValue($sheet, $baseCol, $row);

            if (preg_match('/\(\s*1\s*\)\s*(.+)/', $value, $matches)) {
                return trim($matches[1]);
            }

            if ($value !== ''
                && stripos($this->cellValue($sheet, $baseCol + self::COL_LAST_NAME, $row), 'Bureau') !== false) {
                return $value;
            }
        }

        return null;
    }

    private function cellValue(Worksheet $sheet, int $columnIndex, int $row): string
    {
        $value = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex).$row)->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if ($value === null) {
            return '';
        }

        // Canonicalize numeric-looking values ('01', 223.0) so item numbers
        // compare identically whether stored as text or numbers.
        if (is_numeric($value) && (float) $value == (int) $value) {
            return (string) (int) $value;
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value));
    }

    // ──────────────────────────────────────────────
    // Persistence
    // ──────────────────────────────────────────────

    private function persist(array $items, array &$report): void
    {
        $plantillaIds = [];
        $expectedIncumbent = [];
        $matchedUsers = [];
        $userIndex = $this->buildUserIndex();

        foreach ($items as $item) {
            $plantilla = Plantilla::updateOrCreate(
                ['item_number' => $item['item_number']],
                [
                    'title' => $item['title'],
                    'department' => $item['department'],
                    'salary_grade' => $item['salary_grade'],
                    'step' => $item['step'],
                    'employment_type' => $item['employment_type'],
                ]
            );

            if ($plantilla->wasRecentlyCreated) {
                $report['plantillas_created']++;
            } elseif ($plantilla->wasChanged()) {
                $report['plantillas_updated']++;
            } else {
                $report['plantillas_unchanged']++;
            }

            $plantillaIds[] = $plantilla->id;

            if ($item['vacant']) {
                continue;
            }

            [$userId, $failure] = $this->matchUser($item, $userIndex);

            if ($userId === null) {
                $report[$failure['type']][] = $failure['row'];

                continue;
            }

            if (isset($matchedUsers[$userId])) {
                $report['duplicate_matches'][] = $this->itemRef($item) + [
                    'reason' => "user #{$userId} already matched to item {$matchedUsers[$userId]}",
                ];

                continue;
            }

            $matchedUsers[$userId] = $item['item_number'];
            $expectedIncumbent[$plantilla->id] = $userId;
            $report['matched']++;

            $this->upsertAssignment($userId, $plantilla->id, $report);

            $dates = array_filter([
                'date_of_original_appointment' => $item['original_appointment'],
                'date_of_last_promotion' => $item['last_promotion'],
            ]);

            if ($dates !== []) {
                DB::table('users')->where('id', $userId)->update($dates);
                $report['users_dates_updated']++;
            }
        }

        $this->endStaleAssignments($plantillaIds, $expectedIncumbent, $report);
        $this->syncUserSalaryColumns($report);
        $this->reportDesignatedUnassigned($report);
    }

    private function upsertAssignment(int $userId, int $plantillaId, array &$report): void
    {
        $active = EmployeeAssignment::where('employee_id', $userId)
            ->whereNull('end_date')
            ->get();

        if ($active->contains('plantilla_id', $plantillaId)) {
            $report['assignments_unchanged']++;

            return;
        }

        $replaced = false;

        foreach ($active as $assignment) {
            $assignment->update(['end_date' => self::ASSIGNMENT_END]);
            $replaced = true;
        }

        EmployeeAssignment::create([
            'employee_id' => $userId,
            'plantilla_id' => $plantillaId,
            'start_date' => self::ASSIGNMENT_START,
        ]);

        $report[$replaced ? 'assignments_replaced' : 'assignments_created']++;
    }

    /**
     * Close active assignments on imported items whose holder is not the
     * incumbent in the file (person left, or the item is now vacant).
     */
    private function endStaleAssignments(array $plantillaIds, array $expectedIncumbent, array &$report): void
    {
        EmployeeAssignment::whereIn('plantilla_id', $plantillaIds)
            ->whereNull('end_date')
            ->get()
            ->each(function (EmployeeAssignment $assignment) use ($expectedIncumbent, &$report) {
                if (($expectedIncumbent[$assignment->plantilla_id] ?? null) !== $assignment->employee_id) {
                    $assignment->update(['end_date' => self::ASSIGNMENT_END]);
                    $report['stale_assignments_ended']++;
                }
            });
    }

    private function syncUserSalaryColumns(array &$report): void
    {
        $report['users_synced'] = DB::update('
            UPDATE users u
            JOIN employee_assignments ea ON ea.employee_id = u.id AND ea.end_date IS NULL
            JOIN plantillas p ON p.id = ea.plantilla_id
            SET u.salary_grade = p.salary_grade, u.salary_step = p.step
            WHERE u.salary_grade IS NULL OR u.salary_grade != p.salary_grade
                OR u.salary_step IS NULL OR u.salary_step != p.step
        ');
    }

    private function reportDesignatedUnassigned(array &$report): void
    {
        $report['users_designated_unassigned'] = User::query()
            ->whereNotNull('designation')
            ->where('designation', '!=', '')
            ->whereIn('employee_type', ['Permanent', 'Elected Officials', 'Co-Terminus'])
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('employee_assignments')
                    ->whereColumn('employee_assignments.employee_id', 'users.id')
                    ->whereNull('employee_assignments.end_date');
            })
            ->orderBy('last_name')
            ->get(['id', 'last_name', 'first_name', 'designation'])
            ->map(fn (User $user) => [
                'id' => (string) $user->id,
                'name' => trim("{$user->last_name}, {$user->first_name}", ', '),
                'designation' => (string) $user->designation,
            ])
            ->all();
    }

    // ──────────────────────────────────────────────
    // Name matching
    // ──────────────────────────────────────────────

    /**
     * @return array{exact: array<string, array<int, int>>, first_token: array<string, array<int, int>>, initial: array<string, array<int, int>>}
     */
    private function buildUserIndex(): array
    {
        $index = ['exact' => [], 'first_token' => [], 'initial' => []];

        User::query()
            ->whereNotNull('last_name')
            ->whereNotNull('first_name')
            ->get(['id', 'last_name', 'first_name'])
            ->each(function (User $user) use (&$index) {
                $last = $this->normalizeName($user->last_name);
                $first = $this->normalizeName($user->first_name);

                if ($last === '' || $first === '') {
                    return;
                }

                $index['exact']["{$last}|{$first}"][] = $user->id;
                $index['first_token'][$last.'|'.strtok($first, ' ')][] = $user->id;
                $index['initial'][$last.'|'.mb_substr($first, 0, 1)][] = $user->id;
            });

        return $index;
    }

    /**
     * @return array{0: int|null, 1: array{type: string, row: array<string, string>}|null}
     */
    private function matchUser(array $item, array $index): array
    {
        $last = $this->normalizeName($item['last_name']);
        $first = $this->normalizeName($item['first_name']);

        $tiers = [
            $index['exact']["{$last}|{$first}"] ?? [],
            $index['first_token'][$last.'|'.strtok($first, ' ')] ?? [],
            $index['initial'][$last.'|'.mb_substr($first, 0, 1)] ?? [],
        ];

        foreach ($tiers as $candidates) {
            $candidates = array_values(array_unique($candidates));

            if (count($candidates) === 1) {
                return [$candidates[0], null];
            }

            if (count($candidates) > 1) {
                return [null, [
                    'type' => 'ambiguous',
                    'row' => $this->itemRef($item) + ['candidates' => implode(', ', $candidates)],
                ]];
            }
        }

        return [null, [
            'type' => 'unmatched_incumbents',
            'row' => $this->itemRef($item),
        ]];
    }

    /**
     * Uppercase, transliterate accents (Ñ/É...), drop punctuation and
     * suffixes (Jr/Sr/II-IV, trailing V), and collapse whitespace so file
     * names and user columns compare equal despite spelling drift
     * ("Alcañices" vs "ALCANICES", "Lalong-isip" vs "LALONG - ISIP").
     */
    private function normalizeName(?string $name): string
    {
        $normalized = mb_strtoupper(trim((string) $name));
        $normalized = strtr($normalized, [
            'Ñ' => 'N', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U',
        ]);
        $normalized = str_replace(['.', ',', '-'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        $tokens = array_values(array_filter(
            explode(' ', $normalized),
            fn (string $token) => $token !== '' && ! in_array($token, self::SUFFIX_TOKENS, true)
        ));

        // A trailing standalone V is a suffix; anywhere else it may be an initial.
        if (count($tokens) >= 2 && end($tokens) === 'V') {
            array_pop($tokens);
        }

        return implode(' ', $tokens);
    }

    private function itemRef(array $item): array
    {
        return [
            'sheet' => $item['sheet'],
            'row' => (string) $item['row'],
            'item_number' => $item['item_number'],
            'title' => $item['title'],
            'name' => trim("{$item['last_name']}, {$item['first_name']} {$item['middle_name']}", ', '),
        ];
    }
}
