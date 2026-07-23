<?php

namespace App\Services;

use App\Models\HRAuditTrail;
use App\Models\JobOrderAppointment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds the "Job Order Roster" export to match LGU Calapan's actual "JOB
 * ORDER" appointment document - the real paperwork submitted for JO
 * hiring/renewal approval, not just an internal browsing list: a letterhead,
 * a single item-numbered table covering every appointment with a combined
 * "OFFICE:" line listing every distinct office involved, three fixed legal
 * certification paragraphs, and a 4-signatory sign-off block. Built with raw
 * PhpSpreadsheet calls (mergeCells/setCellValue/applyFromArray) rather than
 * loading and mutating a binary template file - a variable number of data
 * rows is far simpler to write fresh each time than to shift merged ranges
 * inside a loaded template.
 */
class JobOrderRosterExportService
{
    private const CERTIFICATION_PARAGRAPHS = [
        '             The said job order shall automatically cease upon its expiration as stipulated above, unless renewed.  However, services of any or all of the named can be terminated prior to the expiration of its job order for lack of funds or when services are no longer needed.  The above-named hereby attest; A) he/she is not related within the fourth degree of consanguinity nor by affinity to the; 1) contracting officer 2) appointing officer in the hiring agency, B) he/she is not been previously dismissed from the government service because of an administrative offenses; that he/she has not hired to perform function pertaining to vacant plantilla position. D) that he/she was not holding spurious eligibility E) that his/her services rendered herein will not be considered as government service F) that they will not enjoy benefits enjoyed by the City Government personnel, G) that he has not reached the compulsory retirement age of sixty-five (65).',
        '             This further certifies that all future job order entered into shall allow the standard contract or job order and that no amendment or revision shall be made with respect to its stipulation.',
        '             Hiring of job order is done due to exigency of the service and it is feasible or practicable to hire on a casual basis due to lack of funds. Furthermore, this is to certify that all requirements and supporting papers pursuant to CSC MC No. 40 s. 1998, AS AMENDED, have been complied with, reviewed and found in order.',
    ];

    /**
     * @param  array{department_id?: int|string|array<int, int|string>|null, office?: string|null, period_from?: string|null, period_to?: string|null}  $filters
     * @return Collection<int, JobOrderAppointment>
     */
    public function getRows(array $filters): Collection
    {
        $query = JobOrderAppointment::query()
            ->with('employee.department')
            ->whereHas('employee', fn ($q) => $q->where('employee_type', 'Job Orders'));

        if (! empty($filters['department_id'])) {
            $departmentIds = (array) $filters['department_id'];
            $query->whereHas('employee', fn ($q) => $q->whereIn('Dept_id', $departmentIds));
        }

        if (! empty($filters['office'])) {
            $query->where('office', 'like', '%'.$filters['office'].'%');
        }

        $periodFrom = $filters['period_from'] ?? null;
        $periodTo = $filters['period_to'] ?? null;

        if ($periodFrom || $periodTo) {
            $rangeFrom = $periodFrom ?: '0001-01-01';
            $rangeTo = $periodTo ?: '9999-12-31';

            // Overlap, not containment: an appointment counts for a requested
            // range if any part of its window falls inside it.
            $query->where('period_from', '<=', $rangeTo)
                ->where('period_until', '>=', $rangeFrom);
        } else {
            $query->current();
        }

        return $query->orderBy('office')
            ->get()
            ->sortBy(fn (JobOrderAppointment $appointment) => $this->employeeName($appointment->employee))
            ->values();
    }

    /**
     * @param  array{department_id?: int|string|array<int, int|string>|null, office?: string|null, period_from?: string|null, period_to?: string|null}  $filters
     * @return array{0: Spreadsheet, 1: string}
     */
    public function buildSpreadsheet(array $filters, User $actor): array
    {
        $rows = $this->getRows($filters);
        // One combined document, not one repeating block per office: the
        // "OFFICE:" line lists every distinct office involved together
        // (e.g. "OFFICE: BAC, CHRMD, PSD"), and rows are sorted by office
        // then name so same-office appointments still sit next to each
        // other in the single table, even without a block header per office.
        $officeLabel = $rows->pluck('office')->filter()->unique()->sort()->values()->implode(', ');
        $sortedRows = $rows->sortBy(fn (JobOrderAppointment $a) => ($a->office ?: 'Unassigned').'|'.$this->employeeName($a->employee))->values();
        $settings = Setting::first();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Job Order Roster')
            ->setCreator('HRIS');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Job Order Roster');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_LEGAL);

        foreach (['A' => 5, 'B' => 26, 'C' => 22, 'D' => 12, 'E' => 10, 'F' => 16, 'G' => 16, 'H' => 22, 'I' => 12, 'J' => 14] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        if ($sortedRows->isEmpty()) {
            $sheet->setCellValue('A1', 'No Job Order appointments match the current filters.');
        } else {
            $this->writeDocumentBody($sheet, 1, $officeLabel, $sortedRows, $settings, $actor);
        }

        $filename = 'Job-Order-Roster-'.now()->format('Ymd-His').'.xlsx';

        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'records',
                'action' => 'job_order_roster_exported',
                'target_type' => null,
                'target_id' => null,
                'details' => [
                    'filters' => $filters,
                    'employee_count' => $rows->count(),
                    'filename' => $filename,
                ],
            ]);
        } catch (\Exception) {
            // audit failure must not block the download
        }

        return [$spreadsheet, $filename];
    }

    /**
     * Writes the single letterhead/table/certification/signature body
     * covering every appointment in $appointments, with $officeLabel (every
     * distinct office involved, comma-joined) on the "OFFICE:" line.
     *
     * @param  Collection<int, JobOrderAppointment>  $appointments
     */
    private function writeDocumentBody(
        Worksheet $sheet,
        int $startRow,
        string $officeLabel,
        Collection $appointments,
        ?Setting $settings,
        User $actor
    ): int {
        $row = $startRow;
        $centered = ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER];

        foreach (['Republic of the Philippines', 'Province of Oriental Mindoro', 'CITY OF CALAPAN'] as $line) {
            $sheet->mergeCells("A{$row}:J{$row}");
            $sheet->setCellValue("A{$row}", $line);
            $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['size' => 10], 'alignment' => $centered]);
            $row++;
        }
        $row++; // blank

        $sheet->mergeCells("A{$row}:J{$row}");
        $sheet->setCellValue("A{$row}", 'JOB ORDER');
        $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 14], 'alignment' => $centered]);
        $row++;
        $row++; // blank

        $sheet->mergeCells("A{$row}:J{$row}");
        $sheet->setCellValue("A{$row}", "OFFICE: {$officeLabel}");
        $sheet->getStyle("A{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 10], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER]]);
        $row++;
        $row++; // blank

        $headerRow1 = $row;
        $headerRow2 = $row + 1;
        $sheet->mergeCells("A{$headerRow1}:A{$headerRow2}");
        $sheet->mergeCells("B{$headerRow1}:B{$headerRow2}");
        $sheet->mergeCells("C{$headerRow1}:C{$headerRow2}");
        $sheet->mergeCells("D{$headerRow1}:E{$headerRow2}");
        $sheet->mergeCells("F{$headerRow1}:G{$headerRow1}");
        $sheet->mergeCells("H{$headerRow1}:H{$headerRow2}");
        $sheet->mergeCells("I{$headerRow1}:I{$headerRow2}");
        $sheet->mergeCells("J{$headerRow1}:J{$headerRow2}");

        $sheet->setCellValue("A{$headerRow1}", 'ITEM NO.');
        $sheet->setCellValue("B{$headerRow1}", 'NAME of APPOINTEE/S');
        $sheet->setCellValue("C{$headerRow1}", 'DESIGNATION');
        $sheet->setCellValue("D{$headerRow1}", 'RATE PER DAY');
        $sheet->setCellValue("F{$headerRow1}", 'PERIOD OF APPOINTMENT');
        $sheet->setCellValue("H{$headerRow1}", 'FUNDING CHARGING');
        $sheet->setCellValue("I{$headerRow1}", 'OFFICE');
        $sheet->setCellValue("J{$headerRow1}", 'REMARKS');
        $sheet->setCellValue("F{$headerRow2}", 'FROM');
        $sheet->setCellValue("G{$headerRow2}", 'TO');

        $headerStyle = [
            'font' => ['bold' => true, 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'BDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];
        $sheet->getStyle("A{$headerRow1}:J{$headerRow2}")->applyFromArray($headerStyle);
        $row = $headerRow2 + 1;

        $dataStyle = [
            'font' => ['size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];
        $nameStyle = array_merge($dataStyle, ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]]);
        $rateStyle = array_merge($dataStyle, ['numberFormat' => ['formatCode' => '_-* #,##0.00_-;\-* #,##0.00_-;_-* "-"??_-;_-@_-']]);

        $itemNo = 1;
        foreach ($appointments as $appointment) {
            $sheet->setCellValue("A{$row}", $itemNo);
            $sheet->setCellValue("B{$row}", $this->employeeName($appointment->employee));
            $sheet->setCellValue("C{$row}", $appointment->designation ?: '-');
            $sheet->setCellValue("D{$row}", (float) $appointment->rate_per_day);
            $sheet->setCellValue("E{$row}", $appointment->rate_note ?: '');
            $sheet->setCellValue("F{$row}", $appointment->period_from->format('F j, Y'));
            $sheet->setCellValue("G{$row}", $appointment->period_until->format('F j, Y'));
            $sheet->setCellValue("H{$row}", $appointment->funding_source ?: '-');
            $sheet->setCellValue("I{$row}", $appointment->office ?: '-');
            $sheet->setCellValue("J{$row}", $appointment->remarks ?: '-');

            $sheet->getStyle("A{$row}:J{$row}")->applyFromArray($dataStyle);
            $sheet->getStyle("B{$row}")->applyFromArray($nameStyle);
            $sheet->getStyle("D{$row}")->applyFromArray($rateStyle);

            $itemNo++;
            $row++;
        }
        $row++; // blank

        foreach (self::CERTIFICATION_PARAGRAPHS as $paragraph) {
            $sheet->mergeCells("A{$row}:J{$row}");
            $sheet->setCellValue("A{$row}", $paragraph);
            $sheet->getStyle("A{$row}")->applyFromArray([
                'font' => ['size' => 9],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_JUSTIFY, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $sheet->getRowDimension($row)->setRowHeight($this->certificationParagraphRowHeight($paragraph));
            $row++;
        }
        $row++; // blank

        // Each signatory's own column span (matches the sample: PREPARED BY
        // spans A:B, "Certified..." spans C:E, "CERTIFIED as to..." spans
        // G:H, APPROVED BY spans I:J) - needed so a signatory's label/name/
        // designation still center nicely across its whole slot now that
        // column A alone is too narrow (5) to hold them on its own.
        $signatorySpans = ['A' => 'B', 'C' => 'E', 'G' => 'H', 'I' => 'J'];

        $signatoryHeaders = [
            'A' => 'PREPARED BY:',
            'C' => 'Certified that all necessary documents have been reviewed:',
            'G' => 'CERTIFIED as to existence of Appropriation / Obligation:',
            'I' => 'APPROVED BY:',
        ];
        foreach ($signatoryHeaders as $col => $label) {
            $sheet->mergeCells("{$col}{$row}:{$signatorySpans[$col]}{$row}");
            $sheet->setCellValue("{$col}{$row}", $label);
            $sheet->getStyle("{$col}{$row}")->applyFromArray(['font' => ['size' => 10], 'alignment' => ['wrapText' => true]]);
        }
        $row += 3; // blank space for a physical signature

        $preparedByDesignation = trim((string) $actor->designation) !== '' ? $actor->designation : ($actor->access_level ?? '');
        $reviewedByName = $settings->hr_manager_name ?? '';
        $reviewedByDesignation = $settings->hr_manager_designation ?? 'OIC-CHRMD';
        $budgetName = $settings->budget_officer_name ?? '';
        $budgetDesignation = $settings->budget_officer_designation ?? 'OIC City Budget Dept.';
        $approvedByName = $settings->mayor_name ?? '';
        $approvedByDesignation = $settings->mayor_designation ?? 'City Mayor';

        $signatoryNames = ['A' => $actor->name, 'C' => $reviewedByName, 'G' => $budgetName, 'I' => $approvedByName];
        $signatoryDesignations = ['A' => $preparedByDesignation, 'C' => $reviewedByDesignation, 'G' => $budgetDesignation, 'I' => $approvedByDesignation];

        foreach ($signatoryNames as $col => $name) {
            $sheet->mergeCells("{$col}{$row}:{$signatorySpans[$col]}{$row}");
            $sheet->setCellValue("{$col}{$row}", $name);
            $sheet->getStyle("{$col}{$row}")->applyFromArray(['font' => ['bold' => true, 'size' => 10], 'alignment' => $centered]);
        }
        $row++;
        foreach ($signatoryDesignations as $col => $designation) {
            $sheet->mergeCells("{$col}{$row}:{$signatorySpans[$col]}{$row}");
            $sheet->setCellValue("{$col}{$row}", $designation);
            $sheet->getStyle("{$col}{$row}")->applyFromArray(['font' => ['italic' => true, 'size' => 10], 'alignment' => $centered]);
        }
        $row += 2; // blank separator before the next office block

        return $row;
    }

    /**
     * @param  array{department_id?: int|string|array<int, int|string>|null, office?: string|null, period_from?: string|null, period_to?: string|null}  $filters
     */
    public function generateExcelResponse(array $filters, User $actor): StreamedResponse
    {
        [$spreadsheet, $filename] = $this->buildSpreadsheet($filters, $actor);

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
     * The 3 certification paragraphs vary wildly in length (957/202/330
     * chars), so a single fixed row height either clips the longest one or
     * leaves the short one mostly blank. Estimates a wrapped line count for
     * the merged A:J span at 9pt and sizes the row to that, instead of
     * reusing one height for all three.
     */
    private function certificationParagraphRowHeight(string $paragraph): float
    {
        $charsPerLine = 145;
        $pointsPerLine = 8.5;

        $lines = max(1, (int) ceil(mb_strlen($paragraph) / $charsPerLine));

        return $lines * $pointsPerLine;
    }

    private function employeeName(?User $employee): string
    {
        if (! $employee) {
            return '';
        }

        $lastName = trim((string) $employee->last_name);
        $givenName = trim(trim((string) $employee->first_name).' '.trim((string) $employee->middle_name));

        $name = trim($lastName.', '.$givenName, ', ');

        return $name !== '' ? $name : (string) $employee->name;
    }
}
