<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Dtr;
use App\Models\Eta;
use App\Models\HRAuditTrail;
use App\Models\LeaveDate;
use App\Models\Locator;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

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

        // Bulk-load — no N+1
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

        return $employees->map(function (User $emp) use ($dtrs, $approvedLeaveDatesByUser, $locators, $etas, $month, $year) {
            $empDtrs       = $dtrs->get($emp->id, collect());
            $empLeaveDates = $approvedLeaveDatesByUser->get($emp->id, collect());
            $empLocators   = $locators->get($emp->id, collect());
            $empEtas       = $etas->get($emp->id, collect());

            $undertimeCount = $empDtrs->filter(fn ($d) => $d->undertime_minutes > 0)->count();
            $tardinessCount = $empDtrs->filter(fn ($d) => $d->late_minutes > 0)->count();

            $approvedLeaveDateStrings = $empLeaveDates->pluck('leave_date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->flip();

            $unfiledCount = $empDtrs->filter(function ($d) use ($approvedLeaveDateStrings) {
                return $d->is_absent && ! $approvedLeaveDateStrings->has(Carbon::parse($d->date)->toDateString());
            })->count();

            $officialLeaveCount = $empLeaveDates->count();

            $personalLocators    = $empLocators->filter(fn ($l) => strtolower((string) $l->application_type) === 'personal');
            $unofficialExitCount = $personalLocators->count();

            $totalMinutes = $empDtrs->sum(fn ($d) => (int) $d->late_minutes + (int) $d->undertime_minutes);

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
            foreach ($empDtrs->filter(fn ($d) => $d->late_minutes > 0) as $d) {
                $day = Carbon::parse($d->date)->day;
                $remarkEntries->push(['day' => $day, 'label' => $day.'-Tardy ('.$d->late_minutes.' mins)']);
            }

            // DTR: undertime days
            foreach ($empDtrs->filter(fn ($d) => $d->undertime_minutes > 0) as $d) {
                $day = Carbon::parse($d->date)->day;
                $remarkEntries->push(['day' => $day, 'label' => $day.'-Undertime ('.$d->undertime_minutes.' mins)']);
            }

            // Approved leave dates
            foreach ($empLeaveDates as $ld) {
                $day  = Carbon::parse($ld->leave_date)->day;
                $type = trim((string) ($ld->leaveRequest->leave_type ?? ''));
                if ($type !== '') {
                    $remarkEntries->push(['day' => $day, 'label' => $day.'-'.$type]);
                }
            }

            // Official locator slips
            $officialLocators = $empLocators->filter(fn ($l) => strtolower((string) $l->application_type) === 'official');
            foreach ($officialLocators as $l) {
                $day    = Carbon::parse($l->travel_date)->day;
                $detail = trim((string) ($l->detail ?? $l->location ?? ''));
                if ($detail !== '') {
                    $remarkEntries->push(['day' => $day, 'label' => $day.'-'.$detail]);
                }
            }

            // Personal locator slips
            foreach ($personalLocators as $l) {
                $day    = Carbon::parse($l->travel_date)->day;
                $detail = trim((string) ($l->detail ?? $l->location ?? ''));
                $label  = $detail !== '' ? $day.'-Locator ('.$detail.')' : $day.'-Locator';
                $remarkEntries->push(['day' => $day, 'label' => $label]);
            }

            // ETAs — expand each ETA to individual days within the month
            foreach ($empEtas as $eta) {
                $dest      = trim((string) ($eta->destination ?? $eta->purpose ?? ''));
                $start     = Carbon::parse($eta->departure_date)->startOfDay();
                $end       = Carbon::parse($eta->arrival_date)->startOfDay();
                $monthStart = Carbon::createFromDate($year, $month, 1)->startOfDay();
                $monthEnd   = $monthStart->copy()->endOfMonth();

                $cursor = $start->lt($monthStart) ? $monthStart->copy() : $start->copy();
                $until  = $end->gt($monthEnd) ? $monthEnd->copy() : $end->copy();

                while ($cursor->lte($until)) {
                    $day = $cursor->day;
                    $label = $dest !== '' ? $day.'-ETA ('.$dest.')' : $day.'-ETA';
                    $remarkEntries->push(['day' => $day, 'label' => $label]);
                    $cursor->addDay();
                }
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
                'name'                    => $name,
                'position'                => $emp->designation ?? $emp->position ?? '',
                'undertime_count'         => $undertimeCount,
                'tardiness_count'         => $tardinessCount,
                'unfiled_count'           => $unfiledCount,
                'official_leave_count'    => $officialLeaveCount,
                'unofficial_exit_count'   => $unofficialExitCount,
                'total_minutes'           => $totalMinutes,
                'personal_locator_minutes'=> $personalLocatorMinutes,
                'remarks'                 => $fullRemarks,
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
     * @return array{0: Spreadsheet, 1: string}  [spreadsheet, filename]
     */
    public function buildSpreadsheet(Collection $departments, int $month, int $year, ?User $actor = null): array
    {
        $actor    ??= Auth::user();
        $deptName = $departments->pluck('Dept_name')->filter()->implode(' / ');
        $rows     = $this->getRows($departments, $month, $year);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Attendance Monitoring Matrix')
            ->setSubject($deptName)
            ->setCreator('HRIS');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Matrix');

        $monthLabel = Carbon::createFromDate($year, $month, 1)->format('F Y');

        // Row 1: Department name
        $sheet->mergeCells('A1:K1');
        $sheet->setCellValue('A1', strtoupper($deptName));
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(20);

        // Row 2: Report subtitle
        $sheet->mergeCells('A2:K2');
        $sheet->setCellValue('A2', strtoupper($deptName).' CGC Employees\' Attendance, Leave and Locator Monitoring Matrix');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(28);

        // Row 3: Month label
        $sheet->mergeCells('A3:K3');
        $sheet->setCellValue('A3', 'For the Month of: '.$monthLabel);
        $sheet->getStyle('A3')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10],
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
            'font'      => ['bold' => true, 'size' => 8],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'BDD7EE']],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];

        foreach ($headers as $cell => $label) {
            $sheet->setCellValue($cell, $label);
            $sheet->getStyle($cell)->applyFromArray($headerStyle);
        }
        $sheet->getRowDimension(4)->setRowHeight(72);

        $dataStyle = [
            'font'      => ['size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
        ];
        $nameStyle    = array_merge($dataStyle, ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]]);
        $remarksStyle = array_merge($dataStyle, ['font' => ['size' => 8], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]]);

        $rowNum = 5;
        foreach ($rows as $i => $row) {
            $sheet->setCellValue("A{$rowNum}", $i + 1);
            $sheet->setCellValue("B{$rowNum}", $row['name']);
            $sheet->setCellValue("C{$rowNum}", $row['position']);
            $sheet->setCellValue("D{$rowNum}", $row['undertime_count'] ?: 'NONE');
            $sheet->setCellValue("E{$rowNum}", $row['tardiness_count'] ?: 'NONE');
            $sheet->setCellValue("F{$rowNum}", $row['unfiled_count'] ?: 'NONE');
            $sheet->setCellValue("G{$rowNum}", $row['official_leave_count'] ?: 'NONE');
            $sheet->setCellValue("H{$rowNum}", $row['unofficial_exit_count'] ?: 'NONE');
            $sheet->setCellValue("I{$rowNum}", $row['total_minutes'] ?: 'NONE');
            $sheet->setCellValue("J{$rowNum}", $row['personal_locator_minutes'] ?: 'NONE');
            $sheet->setCellValue("K{$rowNum}", $row['remarks']);

            $sheet->getStyle("A{$rowNum}:J{$rowNum}")->applyFromArray($dataStyle);
            $sheet->getStyle("B{$rowNum}")->applyFromArray($nameStyle);
            $sheet->getStyle("K{$rowNum}")->applyFromArray($remarksStyle);
            $sheet->getRowDimension($rowNum)->setRowHeight(20);

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
        $aoName        = $this->buildFullName($actor);
        $aoDesignation = $actor->designation ?? $actor->position ?? 'Administrative Officer';

        $dept          = $departments->first();
        $deptHeadName  = '';
        $deptHeadDesig = '';
        if ($dept && ! empty($dept->EmpNo) && $dept->EmpNo !== 'UNASSIGNED') {
            $head = User::where('EmpNo', $dept->EmpNo)->first();
            if ($head) {
                $deptHeadName  = $this->buildFullName($head);
                $deptHeadDesig = $head->designation ?? $head->position ?? 'Department Head';
            }
        }

        $sigRow = $rowNum + 1; // one blank row after last data row

        $labelStyle = [
            'font'      => ['size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $sigNameStyle = [
            'font'      => ['bold' => true, 'size' => 10, 'underline' => true],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $sigDesigStyle = [
            'font'      => ['size' => 9, 'italic' => true],
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
                'module'        => 'monitoring_matrix',
                'action'        => 'export',
                'target_type'   => 'department',
                'target_id'     => $departments->first()?->Dept_id,
                'details'       => [
                    'month'          => $month,
                    'year'           => $year,
                    'departments'    => $departments->pluck('Dept_name')->toArray(),
                    'employee_count' => $rows->count(),
                    'filename'       => $filename,
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
    public function generateExcelResponse(Collection $departments, int $month, int $year): \Symfony\Component\HttpFoundation\StreamedResponse
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
