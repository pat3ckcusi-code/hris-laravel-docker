<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Dtr;
use App\Models\DtrExcuse;
use App\Models\EmployeeShiftSchedule;
use App\Models\Eta;
use App\Models\HRAuditTrail;
use App\Models\LeaveDate;
use App\Models\Locator;
use App\Models\UniformInspectionDetail;
use App\Models\User;
use App\Support\WorkSchedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceMonitoringExportService
{
    /**
     * Compute one row per employee for the given departments and month.
     *
     * @param  Collection<int, Department>  $departments
     * @return Collection<int, array>
     */
    public function getRows(Collection $departments, int $month, int $year): Collection
    {
        $deptIds = $departments->pluck('Dept_id')->toArray();

        $employees = User::whereIn('Dept_id', $deptIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $employeeIds = $employees->pluck('id')->toArray();

        // Bulk-load - no N+1
        $dtrs = Dtr::whereIn('employee_id', $employeeIds)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get()
            ->groupBy('employee_id');

        $approvedLeaveDatesByUser = LeaveDate::where('is_cancelled', false)
            ->whereHas('leaveRequest', function ($q) use ($employeeIds) {
                $q->whereIn('user_id', $employeeIds)->where('status', 'approved');
            })
            ->whereYear('leave_date', $year)
            ->whereMonth('leave_date', $month)
            ->with('leaveRequest:id,user_id,leave_type')
            ->get()
            ->groupBy(fn ($ld) => $ld->leaveRequest->user_id ?? 0);

        $locators = Locator::whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->whereYear('travel_date', $year)
            ->whereMonth('travel_date', $month)
            ->get()
            ->groupBy('user_id');

        // ETAs that overlap with the month (departure or arrival within the month)
        $etas = Eta::whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->where(function ($q) use ($month, $year) {
                $q->where(function ($q2) use ($month, $year) {
                    $q2->whereYear('departure_date', $year)->whereMonth('departure_date', $month);
                })->orWhere(function ($q2) use ($month, $year) {
                    $q2->whereYear('arrival_date', $year)->whereMonth('arrival_date', $month);
                });
            })
            ->get()
            ->groupBy('user_id');

        $uniformViolations = UniformInspectionDetail::with('inspection')
            ->whereIn('employee_id', $employeeIds)
            ->whereHas('inspection', fn ($q) => $q
                ->whereYear('inspection_date', $year)
                ->whereMonth('inspection_date', $month)
            )
            ->get()
            ->groupBy('employee_id');

        $dtrExcuses = DtrExcuse::whereIn('user_id', $employeeIds)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->get()
            ->groupBy('user_id');

        $periodStart = Carbon::createFromDate($year, $month, 1)->toDateString();
        $periodEnd = Carbon::createFromDate($year, $month, 1)->endOfMonth()->toDateString();

        $allAssignments = EmployeeShiftSchedule::whereIn('user_id', $employeeIds)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get()
            ->groupBy('user_id')
            ->map(fn ($group) => $group->keyBy(fn ($a) => $a->date->toDateString()));

        return $employees->map(function (User $emp) use ($dtrs, $approvedLeaveDatesByUser, $locators, $etas, $uniformViolations, $dtrExcuses, $month, $year, $allAssignments) {
            $empDtrs = $dtrs->get($emp->id, collect());
            $empLeaveDates = $approvedLeaveDatesByUser->get($emp->id, collect());
            $empLocators = $locators->get($emp->id, collect());
            $empEtas = $etas->get($emp->id, collect());
            $empViolations = $uniformViolations->get($emp->id, collect());
            $empAssignments = $allAssignments->get($emp->id, collect());

            // Keyed by date string for O(1) excuse lookup.
            $empExcusesByDate = $dtrExcuses->get($emp->id, collect())
                ->keyBy(fn ($e) => Carbon::parse($e->date)->toDateString());

            // Exclude DTR records that fall on rest days (per-date shift schedule).
            $workDtrs = $empDtrs->filter(
                fn ($d) => ! WorkSchedule::isRestDay($emp, Carbon::parse($d->date), $empAssignments)
            );

            $approvedLeaveDateStrings = $empLeaveDates->pluck('leave_date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->flip();

            $undertimeCount = $workDtrs->filter(function ($d) use ($empExcusesByDate) {
                if ($d->undertime_minutes <= 0) {
                    return false;
                }
                $excuse = $empExcusesByDate[Carbon::parse($d->date)->toDateString()] ?? null;

                return ! ($excuse && ($excuse->is_full_day || $excuse->excuse_pm_out));
            })->count();

            $tardinessCount = $workDtrs->filter(function ($d) use ($empExcusesByDate) {
                if ($d->late_minutes <= 0) {
                    return false;
                }
                $excuse = $empExcusesByDate[Carbon::parse($d->date)->toDateString()] ?? null;

                return ! ($excuse && ($excuse->is_full_day || $excuse->excuse_am_in || $excuse->excuse_pm_in));
            })->count();

            $unfiledCount = $workDtrs->filter(function ($d) use ($approvedLeaveDateStrings, $empExcusesByDate) {
                if (! $d->is_absent) {
                    return false;
                }
                $dateStr = Carbon::parse($d->date)->toDateString();
                if ($approvedLeaveDateStrings->has($dateStr)) {
                    return false;
                }
                $excuse = $empExcusesByDate[$dateStr] ?? null;

                return ! ($excuse && $excuse->is_full_day);
            })->count();

            $officialLeaveCount = $empLeaveDates->count();

            $personalLocators = $empLocators->filter(fn ($l) => strtolower((string) $l->application_type) === 'personal');
            $unofficialExitCount = $personalLocators->count();

            $totalMinutes = $workDtrs->sum(function ($d) use ($empExcusesByDate) {
                $excuse = $empExcusesByDate[Carbon::parse($d->date)->toDateString()] ?? null;
                if (! $excuse) {
                    return (int) $d->late_minutes + (int) $d->undertime_minutes;
                }
                $late = ($excuse->is_full_day || $excuse->excuse_am_in || $excuse->excuse_pm_in) ? 0 : (int) $d->late_minutes;
                $ut = ($excuse->is_full_day || $excuse->excuse_pm_out) ? 0 : (int) $d->undertime_minutes;

                return $late + $ut;
            });

            $personalLocatorMinutes = $personalLocators->sum(function ($l) {
                if (! $l->intended_departure_time || ! $l->intended_arrival_time) {
                    return 0;
                }
                try {
                    $dep = Carbon::createFromFormat('H:i:s', $l->intended_departure_time)
                        ?? Carbon::createFromFormat('H:i', $l->intended_departure_time);
                    $arr = Carbon::createFromFormat('H:i:s', $l->intended_arrival_time)
                        ?? Carbon::createFromFormat('H:i', $l->intended_arrival_time);

                    return max(0, $dep->diffInMinutes($arr));
                } catch (\Exception) {
                    return 0;
                }
            });

            // --- Build unified remarks sorted by day ---
            // Each entry: ['day' => int, 'label' => string]
            $remarkEntries = collect();

            // DTR: tardiness days
            foreach ($workDtrs->filter(fn ($d) => $d->late_minutes > 0) as $d) {
                $day = Carbon::parse($d->date)->day;
                $remarkEntries->push(['day' => $day, 'label' => $day.'-Tardy ('.$d->late_minutes.' mins)']);
            }

            // DTR: undertime days
            foreach ($workDtrs->filter(fn ($d) => $d->undertime_minutes > 0) as $d) {
                $day = Carbon::parse($d->date)->day;
                $remarkEntries->push(['day' => $day, 'label' => $day.'-Undertime ('.$d->undertime_minutes.' mins)']);
            }

            // Approved leave dates
            foreach ($empLeaveDates as $ld) {
                $day = Carbon::parse($ld->leave_date)->day;
                $type = trim((string) ($ld->leaveRequest->leave_type ?? ''));
                if ($type !== '') {
                    $remarkEntries->push(['day' => $day, 'label' => $day.'-'.$type]);
                }
            }

            // Official locator slips
            $officialLocators = $empLocators->filter(fn ($l) => strtolower((string) $l->application_type) === 'official');
            foreach ($officialLocators as $l) {
                $day = Carbon::parse($l->travel_date)->day;
                $detail = trim((string) ($l->detail ?? $l->location ?? ''));
                if ($detail !== '') {
                    $remarkEntries->push(['day' => $day, 'label' => $day.'-'.$detail]);
                }
            }

            // Personal locator slips
            foreach ($personalLocators as $l) {
                $day = Carbon::parse($l->travel_date)->day;
                $detail = trim((string) ($l->detail ?? $l->location ?? ''));
                $label = $detail !== '' ? $day.'-Locator ('.$detail.')' : $day.'-Locator';
                $remarkEntries->push(['day' => $day, 'label' => $label]);
            }

            // ETAs - expand each ETA to individual days within the month
            foreach ($empEtas as $eta) {
                $dest = trim((string) ($eta->destination ?? $eta->purpose ?? ''));
                $start = Carbon::parse($eta->departure_date)->startOfDay();
                $end = Carbon::parse($eta->arrival_date)->startOfDay();
                $monthStart = Carbon::createFromDate($year, $month, 1)->startOfDay();
                $monthEnd = $monthStart->copy()->endOfMonth();

                $cursor = $start->lt($monthStart) ? $monthStart->copy() : $start->copy();
                $until = $end->gt($monthEnd) ? $monthEnd->copy() : $end->copy();

                while ($cursor->lte($until)) {
                    $day = $cursor->day;
                    $label = $dest !== '' ? $day.'-ETA ('.$dest.')' : $day.'-ETA';
                    $remarkEntries->push(['day' => $day, 'label' => $label]);
                    $cursor->addDay();
                }
            }

            // Excused days
            foreach ($empExcusesByDate as $dateStr => $excuse) {
                $day = Carbon::parse($dateStr)->day;

                $typeLabel = match ($excuse->excuse_type) {
                    'power_interruption'  => 'Power Interruption',
                    'system_failure'      => 'System Failure',
                    'weather_disturbance' => 'Weather Disturbance',
                    default               => 'Other',
                };

                if ($excuse->is_full_day) {
                    $scope = 'Full Day';
                } else {
                    $slots = [];
                    if ($excuse->excuse_am_in)  $slots[] = 'AM In';
                    if ($excuse->excuse_am_out) $slots[] = 'AM Out';
                    if ($excuse->excuse_pm_in)  $slots[] = 'PM In';
                    if ($excuse->excuse_pm_out) $slots[] = 'PM Out';
                    $scope = $slots ? implode(', ', $slots) : '';
                }

                $parts = array_filter([$typeLabel, $scope, trim((string) ($excuse->reason ?? ''))]);
                $remarkEntries->push(['day' => $day, 'label' => $day.'-Excused: '.implode(' | ', $parts)]);
            }

            // Uniform violations
            foreach ($empViolations as $v) {
                $day = $v->inspection?->inspection_date?->day;
                if (! $day) {
                    continue;
                }
                $label = $day.'-Uniform Violation ('.$v->violation_type.')';
                if (! empty($v->remarks)) {
                    $label .= ': '.$v->remarks;
                }
                $remarkEntries->push(['day' => $day, 'label' => $label]);
            }

            $fullRemarks = $remarkEntries
                ->sortBy('day')
                ->pluck('label')
                ->implode(', ');

            $name = trim(implode(', ', array_filter([
                $emp->last_name ?? '',
                implode(' ', array_filter([$emp->first_name ?? '', $emp->middle_name ?? ''])),
            ])));
            if (empty($name)) {
                $name = $emp->name ?? '';
            }

            return [
                'name' => $name,
                'position' => $emp->designation ?? $emp->position ?? '',
                'is_exempt' => (bool) $emp->dtr_exempt,
                'undertime_count' => $undertimeCount,
                'tardiness_count' => $tardinessCount,
                'unfiled_count' => $unfiledCount,
                'official_leave_count' => $officialLeaveCount,
                'unofficial_exit_count' => $unofficialExitCount,
                'total_minutes' => $totalMinutes,
                'personal_locator_minutes' => $personalLocatorMinutes,
                'remarks' => $fullRemarks,
            ];
        });
    }

    private function buildFullName(?User $user): string
    {
        if (! $user) {
            return '';
        }
        $parts = array_filter([
            $user->first_name ?? '',
            $user->middle_name ?? '',
            $user->last_name ?? '',
        ]);

        return implode(' ', $parts) ?: ($user->name ?? '');
    }

    /**
     * Build the spreadsheet object without streaming it.
     * Pass $actor explicitly when calling from a queue job (Auth::user() won't work there).
     *
     * @param  Collection<int, Department>  $departments
     * @return array{0: Spreadsheet, 1: string} [spreadsheet, filename]
     */
    public function buildSpreadsheet(Collection $departments, int $month, int $year, ?User $actor = null): array
    {
        $actor ??= Auth::user();
        $deptName = $departments->pluck('Dept_name')->filter()->implode(' / ');
        $rows = $this->getRows($departments, $month, $year);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Attendance Monitoring Matrix')
            ->setSubject($deptName)
            ->setCreator('HRIS');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Matrix');

        // Print-ready: landscape, Folio (8.5" x 13"), narrow margins, and fit all
        // columns to one page wide so the matrix prints cleanly.
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_FOLIO)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()
            ->setTop(0.75)->setBottom(0.75)
            ->setLeft(0.25)->setRight(0.25)
            ->setHeader(0.3)->setFooter(0.3);

        $monthLabel = Carbon::createFromDate($year, $month, 1)->format('F Y');

        // Row 1: Department name
        $sheet->mergeCells('A1:K1');
        $sheet->setCellValue('A1', strtoupper($deptName));
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(20);

        // Row 2: Report subtitle
        $sheet->mergeCells('A2:K2');
        $sheet->setCellValue('A2', strtoupper($deptName).' CGC Employees\' Attendance, Leave and Locator Monitoring Matrix');
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);

        // Row 3: Month label
        $sheet->mergeCells('A3:K3');
        $sheet->setCellValue('A3', 'For the Month of: '.$monthLabel);
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(16);

        // Row 4: Column headers
        $headers = [
            'A4' => '#',
            'B4' => 'NAME',
            'C4' => 'POSITION',
            'D4' => "NO. OF\nUNDER-\nTIME",
            'E4' => "NO. OF\nTARDI-\nNESS",
            'F4' => "NO. OF\nUNFILED\nLEAVE",
            'G4' => "NO. OF\nDAYS\nABSENT W/\nOFFICIAL\nLEAVE",
            'H4' => "NO. OF\nDAYS\nABSENT W/\nUN-\nOFFICIAL\nEXIT",
            'I4' => "NO. OF\nMINUTES /\nTARDINESS/\nUNDER-\nTIME\nTIME",
            'J4' => "NO. OF\nMINUTES ON\nLOCATOR\n(PERSONAL)",
            'K4' => 'REMARKS',
        ];

        $headerStyle = [
            'font' => ['bold' => true, 'size' => 8],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'BDD7EE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];

        foreach ($headers as $cell => $label) {
            $sheet->setCellValue($cell, $label);
            $sheet->getStyle($cell)->applyFromArray($headerStyle);
        }
        $sheet->getRowDimension(4)->setRowHeight(72);

        $dataStyle = [
            'font' => ['size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];
        $nameStyle = array_merge($dataStyle, ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]]);
        $remarksStyle = array_merge($dataStyle, ['font' => ['size' => 8], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]]);

        $rowNum = 5;
        foreach ($rows as $i => $row) {
            $sheet->setCellValue("A{$rowNum}", $i + 1);
            $sheet->setCellValue("B{$rowNum}", $row['name']);
            $sheet->setCellValue("C{$rowNum}", $row['position']);
            // DTR-derived columns read "EXEMPT" for biometric/DTR-exempt employees
            // (they have no DTR), while leave/locator columns stay populated.
            $sheet->setCellValue("D{$rowNum}", $row['is_exempt'] ? 'EXEMPT' : ($row['undertime_count'] ?: 0));
            $sheet->setCellValue("E{$rowNum}", $row['is_exempt'] ? 'EXEMPT' : ($row['tardiness_count'] ?: 0));
            $sheet->setCellValue("F{$rowNum}", $row['is_exempt'] ? 'EXEMPT' : ($row['unfiled_count'] ?: 0));
            $sheet->setCellValue("G{$rowNum}", $row['official_leave_count'] ?: 0);
            $sheet->setCellValue("H{$rowNum}", $row['unofficial_exit_count'] ?: 0);
            $sheet->setCellValue("I{$rowNum}", $row['is_exempt'] ? 'EXEMPT' : ($row['total_minutes'] ?: 0));
            $sheet->setCellValue("J{$rowNum}", $row['personal_locator_minutes'] ?: 0);
            $sheet->setCellValue("K{$rowNum}", $row['remarks']);

            $sheet->getStyle("A{$rowNum}:J{$rowNum}")->applyFromArray($dataStyle);
            $sheet->getStyle("B{$rowNum}")->applyFromArray($nameStyle);
            $sheet->getStyle("K{$rowNum}")->applyFromArray($remarksStyle);
            $sheet->getRowDimension($rowNum)->setRowHeight(-1);

            $rowNum++;
        }

        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getColumnDimension('C')->setWidth(14);
        foreach (['D', 'E', 'F', 'G', 'H', 'I', 'J'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(10);
        }
        $sheet->getColumnDimension('K')->setWidth(42);
        $sheet->freezePane('A5');

        // --- Signature block ---
        $aoName = $this->buildFullName($actor);
        $aoDesignation = $actor->designation ?? $actor->position ?? 'Administrative Officer';

        $dept = $departments->first();
        $deptHeadName = '';
        $deptHeadDesig = '';
        if ($dept && ! empty($dept->EmpNo) && $dept->EmpNo !== 'UNASSIGNED') {
            $head = User::where('EmpNo', $dept->EmpNo)->first();
            if ($head) {
                $deptHeadName = $this->buildFullName($head);
                $deptHeadDesig = $head->designation ?? $head->position ?? 'Department Head';
            }
        }

        $sigRow = $rowNum + 1; // one blank row after last data row

        $labelStyle = [
            'font' => ['size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $sigNameStyle = [
            'font' => ['bold' => true, 'size' => 10, 'underline' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $sigDesigStyle = [
            'font' => ['size' => 9, 'italic' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];

        // "Prepared by:" label
        $sheet->mergeCells("B{$sigRow}:D{$sigRow}");
        $sheet->setCellValue("B{$sigRow}", 'Prepared by:');
        $sheet->getStyle("B{$sigRow}")->applyFromArray($labelStyle);

        // "Approved by:" label
        $sheet->mergeCells("H{$sigRow}:K{$sigRow}");
        $sheet->setCellValue("H{$sigRow}", 'Approved by:');
        $sheet->getStyle("H{$sigRow}")->applyFromArray($labelStyle);

        // AO name
        $sheet->mergeCells('B'.($sigRow + 1).':D'.($sigRow + 1));
        $sheet->setCellValue('B'.($sigRow + 1), strtoupper($aoName));
        $sheet->getStyle('B'.($sigRow + 1))->applyFromArray($sigNameStyle);

        // Dept head name
        $sheet->mergeCells('H'.($sigRow + 1).':K'.($sigRow + 1));
        $sheet->setCellValue('H'.($sigRow + 1), strtoupper($deptHeadName));
        $sheet->getStyle('H'.($sigRow + 1))->applyFromArray($sigNameStyle);

        // AO designation
        $sheet->mergeCells('B'.($sigRow + 2).':D'.($sigRow + 2));
        $sheet->setCellValue('B'.($sigRow + 2), $aoDesignation);
        $sheet->getStyle('B'.($sigRow + 2))->applyFromArray($sigDesigStyle);

        // Dept head designation
        $sheet->mergeCells('H'.($sigRow + 2).':K'.($sigRow + 2));
        $sheet->setCellValue('H'.($sigRow + 2), $deptHeadDesig);
        $sheet->getStyle('H'.($sigRow + 2))->applyFromArray($sigDesigStyle);

        foreach ([$sigRow, $sigRow + 1, $sigRow + 2] as $r) {
            $sheet->getRowDimension($r)->setRowHeight(18);
        }

        $filename = 'Monitoring-Matrix-'.$monthLabel.'-'.now()->format('Ymd-His').'.xlsx';

        try {
            HRAuditTrail::create([
                'actor_user_id' => $actor->id,
                'module' => 'monitoring_matrix',
                'action' => 'export',
                'target_type' => 'department',
                'target_id' => $departments->first()?->Dept_id,
                'details' => [
                    'month' => $month,
                    'year' => $year,
                    'departments' => $departments->pluck('Dept_name')->toArray(),
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
     * Generate and stream the monthly monitoring matrix Excel file.
     *
     * @param  Collection<int, Department>  $departments
     */
    public function generateExcelResponse(Collection $departments, int $month, int $year): StreamedResponse
    {
        [$spreadsheet, $filename] = $this->buildSpreadsheet($departments, $month, $year);

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
}
