<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Dtr;
use App\Models\User;
use App\Services\Form48ExportService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DtrController extends Controller
{
    private const ADMIN_ROLES = ['hr manager', 'payroll manager', 'time keeper', 'records manager'];

    // ── PERIOD HELPERS ────────────────────────────────────────────────────────

    /**
     * Compute [from, to] date strings from a YYYY-MM month, DTR type, and half.
     *
     * @return array{0: string, 1: string}
     */
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

    /** Human-readable label written into the Form 48 "For the Month of" cells. */
    private function resolveMonthYearLabel(string $from, string $to, string $dtrType): string
    {
        $f = Carbon::parse($from);
        $t = Carbon::parse($to);

        if ($dtrType === 'semi-monthly') {
            return $f->format('F').' '.$f->day.'–'.$t->day.', '.$f->year;
        }

        return $f->format('F Y');
    }

    /**
     * Resolve the acting user's access tier.
     *
     * Returns:
     *   isAdmin   — full cross-department access (ADMIN_ROLES)
     *   isOfficer — department head or administrative officer: admin-like UI scoped to their own department
     *   deptId    — the officer/head's Dept_id; null for full admins and plain employees
     *
     * Aborts 403 for any role outside these three tiers.
     *
     * @return array{isAdmin: bool, isOfficer: bool, deptId: int|null}
     */
    private function resolveContext(User $user): array
    {
        $role      = strtolower(trim((string) ($user->access_level ?? '')));
        $isAdmin   = in_array($role, self::ADMIN_ROLES, true);
        $isOfficer = in_array($role, ['administrative officer', 'department head'], true);

        if (! $isAdmin && ! $isOfficer && $role !== 'employee') {
            abort(403);
        }

        return [
            'isAdmin'   => $isAdmin,
            'isOfficer' => $isOfficer,
            'deptId'    => $isOfficer ? (int) $user->Dept_id : null,
        ];
    }

    // ── LIST VIEW ─────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptId' => $officerDeptId] = $this->resolveContext($user);

        if ($isAdmin) {
            $departments = Department::orderBy('Dept_name')->get();
            $employees   = User::orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_type']);
            $officerDept = null;
        } elseif ($isOfficer) {
            $departments = collect();
            $officerDept = Department::find($officerDeptId);
            $employees   = User::where('Dept_id', $officerDeptId)
                ->orderBy('last_name')->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'employee_type']);
        } else {
            $departments = collect();
            $employees   = collect();
            $officerDept = null;
        }

        return view('attendance.dtr.index', compact(
            'isAdmin', 'isOfficer', 'departments', 'employees', 'officerDeptId', 'officerDept'
        ));
    }

    // ── DATATABLE AJAX DATA ───────────────────────────────────────────────────

    public function data(Request $request): JsonResponse
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptId' => $officerDeptId] = $this->resolveContext($user);

        $periodRules = [
            'dtr_type' => ['required', 'in:monthly,semi-monthly'],
            'month'    => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'period'   => ['nullable', 'in:1,2'],
        ];

        if ($isAdmin || $isOfficer) {
            $request->validate(array_merge($periodRules, [
                'employee_id' => ['required', 'integer', 'exists:users,id'],
            ]));
            $employee = User::findOrFail($request->integer('employee_id'));

            // Officers are locked to their own department — reject cross-dept requests.
            if ($isOfficer && (int) $employee->Dept_id !== $officerDeptId) {
                abort(403, 'You may only view DTR records for employees in your own department.');
            }
        } else {
            $request->validate($periodRules);
            $employee = $user;
        }

        [$from, $to] = $this->resolvePeriod(
            $request->input('month'),
            $request->input('dtr_type'),
            $request->integer('period', 1)
        );

        $draw   = $request->integer('draw', 1);
        $start  = max(0, $request->integer('start', 0));
        $length = max(1, $request->integer('length', 31));

        $query = Dtr::where('employee_id', $employee->id)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date');

        $total = $query->count();
        $rows  = (clone $query)->offset($start)->limit($length)->get();

        $data = $rows->map(static function (Dtr $dtr): array {
            $isLate      = ($dtr->late_minutes      ?? 0) > 0;
            $isUndertime = ($dtr->undertime_minutes ?? 0) > 0;

            return [
                'date'              => Carbon::parse($dtr->date)->format('M d, Y (D)'),
                'time_in_am'        => $dtr->time_in_am  ?? '—',
                'time_out_am'       => $dtr->time_out_am ?? '—',
                'time_in_pm'        => $dtr->time_in_pm  ?? '—',
                'time_out_pm'       => $dtr->time_out_pm ?? '—',
                'late_minutes'      => $dtr->late_minutes      ?? 0,
                'undertime_minutes' => $dtr->undertime_minutes ?? 0,
                'is_late'           => $isLate,
                'is_undertime'      => $isUndertime,
                'source_badge'      => match ($dtr->source) {
                    'biometric' => '<span class="hris-badge badge-approved">Biometric</span>',
                    'manual'    => '<span class="hris-badge" style="background:#e5e7eb;color:#374151;">Manual</span>',
                    default     => '<span style="color:#9ca3af;">—</span>',
                },
                'status_badge'      => $dtr->is_absent
                    ? '<span class="hris-badge badge-rejected">Absent</span>'
                    : '<span class="hris-badge badge-approved">Present</span>',
            ];
        });

        return response()->json([
            'draw'            => $draw,
            'recordsTotal'    => $total,
            'recordsFiltered' => $total,
            'data'            => $data,
        ]);
    }

    // ── FORM 48 DOWNLOAD ──────────────────────────────────────────────────────

    public function downloadForm48(Request $request, Form48ExportService $exportService): StreamedResponse
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptId' => $officerDeptId] = $this->resolveContext($user);

        $periodRules = [
            'dtr_type' => ['required', 'in:monthly,semi-monthly'],
            'month'    => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'period'   => ['nullable', 'in:1,2'],
        ];

        if ($isAdmin || $isOfficer) {
            $request->validate(array_merge($periodRules, [
                'employee_id' => ['required', 'integer', 'exists:users,id'],
            ]));
            $employee = User::findOrFail($request->integer('employee_id'));

            if ($isOfficer && (int) $employee->Dept_id !== $officerDeptId) {
                abort(403, 'You may only export DTR records for employees in your own department.');
            }
        } else {
            $request->validate($periodRules);
            $employee = $user;
        }

        $dtrType = $request->input('dtr_type');
        $month   = $request->input('month');
        $period  = $request->integer('period', 1);

        [$from, $to] = $this->resolvePeriod($month, $dtrType, $period);
        $monthYear   = $this->resolveMonthYearLabel($from, $to, $dtrType);

        $templatePath = storage_path('app/templates/form48.xls');
        abort_unless(file_exists($templatePath), 500, 'Form 48 template not found.');

        $records     = $exportService->buildRecords($employee->id, $from, $to);
        $spreadsheet = IOFactory::load($templatePath);
        $sheet       = $spreadsheet->getActiveSheet();

        $exportService->fill($sheet, $records, $employee, $monthYear, $from);

        $safe         = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $exportService->formatName($employee))) ?: 'DTR';
        $downloadName = "CSC_Form_48_({$safe}).xlsx";

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $downloadName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    // ── DEPARTMENT BULK — ZIP (one xlsx per employee) ─────────────────────────

    public function downloadDepartmentZip(Request $request, Form48ExportService $exportService): BinaryFileResponse
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptId' => $officerDeptId] = $this->resolveContext($user);
        abort_unless($isAdmin || $isOfficer, 403);

        $request->validate([
            'dtr_type'      => ['required', 'in:monthly,semi-monthly'],
            'month'         => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'period'        => ['nullable', 'in:1,2'],
            'employee_type' => ['nullable', 'in:permanent,co-terminus,casual,job order,contractual'],
            // Officers' dept is resolved from their profile — skip validating the param.
            ...($isAdmin ? ['dept_id' => ['required', 'integer', 'exists:departments,Dept_id']] : []),
        ]);

        // Officers are always locked to their own department regardless of what was submitted.
        $deptId       = $isOfficer ? $officerDeptId : $request->integer('dept_id');
        $dtrType      = $request->input('dtr_type');
        $month        = $request->input('month');
        $period       = $request->integer('period', 1);
        $employeeType = $request->input('employee_type') ?: null;

        [$from, $to] = $this->resolvePeriod($month, $dtrType, $period);
        $monthYear   = $this->resolveMonthYearLabel($from, $to, $dtrType);

        $employees    = User::where('Dept_id', $deptId)
            ->when($employeeType, fn ($q, $type) => $q->where('employee_type', $type))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
        $templatePath = storage_path('app/templates/form48.xls');

        abort_if($employees->isEmpty(), 404, 'No employees found in the selected department.');
        abort_unless(file_exists($templatePath), 500, 'Form 48 template not found.');

        $generated = [];    // zip entry name → tmp file path

        foreach ($employees as $employee) {
            $records = $exportService->buildRecords($employee->id, $from, $to);
            if (empty($records)) {
                continue;
            }

            $spreadsheet = IOFactory::load($templatePath);
            $sheet       = $spreadsheet->getActiveSheet();
            $exportService->fill($sheet, $records, $employee, $monthYear, $from);

            $safe    = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $exportService->formatName($employee))) ?: 'Employee_'.$employee->id;
            $tmpPath = tempnam(sys_get_temp_dir(), 'dtr_');

            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tmpPath);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            $generated[$safe.'.xlsx'] = $tmpPath;
        }

        if (empty($generated)) {
            abort(404, 'No DTR records found for any employee in the selected department.');
        }

        $dept       = Department::find($deptId);
        $deptSafe   = preg_replace('/[^A-Za-z0-9_]/', '_', $dept?->Dept_name ?? (string) $deptId);
        $typeLabel  = $employeeType ? ucwords(str_replace('-', ' ', $employeeType)) : 'All';
        $typeSafe   = preg_replace('/[^A-Za-z0-9]+/', '_', $typeLabel) ?: 'All';
        $monthLabel = Carbon::parse($month.'-01')->format('FY');
        $zipPath    = tempnam(sys_get_temp_dir(), 'dtr_zip_');

        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            foreach ($generated as $p) {
                @unlink($p);
            }
            abort(500, 'Failed to create ZIP archive.');
        }

        foreach ($generated as $entryName => $path) {
            $zip->addFile($path, $entryName);
        }
        $zip->close();

        foreach ($generated as $p) {
            @unlink($p);
        }

        return response()->download($zipPath, "CSC_Form_48_{$deptSafe}_{$typeSafe}_{$monthLabel}.zip", [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    // ── DEPARTMENT BULK — MULTI-SHEET WORKBOOK ────────────────────────────────

    public function downloadDepartmentForm48(Request $request, Form48ExportService $exportService): StreamedResponse
    {
        $user = $request->user();
        ['isAdmin' => $isAdmin, 'isOfficer' => $isOfficer, 'deptId' => $officerDeptId] = $this->resolveContext($user);
        abort_unless($isAdmin || $isOfficer, 403);

        $request->validate([
            'dtr_type'      => ['required', 'in:monthly,semi-monthly'],
            'month'         => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'period'        => ['nullable', 'in:1,2'],
            'employee_type' => ['nullable', 'in:permanent,co-terminus,casual,job order,contractual'],
            ...($isAdmin ? ['dept_id' => ['required', 'integer', 'exists:departments,Dept_id']] : []),
        ]);

        $deptId       = $isOfficer ? $officerDeptId : $request->integer('dept_id');
        $dtrType      = $request->input('dtr_type');
        $month        = $request->input('month');
        $period       = $request->integer('period', 1);
        $employeeType = $request->input('employee_type') ?: null;

        [$from, $to] = $this->resolvePeriod($month, $dtrType, $period);
        $monthYear   = $this->resolveMonthYearLabel($from, $to, $dtrType);

        $employees    = User::where('Dept_id', $deptId)
            ->when($employeeType, fn ($q, $type) => $q->where('employee_type', $type))
            ->orderBy('last_name')->orderBy('first_name')
            ->get();
        $templatePath = storage_path('app/templates/form48.xls');

        abort_if($employees->isEmpty(), 404, 'No employees found in the selected department.');
        abort_unless(file_exists($templatePath), 500, 'Form 48 template not found.');

        $workbook = IOFactory::load($templatePath);
        $template = $workbook->getActiveSheet();
        $filled   = 0;

        foreach ($employees as $employee) {
            $records = $exportService->buildRecords($employee->id, $from, $to);
            if (empty($records)) {
                continue;
            }

            $clone     = clone $template;
            $sheetName = mb_substr(
                preg_replace('/[^\w ]/', '', $exportService->formatName($employee)),
                0, 31
            ) ?: "Employee_{$employee->id}";
            $clone->setTitle($sheetName);

            // addSheet before fill so merged-cell operations resolve against the workbook.
            $workbook->addSheet($clone);
            $exportService->fill($clone, $records, $employee, $monthYear, $from);
            $filled++;
        }

        if ($filled === 0) {
            abort(404, 'No DTR records found for any employee in the selected department.');
        }

        // Drop the blank template sheet (index 0) and activate the first filled sheet.
        $workbook->removeSheetByIndex(0);
        $workbook->setActiveSheetIndex(0);

        $dept         = Department::find($deptId);
        $deptSafe     = preg_replace('/[^A-Za-z0-9_]/', '_', $dept?->Dept_name ?? (string) $deptId);
        $typeLabel    = $employeeType ? ucwords(str_replace('-', ' ', $employeeType)) : 'All';
        $typeSafe     = preg_replace('/[^A-Za-z0-9]+/', '_', $typeLabel) ?: 'All';
        $monthLabel   = Carbon::parse($month.'-01')->format('FY');
        $downloadName = "CSC_Form_48_{$deptSafe}_{$typeSafe}_{$monthLabel}.xlsx";

        return response()->streamDownload(
            function () use ($workbook): void {
                $writer = IOFactory::createWriter($workbook, 'Xlsx');
                $writer->save('php://output');
                $workbook->disconnectWorksheets();
            },
            $downloadName,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }
}
