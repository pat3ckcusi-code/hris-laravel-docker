<?php

namespace App\Services;

use App\Models\Locator;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LocatorExportService
{
    public function generateExcelResponse(Locator $locator)
    {
        $user = Auth::user();
        $owner = $locator->user ?? User::find($locator->user_id);

        // Build employee full name
        $fullName = $this->buildFullName($owner);

        // Travel date
        $travelDate = $locator->travel_date
            ? Carbon::parse($locator->travel_date)->toFormattedDateString()
            : '';

        // Department
        $dept = '';
        if ($owner && !empty($owner->Dept_id)) {
            $department = Department::find($owner->Dept_id);
            $dept = $department ? ($department->Dept_name ?? '') : '';
        }

        // Department head name
        $deptHeadName = $this->resolveDeptHeadName($owner);

        // Approval date
        $dateApproved = $locator->approved_at
            ? Carbon::parse($locator->approved_at)->toFormattedDateString()
            : ($locator->updated_at ? $locator->updated_at->toFormattedDateString() : '');

        // Load template
        $templatePath = storage_path('app/templates/LOCATOR.xls');
        if (!file_exists($templatePath)) {
            abort(404, 'Locator print template not found.');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getSheet(0); // "front (3)" sheet

        $isOfficial = strtolower($locator->application_type ?? '') === 'official';

        // --- Copy 1 (Left: columns B–J) ---
        $sheet->setCellValue('B6', $fullName);
        $sheet->setCellValue('I6', $travelDate);
        $sheet->setCellValue('C10', '✓');
        $sheet->setCellValue('H13', $locator->intended_departure_time 
            ? date('h:i A', strtotime($locator->intended_departure_time)) 
            : '');
        $sheet->setCellValue('H14', $locator->intended_arrival_time 
            ? date('h:i A', strtotime($locator->intended_arrival_time)) 
            : '');

        if ($isOfficial) {
            $sheet->setCellValue('E16', '✓');
        } else {
            $sheet->setCellValue('H16', '✓');
        }

        $sheet->setCellValue('D18', $locator->detail ?? '');

        if ($deptHeadName) {
            $sheet->setCellValue('D25', $deptHeadName);
        }

        $sheet->setCellValue('H31', $locator->actual_arrival_time ?? '');

        // --- Copy 2 (Right: columns M–U) ---
        $sheet->setCellValue('M6', $fullName);
        $sheet->setCellValue('T6', $travelDate);
        $sheet->setCellValue('N10', '✓');
        $sheet->setCellValue('S13', $locator->intended_departure_time 
            ? date('h:i A', strtotime($locator->intended_departure_time)) 
            : '');
        $sheet->setCellValue('S14', $locator->intended_arrival_time 
            ? date('h:i A', strtotime($locator->intended_arrival_time)) 
            : '');

        if ($isOfficial) {
            $sheet->setCellValue('P16', '✓');
        } else {
            $sheet->setCellValue('S16', '✓');
        }

        $sheet->setCellValue('O18', $locator->detail ?? '');

        if ($deptHeadName) {
            $sheet->setCellValue('O25', $deptHeadName);
        }

        $sheet->setCellValue('S31', $locator->actual_arrival_time ?? '');

        // Apply sheet protection
        $lockApplied = false;
        try {
            $this->protectAllSheets($spreadsheet, $owner);
            $lockApplied = true;
        } catch (\Exception $e) {
            Log::warning('Locator sheet protection failed', [
                'locator_id' => $locator->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Audit log
        $filename = 'Locator-' . $locator->id . '-' . now()->format('Ymd-His') . '.xls';

        Log::info('Locator print action', [
            'locator_id' => $locator->id,
            'printed_by' => $user->id,
            'role' => $user->access_level ?? 'unknown',
            'lock_applied' => $lockApplied,
            'format_preserved' => true,
            'filename' => $filename,
            'timestamp' => now()->toDateTimeString(),
        ]);

        HRAuditTrail::create([
            'actor_user_id' => $user->id,
            'module' => 'Locator',
            'action' => 'print',
            'target_type' => 'locator',
            'target_id' => $locator->id,
            'details' => [
                'locator_id' => $locator->id,
                'employee_name' => $fullName,
                'role' => $user->access_level ?? 'unknown',
                'dept_head_name' => $deptHeadName,
                'lock_applied' => $lockApplied,
                'filename' => $filename,
            ],
        ]);

        // Stream directly to browser without persisting to disk
        return response()->streamDownload(
            function () use ($spreadsheet): void {
                $writer = IOFactory::createWriter($spreadsheet, 'Xls');
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.ms-excel',
            ]
        );
    }

    private function buildFullName(?User $user): string
    {
        if (!$user) {
            return '';
        }

        $parts = [];
        if (!empty($user->first_name)) $parts[] = $user->first_name;
        if (!empty($user->middle_name)) $parts[] = $user->middle_name;
        if (!empty($user->last_name)) $parts[] = $user->last_name;
        if (empty($parts) && !empty($user->name)) $parts[] = $user->name;

        return implode(' ', $parts);
    }

    private function resolveDeptHeadName(?User $owner): string
    {
        if (!$owner || empty($owner->Dept_id)) {
            return '';
        }

        $department = Department::find($owner->Dept_id);
        if (!$department || empty($department->EmpNo) || $department->EmpNo === 'UNASSIGNED') {
            return '';
        }

        $head = User::where('EmpNo', $department->EmpNo)->first();
        if (!$head) {
            return '';
        }

        return $this->buildFullName($head);
    }

    /**
     * Lock all sheets in the spreadsheet to prevent editing.
     */
    private function protectAllSheets(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet, ?User $owner): void
    {
        $first = $owner->first_name ?? ($owner->firstname ?? '');
        $last  = $owner->last_name ?? ($owner->lastname ?? '');
        $password = strtoupper($first . substr((string) $last, 0, 1));

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            // NOTE: We intentionally skip bulk getStyle($range)->setLocked() here.
            // All cells are locked by default in Excel when sheet protection is
            // enabled. Calling getStyle() on the entire range forces PhpSpreadsheet
            // to create explicit style objects per cell, which destroys the
            // inherited template formatting (fonts, borders, alignment, colours).
            $protection = $sheet->getProtection();
            $protection->setSheet(true);
            $protection->setPassword($password);
            $protection->setSort(false);
            $protection->setInsertRows(false);
            $protection->setInsertColumns(false);
            $protection->setFormatCells(false);
            $protection->setFormatColumns(false);
            $protection->setFormatRows(false);
            $protection->setDeleteRows(false);
            $protection->setDeleteColumns(false);
            $protection->setAutoFilter(false);
            $protection->setPivotTables(false);
            $protection->setObjects(false);
            $protection->setScenarios(false);
            $protection->setSelectLockedCells(false);
            $protection->setSelectUnlockedCells(false);
        }
    }
}
