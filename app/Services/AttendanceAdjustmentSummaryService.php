<?php

namespace App\Services;

use App\Models\AttendanceAdjustmentSubmission;
use App\Models\AttendanceAdjustmentSubmissionItem;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * Presentation/submission layer on top of AttendanceMonitoringExportService's
 * getRows() - the only place unfiled leave/tardiness/undertime are classified.
 * This service never recomputes those values; it only filters, sorts,
 * paginates, and persists snapshots of what getRows() already returns.
 */
class AttendanceAdjustmentSummaryService
{
    /**
     * Every DataTables interaction (page turn, sort click, the Minimum Count
     * field, search box) re-requests this endpoint, but getRows() itself -
     * computing every employee's classification for the selected
     * department(s)/month - is the expensive part (~5s across all
     * departments). None of those interactions change department/month/
     * year/employee type, so caching keyed on just those lets pagination/
     * sorting/searching/min-count stay fast without re-running the bulk
     * computation on every keystroke or click.
     */
    private const CACHE_TTL_SECONDS = 180;

    public function __construct(private readonly AttendanceMonitoringExportService $monitoringExportService) {}

    /**
     * @param  Collection<int, Department>  $departments
     * @return Collection<int, array>
     */
    public function getFilteredRows(
        Collection $departments,
        int $month,
        int $year,
        ?string $employeeType,
        ?string $issueFilter,
        ?string $search,
        ?int $minCount = null,
    ): Collection {
        $rows = $this->cachedGetRows($departments, $month, $year, $employeeType);

        // When a minimum count is given, it replaces the plain ">0 has this
        // issue" check below with ">$minCount" for whichever issue is
        // selected - or, with no issue selected, "any of the three counts
        // exceeds $minCount" - so the Timekeeper can narrow a long list down
        // to only the more severe cases.
        $threshold = $minCount ?? 0;

        $rows = match ($issueFilter) {
            'unfiled' => $rows->filter(fn (array $r) => $r['unfiled_count'] > $threshold),
            'tardiness' => $rows->filter(fn (array $r) => $r['tardiness_count'] > $threshold),
            'undertime' => $rows->filter(fn (array $r) => $r['undertime_count'] > $threshold),
            default => $minCount !== null
                ? $rows->filter(fn (array $r) => max($r['unfiled_count'], $r['tardiness_count'], $r['undertime_count']) > $threshold)
                : $rows,
        };

        $search = trim((string) $search);
        if ($search !== '') {
            $rows = $rows->filter(function (array $r) use ($search) {
                foreach (['emp_no', 'name', 'department', 'position'] as $field) {
                    if (stripos((string) ($r[$field] ?? ''), $search) !== false) {
                        return true;
                    }
                }

                return false;
            });
        }

        $submittedUserIds = $this->alreadySubmittedUserIds($month, $year);

        return $rows->map(function (array $r) use ($submittedUserIds, $issueFilter) {
            $r['status'] = $submittedUserIds->contains($r['user_id']) ? 'Submitted' : 'Not Submitted';
            $r['remarks'] = $this->filterRemarksForIssue((string) $r['remarks'], $issueFilter);

            return $r;
        })->values();
    }

    /**
     * getRows() builds one combined Remarks blob per employee out of every
     * attendance/leave/travel event that month (tardy days, undertime days,
     * unfiled-leave days, approved leave, locators, ETAs, office/travel
     * orders, excuses, uniform violations...). That's appropriate for the
     * Monitoring Matrix, but on this issue-scoped screen it reads as noise -
     * a Timekeeper reviewing Tardiness shouldn't see Undertime/Unfiled Leave
     * entries mixed into the same cell. Narrow it down to just the entries
     * that match the selected issue's label pattern.
     */
    private function filterRemarksForIssue(string $remarks, ?string $issueFilter): string
    {
        $keywords = match ($issueFilter) {
            'unfiled' => ['Unfiled Leave', 'No DTR data recorded'],
            'tardiness' => ['-Tardy ('],
            'undertime' => ['-Undertime ('],
            default => null,
        };

        if ($keywords === null || $remarks === '') {
            return $remarks;
        }

        $entries = array_filter(
            array_map('trim', explode(', ', $remarks)),
            fn (string $entry) => collect($keywords)->contains(fn (string $kw) => str_contains($entry, $kw))
        );

        return implode(', ', $entries);
    }

    /**
     * @param  Collection<int, Department>  $departments
     * @return Collection<int, array>
     */
    private function cachedGetRows(Collection $departments, int $month, int $year, ?string $employeeType): Collection
    {
        $deptKey = $departments->pluck('Dept_id')->sort()->implode(',');
        $cacheKey = "attendance_adjustment_summary.rows.{$deptKey}.{$month}.{$year}.".($employeeType ?? 'all');

        return Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->monitoringExportService->getRows($departments, $month, $year, $employeeType)
        );
    }

    /**
     * @param  Collection<int, array>  $rows  already-filtered rows
     * @return array{data: Collection<int, array>, recordsFiltered: int}
     */
    public function paginateForDataTable(Collection $rows, int $start, int $length, ?string $orderColumn, string $orderDir): array
    {
        $recordsFiltered = $rows->count();

        if ($orderColumn) {
            $rows = $rows->sortBy(
                fn (array $r) => $r[$orderColumn] ?? '',
                SORT_REGULAR,
                strtolower($orderDir) === 'desc'
            )->values();
        }

        $data = $length > 0
            ? $rows->slice($start, $length)->values()
            : $rows;

        return ['data' => $data, 'recordsFiltered' => $recordsFiltered];
    }

    /**
     * @param  Collection<int, array>  $rows  the full filtered (non-paginated) set
     * @return array{total_employees: int, unfiled_leave: int, tardiness: int, undertime: int}
     */
    public function buildSummaryCounts(Collection $rows): array
    {
        return [
            'total_employees' => $rows->count(),
            'unfiled_leave' => $rows->filter(fn (array $r) => $r['unfiled_count'] > 0)->count(),
            'tardiness' => $rows->filter(fn (array $r) => $r['tardiness_count'] > 0)->count(),
            'undertime' => $rows->filter(fn (array $r) => $r['undertime_count'] > 0)->count(),
        ];
    }

    /**
     * @return Collection<int, int> user_id set already submitted (non-voided) for month/year
     */
    public function alreadySubmittedUserIds(int $month, int $year): Collection
    {
        return AttendanceAdjustmentSubmissionItem::query()
            ->where('month', $month)
            ->where('year', $year)
            ->whereHas('submission', fn ($q) => $q->where('status', 'submitted'))
            ->pluck('user_id');
    }

    /**
     * @param  Collection<int, array>  $rows  the filtered rows the Timekeeper is submitting
     * @param  array<int, int>  $departmentIds
     * @return array{submission: AttendanceAdjustmentSubmission, submitted_count: int, skipped_count: int, skipped_names: array<int, string>}
     */
    public function submitToLeaveManager(
        Collection $rows,
        int $month,
        int $year,
        ?string $employeeType,
        array $departmentIds,
        string $departmentLabel,
        User $actor,
    ): array {
        $alreadySubmitted = $this->alreadySubmittedUserIds($month, $year);

        $toSubmit = $rows->reject(fn (array $r) => $alreadySubmitted->contains($r['user_id']))->values();
        $skipped = $rows->filter(fn (array $r) => $alreadySubmitted->contains($r['user_id']))->values();

        return DB::transaction(function () use ($toSubmit, $skipped, $month, $year, $employeeType, $departmentIds, $departmentLabel, $actor) {
            $submission = AttendanceAdjustmentSubmission::create([
                'submitted_by' => $actor->id,
                'month' => $month,
                'year' => $year,
                'employee_type' => $employeeType,
                'department_ids' => $departmentIds,
                'department_label' => $departmentLabel,
                'item_count' => $toSubmit->count(),
                'skipped_count' => $skipped->count(),
                'status' => 'submitted',
            ]);

            foreach ($toSubmit as $row) {
                AttendanceAdjustmentSubmissionItem::create([
                    'submission_id' => $submission->id,
                    'user_id' => $row['user_id'],
                    'month' => $month,
                    'year' => $year,
                    'emp_no' => $row['emp_no'],
                    'name' => $row['name'],
                    'department' => $row['department'],
                    'position' => $row['position'],
                    'employee_type' => $row['employee_type'],
                    'unfiled_count' => $row['unfiled_count'],
                    'tardiness_count' => $row['tardiness_count'],
                    'tardiness_minutes' => $row['tardiness_minutes'],
                    'undertime_count' => $row['undertime_count'],
                    'undertime_minutes' => $row['undertime_minutes'],
                    'remarks' => $row['remarks'],
                ]);
            }

            try {
                HRAuditTrail::create([
                    'actor_user_id' => $actor->id,
                    'module' => 'attendance',
                    'action' => 'adjustment_summary_submitted',
                    'target_type' => 'attendance_adjustment_submission',
                    'target_id' => $submission->id,
                    'details' => [
                        'month' => $month,
                        'year' => $year,
                        'employee_type' => $employeeType,
                        'department_ids' => $departmentIds,
                        'department_label' => $departmentLabel,
                        'submitted_count' => $toSubmit->count(),
                        'skipped_count' => $skipped->count(),
                    ],
                ]);
            } catch (\Exception) {
                // audit failure must not block submission
            }

            return [
                'submission' => $submission,
                'submitted_count' => $toSubmit->count(),
                'skipped_count' => $skipped->count(),
                'skipped_names' => $skipped->pluck('name')->all(),
            ];
        });
    }

    /**
     * @param  Collection<int, Department>  $departments
     * @return Collection<int, array>
     */
    public function buildExportRows(
        Collection $departments,
        int $month,
        int $year,
        ?string $employeeType,
        ?string $issueFilter,
        ?string $search,
        ?int $minCount = null,
    ): Collection {
        return $this->getFilteredRows($departments, $month, $year, $employeeType, $issueFilter, $search, $minCount);
    }

    /**
     * Build the Excel spreadsheet for the async export-job queue. Column
     * layout differs from AttendanceMonitoringExportService::buildSpreadsheet()
     * (adds Employee No., Department, split Tardiness/Undertime minutes, and
     * Status), so it's a separate method rather than a modification of that
     * one - the Monitoring Matrix export is untouched.
     *
     * @param  Collection<int, array>  $rows
     * @return array{0: Spreadsheet, 1: string}
     */
    public function buildSpreadsheet(Collection $rows, int $month, int $year, string $departmentLabel): array
    {
        $monthLabel = Carbon::createFromDate($year, $month, 1)->format('F Y');

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Attendance Adjustment Summary')
            ->setSubject($departmentLabel)
            ->setCreator('HRIS');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Adjustment Summary');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_FOLIO)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $sheet->mergeCells('A1:M1');
        $sheet->setCellValue('A1', 'Attendance Adjustment Summary - '.$departmentLabel.' - '.$monthLabel);
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $headers = [
            'A3' => '#', 'B3' => 'EMPLOYEE NO.', 'C3' => 'NAME', 'D3' => 'DEPARTMENT',
            'E3' => 'POSITION', 'F3' => 'EMPLOYEE TYPE', 'G3' => 'UNFILED LEAVE',
            'H3' => 'TARDINESS (COUNT)', 'I3' => 'TARDINESS (MINUTES)',
            'J3' => 'UNDERTIME (COUNT)', 'K3' => 'UNDERTIME (MINUTES)',
            'L3' => 'STATUS', 'M3' => 'REMARKS',
        ];
        $headerStyle = [
            'font' => ['bold' => true, 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'BDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];
        foreach ($headers as $cell => $label) {
            $sheet->setCellValue($cell, $label);
            $sheet->getStyle($cell)->applyFromArray($headerStyle);
        }

        $dataStyle = [
            'font' => ['size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];
        $leftStyle = array_merge($dataStyle, ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]]);

        $rowNum = 4;
        foreach ($rows as $i => $row) {
            $sheet->setCellValue("A{$rowNum}", $i + 1);
            $sheet->setCellValue("B{$rowNum}", $row['emp_no']);
            $sheet->setCellValue("C{$rowNum}", $row['name']);
            $sheet->setCellValue("D{$rowNum}", $row['department']);
            $sheet->setCellValue("E{$rowNum}", $row['position']);
            $sheet->setCellValue("F{$rowNum}", $row['employee_type']);
            $sheet->setCellValue("G{$rowNum}", $row['unfiled_count']);
            $sheet->setCellValue("H{$rowNum}", $row['tardiness_count']);
            $sheet->setCellValue("I{$rowNum}", $row['tardiness_minutes']);
            $sheet->setCellValue("J{$rowNum}", $row['undertime_count']);
            $sheet->setCellValue("K{$rowNum}", $row['undertime_minutes']);
            $sheet->setCellValue("L{$rowNum}", $row['status']);
            $sheet->setCellValue("M{$rowNum}", $row['remarks']);

            $sheet->getStyle("A{$rowNum}:L{$rowNum}")->applyFromArray($dataStyle);
            $sheet->getStyle("C{$rowNum}")->applyFromArray($leftStyle);
            $sheet->getStyle("M{$rowNum}")->applyFromArray($leftStyle);
            $rowNum++;
        }

        foreach (['A' => 5, 'B' => 12, 'C' => 22, 'D' => 18, 'E' => 16, 'F' => 14, 'G' => 10, 'H' => 10, 'I' => 10, 'J' => 10, 'K' => 10, 'L' => 12, 'M' => 40] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane('A4');

        $typeSafe = preg_replace('/[^A-Za-z0-9]+/', '_', $departmentLabel) ?: 'All';
        $filename = 'Attendance-Adjustment-Summary-'.$typeSafe.'-'.$monthLabel.'-'.now()->format('Ymd-His').'.xlsx';

        return [$spreadsheet, $filename];
    }
}
