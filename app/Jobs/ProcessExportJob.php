<?php

namespace App\Jobs;

use App\Models\Department;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\AttendanceMonitoringExportService;
use App\Services\DepartmentService;
use App\Services\Form48ExportService;
use App\Services\HRDashboardService;
use App\Services\LeaveCardExportService;
use App\Services\PdsService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(private readonly ExportJob $exportJob) {}

    public function handle(
        PdsService $pdsService,
        Form48ExportService $form48Service,
        LeaveCardExportService $leaveCardService,
        AttendanceMonitoringExportService $monitoringService,
        HRDashboardService $dashboardService,
        DepartmentService $departmentService,
    ): void {
        $job = $this->exportJob;

        try {
            $job->markProcessing();

            $exportsDir = storage_path('app/exports');
            if (! is_dir($exportsDir)) {
                mkdir($exportsDir, 0775, true);
            }

            [$path, $filename, $mime] = match ($job->type) {
                'pds' => $this->handlePds($job, $pdsService),
                'form48' => $this->handleForm48($job, $form48Service),
                'form48_dept_zip' => $this->handleForm48DeptZip($job, $form48Service),
                'form48_dept' => $this->handleForm48Dept($job, $form48Service),
                'monitoring_matrix' => $this->handleMonitoringMatrix($job, $monitoringService, $departmentService),
                'leave_card' => $this->handleLeaveCard($job, $leaveCardService),
                'hr_reports' => $this->handleHrReports($job, $dashboardService),
                default => throw new \RuntimeException("Unknown export type: {$job->type}"),
            };

            $job->markCompleted($path, $filename, $mime);
        } catch (\Throwable $e) {
            $job->markFailed($e->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->exportJob->markFailed($exception->getMessage() ?: 'Export timed out. Please try again.');
    }

    // ── PDS ───────────────────────────────────────────────────────────────────

    private function handlePds(ExportJob $job, PdsService $pdsService): array
    {
        $user = User::findOrFail($job->params['user_id']);
        $spreadsheet = $pdsService->exportToExcel($user);

        $path = "exports/{$job->id}.xlsx";
        $absPath = storage_path("app/{$path}");
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($absPath);
        $spreadsheet->disconnectWorksheets();

        $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $user->name ?? 'Employee');
        $filename = "PDS_{$safe}_".now()->format('Y-m-d').'.xlsx';
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return [$path, $filename, $mime];
    }

    // ── FORM 48 — INDIVIDUAL ─────────────────────────────────────────────────

    private function handleForm48(ExportJob $job, Form48ExportService $exportService): array
    {
        $params = $job->params;
        $employee = User::findOrFail($params['target_user_id']);

        if ($employee->dtr_exempt) {
            throw new \RuntimeException('This employee is exempt from biometric/DTR.');
        }

        $dtrType = $params['dtr_type'];
        $month = $params['month'];
        $period = (int) ($params['period'] ?? 1);

        [$from, $to] = $this->resolvePeriod($month, $dtrType, $period);
        $monthYear = $this->resolveMonthYearLabel($from, $to, $dtrType);

        $templatePath = storage_path('app/templates/form48.xls');
        if (! file_exists($templatePath)) {
            throw new \RuntimeException('Form 48 template not found.');
        }

        $records = $exportService->buildRecords($employee->id, $from, $to);
        $leaveMap = $exportService->buildLeaveMap($employee->id, $from, $to);
        $etaMap = $exportService->buildEtaMap($employee->id, $from, $to);
        $locatorMap = $exportService->buildLocatorMap($employee->id, $from, $to);

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();
        $exportService->fill($sheet, $records, $employee, $monthYear, $from, $leaveMap, $etaMap, $locatorMap);

        $safe = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $exportService->formatName($employee))) ?: 'DTR';
        $filename = "CSC_Form_48_({$safe}).xlsx";
        $path = "exports/{$job->id}.xlsx";
        $absPath = storage_path("app/{$path}");

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($absPath);
        $spreadsheet->disconnectWorksheets();

        return [$path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    // ── FORM 48 — DEPARTMENT ZIP ─────────────────────────────────────────────

    private function handleForm48DeptZip(ExportJob $job, Form48ExportService $exportService): array
    {
        $params = $job->params;
        $dtrType = $params['dtr_type'];
        $month = $params['month'];
        $period = (int) ($params['period'] ?? 1);
        $employeeType = $params['employee_type'] ?? null;
        $deptId = (int) $params['dept_id'];

        [$from, $to] = $this->resolvePeriod($month, $dtrType, $period);
        $monthYear = $this->resolveMonthYearLabel($from, $to, $dtrType);

        $templatePath = storage_path('app/templates/form48.xls');
        if (! file_exists($templatePath)) {
            throw new \RuntimeException('Form 48 template not found.');
        }

        $employees = User::where('Dept_id', $deptId)
            ->where('dtr_exempt', false)
            ->when($employeeType, fn ($q, $t) => $q->where('employee_type', $t))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        if ($employees->isEmpty()) {
            throw new \RuntimeException('No employees found in the selected department.');
        }

        $dept = Department::find($deptId);
        $deptSafe = preg_replace('/[^A-Za-z0-9_]/', '_', $dept?->Dept_name ?? (string) $deptId);

        $generated = [];

        foreach ($employees as $employee) {
            $records = $exportService->buildRecords($employee->id, $from, $to);
            $leaveMap = $exportService->buildLeaveMap($employee->id, $from, $to);
            $etaMap = $exportService->buildEtaMap($employee->id, $from, $to);
            $locatorMap = $exportService->buildLocatorMap($employee->id, $from, $to);

            if (empty($records) && empty($leaveMap) && empty($etaMap) && empty($locatorMap)) {
                continue;
            }

            $spreadsheet = IOFactory::load($templatePath);
            $sheet = $spreadsheet->getActiveSheet();
            $exportService->fill($sheet, $records, $employee, $monthYear, $from, $leaveMap, $etaMap, $locatorMap);

            $safe = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $exportService->formatName($employee))) ?: 'Employee_'.$employee->id;
            $tmpPath = tempnam(sys_get_temp_dir(), 'dtr_');
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tmpPath);
            $spreadsheet->disconnectWorksheets();

            $generated[$safe.'.xlsx'] = $tmpPath;
        }

        if (empty($generated)) {
            throw new \RuntimeException('No time records found for any employee in the selected department and period.');
        }

        $typeLabel = $employeeType ? ucwords(str_replace('-', ' ', $employeeType)) : 'All';
        $typeSafe = preg_replace('/[^A-Za-z0-9]+/', '_', $typeLabel) ?: 'All';
        $monthLabel = Carbon::parse($month.'-01')->format('FY');

        $path = "exports/{$job->id}.zip";
        $absPath = storage_path("app/{$path}");
        $filename = "CSC_Form_48_{$deptSafe}_{$typeSafe}_{$monthLabel}.zip";

        $zip = new \ZipArchive;
        if ($zip->open($absPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            foreach ($generated as $p) {
                @unlink($p);
            }
            throw new \RuntimeException('Failed to create ZIP archive.');
        }

        foreach ($generated as $entryName => $tmpPath) {
            $zip->addFile($tmpPath, $entryName);
        }
        $zip->close();

        foreach ($generated as $p) {
            @unlink($p);
        }

        return [$path, $filename, 'application/zip'];
    }

    // ── FORM 48 — DEPARTMENT MULTI-SHEET ─────────────────────────────────────

    private function handleForm48Dept(ExportJob $job, Form48ExportService $exportService): array
    {
        $params = $job->params;
        $dtrType = $params['dtr_type'];
        $month = $params['month'];
        $period = (int) ($params['period'] ?? 1);
        $employeeType = $params['employee_type'] ?? null;
        $deptId = (int) $params['dept_id'];

        [$from, $to] = $this->resolvePeriod($month, $dtrType, $period);
        $monthYear = $this->resolveMonthYearLabel($from, $to, $dtrType);

        $templatePath = storage_path('app/templates/form48.xls');
        if (! file_exists($templatePath)) {
            throw new \RuntimeException('Form 48 template not found.');
        }

        $employees = User::where('Dept_id', $deptId)
            ->where('dtr_exempt', false)
            ->when($employeeType, fn ($q, $t) => $q->where('employee_type', $t))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        if ($employees->isEmpty()) {
            throw new \RuntimeException('No employees found in the selected department.');
        }

        $dept = Department::find($deptId);
        $deptSafe = preg_replace('/[^A-Za-z0-9_]/', '_', $dept?->Dept_name ?? (string) $deptId);

        $workbook = IOFactory::load($templatePath);
        $template = $workbook->getActiveSheet();
        $filled = 0;

        foreach ($employees as $employee) {
            $records = $exportService->buildRecords($employee->id, $from, $to);
            $leaveMap = $exportService->buildLeaveMap($employee->id, $from, $to);
            $etaMap = $exportService->buildEtaMap($employee->id, $from, $to);
            $locatorMap = $exportService->buildLocatorMap($employee->id, $from, $to);

            if (empty($records) && empty($leaveMap) && empty($etaMap) && empty($locatorMap)) {
                continue;
            }

            $clone = clone $template;
            $sheetName = mb_substr(
                preg_replace('/[^\w ]/', '', $exportService->formatName($employee)),
                0, 31
            ) ?: "Employee_{$employee->id}";
            $clone->setTitle($sheetName);
            $workbook->addSheet($clone);
            $exportService->fill($clone, $records, $employee, $monthYear, $from, $leaveMap, $etaMap, $locatorMap);
            $filled++;
        }

        if ($filled === 0) {
            throw new \RuntimeException('No time records found for any employee in the selected department and period.');
        }

        $workbook->removeSheetByIndex(0);
        $workbook->setActiveSheetIndex(0);

        $typeLabel = $employeeType ? ucwords(str_replace('-', ' ', $employeeType)) : 'All';
        $typeSafe = preg_replace('/[^A-Za-z0-9]+/', '_', $typeLabel) ?: 'All';
        $monthLabel = Carbon::parse($month.'-01')->format('FY');
        $filename = "CSC_Form_48_{$deptSafe}_{$typeSafe}_{$monthLabel}.xlsx";
        $path = "exports/{$job->id}.xlsx";
        $absPath = storage_path("app/{$path}");

        IOFactory::createWriter($workbook, 'Xlsx')->save($absPath);
        $workbook->disconnectWorksheets();

        return [$path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    // ── MONITORING MATRIX ─────────────────────────────────────────────────────

    private function handleMonitoringMatrix(
        ExportJob $job,
        AttendanceMonitoringExportService $monitoringService,
        DepartmentService $departmentService
    ): array {
        $actor = User::findOrFail($job->user_id);
        $depts = $departmentService->resolveAllDepartmentsForAdminOfficer($actor);

        if ($depts->isEmpty()) {
            throw new \RuntimeException('No departments assigned to your account.');
        }

        $month = (int) $job->params['month'];
        $year = (int) $job->params['year'];

        [$spreadsheet, $filename] = $monitoringService->buildSpreadsheet($depts, $month, $year, $actor);

        $path = "exports/{$job->id}.xlsx";
        $absPath = storage_path("app/{$path}");
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($absPath);
        $spreadsheet->disconnectWorksheets();

        return [$path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    // ── LEAVE CARD ────────────────────────────────────────────────────────────

    private function handleLeaveCard(ExportJob $job, LeaveCardExportService $leaveCardService): array
    {
        $user = User::findOrFail($job->params['user_id']);
        $year = (int) $job->params['year'];
        $month = (int) $job->params['month'];

        [$wb, $filename] = $leaveCardService->buildSpreadsheet($user, $year, $month);

        $path = "exports/{$job->id}.xlsx";
        $absPath = storage_path("app/{$path}");
        IOFactory::createWriter($wb, 'Xlsx')->save($absPath);
        $wb->disconnectWorksheets();

        return [$path, $filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    // ── HR REPORTS (CSV) ──────────────────────────────────────────────────────

    private function handleHrReports(ExportJob $job, HRDashboardService $dashboardService): array
    {
        $chart = $dashboardService->buildChartData(null);
        $filename = 'hr-workforce-report-'.now()->format('Ymd-His').'.csv';
        $path = "exports/{$job->id}.csv";
        $absPath = storage_path("app/{$path}");

        $handle = fopen($absPath, 'wb');
        fputcsv($handle, ['Metric', 'Category', 'Value']);
        foreach ($chart as $metric => $payload) {
            $labels = $payload['labels'] ?? [];
            $values = $payload['values'] ?? [];
            foreach ($labels as $index => $label) {
                fputcsv($handle, [$metric, $label, (string) ($values[$index] ?? 0)]);
            }
        }
        fclose($handle);

        return [$path, $filename, 'text/csv'];
    }

    // ── DTR helpers (mirrors DtrController private methods) ───────────────────

    private function resolvePeriod(string $month, string $dtrType, int $period = 1): array
    {
        $carbon = Carbon::parse($month.'-01');

        if ($dtrType === 'semi-monthly') {
            if ($period === 1) {
                return [$carbon->format('Y-m-d'), $carbon->copy()->setDay(15)->format('Y-m-d')];
            }

            return [
                $carbon->copy()->setDay(16)->format('Y-m-d'),
                $carbon->copy()->endOfMonth()->format('Y-m-d'),
            ];
        }

        return [$carbon->format('Y-m-d'), $carbon->copy()->endOfMonth()->format('Y-m-d')];
    }

    private function resolveMonthYearLabel(string $from, string $to, string $dtrType): string
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);

        if ($dtrType === 'semi-monthly') {
            return $f->format('F').' '.$f->day.'–'.$t->day.', '.$f->year;
        }

        return $f->format('F Y');
    }
}
