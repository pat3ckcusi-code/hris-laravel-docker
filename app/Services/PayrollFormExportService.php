<?php

namespace App\Services;

use App\Models\Deduction;
use App\Models\PayrollDetail;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollFormExportService
{
    private const ROWS_PER_PAGE = 10;

    private const HEADER_ROW = 8;

    // Sub-header row directly below HEADER_ROW - blank in most columns, but carries a
    // sub-label (e.g. "July 1-15") under a column whose HEADER_ROW label has been split
    // into two, like Net Pay.
    private const SUB_HEADER_ROW = 9;

    // Column K (index 11) is where the template's per-deduction columns start (after the
    // fixed Serial No./Name/Rate/Gross Pay/GSIS/PhilHealth/Pag-IBIG/BIR columns).
    private const FIRST_DYNAMIC_COLUMN_INDEX = 11;

    // Signatory names AND designations are configurable via Payroll Settings
    // (payroll.settings, a generic key/value store - PayrollSetting model) instead of
    // being permanently baked into the template file, since both the actual incumbents
    // and (occasionally) their titles change over time. Each entry's "anchor" is the
    // designation text currently in the template - used purely to *locate* the relevant
    // cells (the anchor cell itself, and the name cell directly above it), never itself
    // read as a live value, since the on-disk template is never mutated by this export
    // (only an in-memory clone is) so the anchor text is always there to find regardless
    // of what's actually configured to be written over it.
    private const SIGNATORY_SETTINGS = [
        ['anchor' => 'City Mayor', 'nameKey' => 'payroll_signatory_mayor_name', 'designationKey' => 'payroll_signatory_mayor_designation'],
        ['anchor' => 'City Accountant', 'nameKey' => 'payroll_signatory_accountant_name', 'designationKey' => 'payroll_signatory_accountant_designation'],
        ['anchor' => 'City Treasurer', 'nameKey' => 'payroll_signatory_treasurer_name', 'designationKey' => 'payroll_signatory_treasurer_designation'],
        ['anchor' => 'Cash Clerk II / Disbursing Officer II', 'nameKey' => 'payroll_signatory_cash_clerk_names', 'designationKey' => 'payroll_signatory_cash_clerk_designation'],
    ];

    /**
     * The template (storage/app/templates/PAYROLL FORM.xlsx) is manually maintained: every
     * column header, border, font, row height, and the whole certification/signature
     * section layout are already baked into the file by hand, not built by this service.
     * Every export method here therefore only *fills in* the existing structure - none of
     * them insert columns/rows or touch borders/alignment, since doing so would corrupt
     * the hand-tuned layout instead of reproducing it.
     *
     * $departmentId, when given, scopes the export to a single department (matched against
     * employee->Dept_id, same comparison PayrollRunController::show() already uses for its
     * own department filter) instead of every department in the run.
     */
    public function export(PayrollRun $run, ?string $departmentId = null): StreamedResponse|RedirectResponse
    {
        abort_unless(file_exists(storage_path('app/templates/PAYROLL FORM.xlsx')), 500, 'Payroll Form template not found.');

        $run->loadMissing('details.employee.department');

        $groups = $this->resolveGroups($run->details, $departmentId === null ? null : [$departmentId]);

        if ($groups->isEmpty()) {
            $message = $departmentId === null
                ? 'This payroll run has no computed details to export. Compute it first.'
                : 'No computed details found for the selected department in this payroll run.';

            return back()->with('error', $message);
        }

        // When scoped to one department, $groups has exactly that one entry - use its
        // display name to make a single-department export distinguishable from a
        // full-run export by filename.
        $filenameSuffix = $departmentId !== null ? '_'.$this->sanitize((string) $groups->first()['name']) : '';
        $filenameBase = 'Payroll_'.$run->id.'_'.$this->sanitize($run->period).$filenameSuffix;

        return $this->buildAndStream($groups, 'for the period '.$run->period, $filenameBase);
    }

    /**
     * A custom export of an arbitrary subset of departments (picked via the "Custom
     * Export" modal on the run page), each optionally printed under a name other than its
     * real Dept_name - e.g. fixing a data-entry quirk like "CITY ADMINISTRATOR' S OFFICE"
     * for the printed form without touching the actual department record.
     *
     * @param  array<int, string>  $departmentIds  required, non-empty
     * @param  array<string, string>  $nameOverrides  Dept_id => display name to print instead of the real Dept_name
     */
    public function exportSelected(PayrollRun $run, array $departmentIds, array $nameOverrides = []): StreamedResponse|RedirectResponse
    {
        abort_unless(file_exists(storage_path('app/templates/PAYROLL FORM.xlsx')), 500, 'Payroll Form template not found.');

        $run->loadMissing('details.employee.department');

        $groups = $this->resolveGroups($run->details, $departmentIds, $nameOverrides);

        if ($groups->isEmpty()) {
            return back()->with('error', 'No computed details found for the selected department(s) in this payroll run.');
        }

        $filenameSuffix = $groups->count() === 1
            ? '_'.$this->sanitize((string) $groups->first()['name'])
            : '_'.$groups->count().'_departments';
        $filenameBase = 'Payroll_'.$run->id.'_'.$this->sanitize($run->period).$filenameSuffix;

        return $this->buildAndStream($groups, 'for the period '.$run->period, $filenameBase);
    }

    /**
     * Loads the template once, applies signatory overrides/scans dynamic columns/detects
     * the first data row once, then clones one page per ROWS_PER_PAGE-sized chunk of each
     * group - shared by every export method above so they only ever differ in *which*
     * employees end up in $groups and what name each group is printed under.
     *
     * @param  Collection<int, array{name: string, details: Collection<int, PayrollDetail>}>  $groups
     */
    private function buildAndStream(Collection $groups, string $periodLabel, string $filenameBase): StreamedResponse
    {
        $templatePath = storage_path('app/templates/PAYROLL FORM.xlsx');

        // The template's sheet name isn't stable - it's manually saved from this same
        // export's own output, so it's literally named after whichever department page
        // was last in the file (e.g. "1_BIDS AND AWARD COMMITTEE OFFI"). Always use the
        // first sheet rather than a fixed name, and immediately rename it to something no
        // real page name could ever collide with - otherwise a run whose first department
        // happens to match the template's leftover name would fail to add that page's
        // clone (PhpSpreadsheet rejects duplicate sheet names).
        $spreadsheet = IOFactory::load($templatePath);
        $template = $spreadsheet->getSheet(0);
        $template->setTitle('__template__');

        $this->applySignatoryOverrides($template);
        $dynamicColumns = $this->scanDynamicColumns($template);

        // The employee data's starting row has already shifted twice across two separate
        // manual template edits (9 -> 10 when the Net Pay split's sub-header row was
        // inserted). Detect it from the template itself rather than hardcoding it again,
        // so a future row insertion doesn't silently break this the same way.
        $firstDataRow = $this->detectFirstDataRow($template);

        $sheetIndex = 0;

        foreach ($groups as $group) {
            foreach ($group['details']->chunk(self::ROWS_PER_PAGE) as $chunk) {
                $sheetIndex++;
                $clone = clone $template;
                $clone->setTitle($this->buildSheetName($sheetIndex, $group['name']));

                // addSheet before any cell mutation, so merged-cell operations resolve
                // against the workbook (same rule DtrController::downloadDepartmentForm48()
                // follows for its own clone-per-group pattern).
                $spreadsheet->addSheet($clone);
                $clone->setShowGridlines(false);
                $this->fillPage($clone, $group['name'], $periodLabel, $chunk->values(), $dynamicColumns, $firstDataRow);
            }
        }

        // Drop the original, unfilled template sheet.
        $spreadsheet->removeSheetByIndex(0);
        $spreadsheet->setActiveSheetIndex(0);

        $filename = $filenameBase.'.xlsx';

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    /**
     * $departmentIds === null means every department; a populated list restricts to just
     * those Dept_ids (covers both the single-department filter and a custom multi-select).
     * Groups by Dept_id (not Dept_name, unlike the old single-purpose grouping this
     * replaced) so a $nameOverrides lookup and a theoretical two-departments-same-name
     * collision both resolve correctly; the *display* name (override or real Dept_name)
     * is what the result is finally sorted by, to keep the same alphabetical-by-printed-
     * name page ordering the export has always had.
     *
     * @param  Collection<int, PayrollDetail>  $details
     * @param  array<int, string>|null  $departmentIds
     * @param  array<string, string>  $nameOverrides  Dept_id => display name
     * @return Collection<int, array{name: string, details: Collection<int, PayrollDetail>}>
     */
    private function resolveGroups(Collection $details, ?array $departmentIds, array $nameOverrides = []): Collection
    {
        return $details
            ->filter(fn (PayrollDetail $detail) => $detail->employee !== null)
            ->filter(fn (PayrollDetail $detail) => $departmentIds === null
                || in_array((string) ($detail->employee->Dept_id ?? ''), $departmentIds, true))
            ->groupBy(fn (PayrollDetail $detail) => (string) ($detail->employee->Dept_id ?? 'unassigned'))
            ->map(function (Collection $group) use ($nameOverrides) {
                $deptId = (string) ($group->first()->employee->Dept_id ?? 'unassigned');
                $realName = $group->first()->employee->department->Dept_name ?? 'Unassigned';
                $override = trim((string) ($nameOverrides[$deptId] ?? ''));

                return [
                    'name' => $override !== '' ? $override : $realName,
                    'details' => $group
                        ->sortBy(fn (PayrollDetail $d) => (string) ($d->employee->first_name ?? ''))
                        ->sortBy(fn (PayrollDetail $d) => (string) ($d->employee->last_name ?? ''))
                        ->values(),
                ];
            })
            ->sortBy('name')
            ->values();
    }

    // Applies whichever signatory names/designations are actually configured in Payroll
    // Settings, leaving the template's own baked-in text untouched for any that aren't (a
    // blank setting means "not configured yet", not "blank out the signature line").
    private function applySignatoryOverrides(Worksheet $template): void
    {
        $keys = array_merge(
            array_column(self::SIGNATORY_SETTINGS, 'nameKey'),
            array_column(self::SIGNATORY_SETTINGS, 'designationKey')
        );
        $settings = PayrollSetting::whereIn('key', $keys)->pluck('value', 'key');

        foreach (self::SIGNATORY_SETTINGS as $signatory) {
            $name = trim((string) ($settings[$signatory['nameKey']] ?? ''));
            $designation = trim((string) ($settings[$signatory['designationKey']] ?? ''));

            if ($name !== '' || $designation !== '') {
                $this->applySignatoryText($template, $signatory['anchor'], $name, $designation);
            }
        }
    }

    // Finds every cell whose value exactly matches a known designation anchor (e.g. "City
    // Mayor") and, for each match, overwrites the cell directly above it (same column,
    // previous row) with the configured name, and the anchor cell itself with the
    // configured designation - each independently optional. Locating by the stable
    // anchor text rather than a hardcoded coordinate, since a person's name/title is what
    // changes over time, not where "designation text currently sits", and the exact cell
    // coordinates here have already moved twice across manual template edits. Matches
    // every occurrence (the Mayor's designation currently appears twice, in two separate
    // certification blocks), so one pair of settings updates every occurrence at once.
    private function applySignatoryText(Worksheet $template, string $anchor, string $name, string $designation): void
    {
        $highestRow = $template->getHighestRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($template->getHighestColumn());

        for ($row = 2; $row <= $highestRow; $row++) {
            for ($colIndex = 1; $colIndex <= $highestColumnIndex; $colIndex++) {
                $col = Coordinate::stringFromColumnIndex($colIndex);
                if (trim((string) $template->getCell("{$col}{$row}")->getValue()) !== $anchor) {
                    continue;
                }
                if ($name !== '') {
                    $template->setCellValue("{$col}".($row - 1), $name);
                }
                if ($designation !== '') {
                    $template->setCellValue("{$col}{$row}", $designation);
                }
            }
        }
    }

    // The first "Serial No." row always contains 1 - detecting it instead of hardcoding a
    // row number survives the template's own row insertions (this has already happened
    // twice from two separate manual edits) without another silent row-offset bug.
    private function detectFirstDataRow(Worksheet $template): int
    {
        $row = self::HEADER_ROW + 1;
        while ($row < self::HEADER_ROW + 20) {
            if ((int) $template->getCell('A'.$row)->getValue() === 1) {
                return $row;
            }
            $row++;
        }

        abort(500, 'Could not locate the first employee row (Serial No. "1") in the Payroll Form template.');
    }

    /**
     * Reads whichever per-deduction columns already exist in the template's own header
     * row (K onward) instead of computing/inserting them - the template file, not the
     * live Deduction catalog, is the source of truth for which columns exist and where.
     * "LWOP"/"NET PAY" headers are special-cased; everything else is matched against the
     * Deduction catalog by label to recover its category ('loan'/'other') for the
     * deduction_breakdown lookup in fillPage(). A header with no catalog match still gets
     * a column (just one that will always fill as 0) rather than aborting the export -
     * the template is manually maintained now, so a stray/renamed header is the user's
     * concern to fix in the file, not a reason to fail every export.
     *
     * A HEADER_ROW label can be split across two columns via a SUB_HEADER_ROW label under
     * each half (e.g. "NET PAY" spanning two columns sub-labeled "July 1-15"/"July 16-31")
     * - a blank HEADER_ROW cell inherits the most recent non-blank label to its left, and
     * the first/second column found with both that inherited "NET PAY" label and its own
     * non-blank SUB_HEADER_ROW cell become 'net_pay_first_half'/'net_pay_second_half'
     * instead of the plain 'net_pay' used when the column isn't split.
     *
     * @return list<array{col: string, label: string, category: string|null, special: string|null}>
     */
    private function scanDynamicColumns(Worksheet $template): array
    {
        $columns = [];
        $lastColumnIndex = Coordinate::columnIndexFromString($template->getHighestColumn());
        $currentLabel = '';
        $netPaySplitsSeen = 0;

        for ($i = self::FIRST_DYNAMIC_COLUMN_INDEX; $i <= $lastColumnIndex; $i++) {
            $col = Coordinate::stringFromColumnIndex($i);
            $headerLabel = trim((string) $template->getCell("{$col}".self::HEADER_ROW)->getValue());
            $subLabel = trim((string) $template->getCell("{$col}".self::SUB_HEADER_ROW)->getValue());

            if ($headerLabel !== '') {
                $currentLabel = $headerLabel;
            }
            if ($currentLabel === '') {
                continue;
            }

            $upperLabel = mb_strtoupper($currentLabel);

            if ($upperLabel === 'NET PAY' && $subLabel !== '') {
                $netPaySplitsSeen++;
                $special = $netPaySplitsSeen === 1 ? 'net_pay_first_half' : 'net_pay_second_half';
                $columns[] = ['col' => $col, 'label' => $subLabel, 'category' => null, 'special' => $special];

                continue;
            }
            if ($upperLabel === 'LWOP') {
                $columns[] = ['col' => $col, 'label' => $currentLabel, 'category' => null, 'special' => 'lwop'];

                continue;
            }
            if ($upperLabel === 'NET PAY') {
                $columns[] = ['col' => $col, 'label' => $currentLabel, 'category' => null, 'special' => 'net_pay'];

                continue;
            }

            $category = Deduction::whereRaw('LOWER(type) = ?', [mb_strtolower($currentLabel)])->value('deduction_category');
            $columns[] = ['col' => $col, 'label' => $currentLabel, 'category' => $category, 'special' => null];
        }

        return $columns;
    }

    /**
     * @param  Collection<int, PayrollDetail>  $employeeChunk
     * @param  list<array{col: string, label: string, category: string|null, special: string|null}>  $dynamicColumns
     */
    private function fillPage(Worksheet $sheet, string $deptName, string $periodLabel, Collection $employeeChunk, array $dynamicColumns, int $firstDataRow): void
    {
        $sheet->setCellValue('A3', $periodLabel);
        $sheet->setCellValue('C5', $deptName);

        $row = $firstDataRow;
        foreach ($employeeChunk as $detail) {
            /** @var User $employee */
            $employee = $detail->employee;
            $breakdown = collect($detail->deduction_breakdown ?? []);
            [$netPayFirstHalf, $netPaySecondHalf] = $this->splitNetPay((float) $detail->net_pay);

            // Single concatenated "Lastname, First M." string into column B - the
            // template's existing B:D merge just widens its display, C/D get no
            // separate content of their own.
            $sheet->setCellValue("B{$row}", $this->formatName($employee));
            $sheet->setCellValue("E{$row}", (float) $detail->basic_salary);
            $sheet->setCellValue("F{$row}", (float) $detail->gross_pay);
            $sheet->setCellValue("G{$row}", (float) $detail->gsis_deduction);
            $sheet->setCellValue("H{$row}", (float) $detail->philhealth_deduction);
            $sheet->setCellValue("I{$row}", (float) $detail->pagibig_deduction);
            $sheet->setCellValue("J{$row}", (float) $detail->bir_deduction);

            foreach ($dynamicColumns as $meta) {
                $value = match ($meta['special']) {
                    'lwop' => (float) $detail->lwop_deduction,
                    'net_pay' => (float) $detail->net_pay,
                    'net_pay_first_half' => $netPayFirstHalf,
                    'net_pay_second_half' => $netPaySecondHalf,
                    default => (float) ($breakdown->first(
                        fn (array $item) => ($item['category'] ?? null) === $meta['category']
                            && ($item['label'] ?? null) === $meta['label']
                    )['amount'] ?? 0),
                };
                $sheet->setCellValue("{$meta['col']}{$row}", $value);
            }

            $row++;
        }
    }

    /**
     * A PayrollRun/PayrollDetail only ever stores one net_pay figure - there's no real
     * per-cutoff amount to source the template's "July 1-15"/"July 16-31" split from. Per
     * the user, split it into two halves that sum exactly back to the real net_pay: the
     * first half is a whole-peso amount (no centavos), and the second half absorbs
     * whatever centavos net_pay actually has - which also means it's always >= the first
     * half, strictly greater whenever net_pay isn't itself an exact even whole number.
     *
     * @return array{0: float, 1: float}
     */
    private function splitNetPay(float $netPay): array
    {
        $firstHalf = floor($netPay / 2);

        return [$firstHalf, $netPay - $firstHalf];
    }

    private function formatName(User $employee): string
    {
        $lastName = trim((string) $employee->last_name);
        $givenName = trim(trim((string) $employee->first_name).' '.trim((string) $employee->middle_name));
        $name = trim($lastName.', '.$givenName, ', ');

        return $name !== '' ? $name : (string) $employee->name;
    }

    private function buildSheetName(int $index, string $deptName): string
    {
        $sanitized = preg_replace('/[^\w ]/', '', $deptName) ?? '';
        $name = mb_substr(sprintf('%d_%s', $index, $sanitized), 0, 31);

        return $name !== '' ? $name : "Page_{$index}";
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? '';
    }
}
