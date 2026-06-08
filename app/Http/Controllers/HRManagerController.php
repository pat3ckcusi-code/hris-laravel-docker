<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DocumentRequest;
use App\Models\Dtr;
use App\Models\HRAuditTrail;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use App\Models\Pds;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use App\Services\DepartmentService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HRManagerController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureHrManager($request);

        $departments = $this->departmentOptions();

        return view('hr-manager.dashboard', [
            'departments' => $departments,
            'summary' => $this->buildSummaryCards(),
            'chartDataUrl' => route('hr-manager.chart-data'),
            'initialChartData' => $this->buildChartData(null),
        ]);
    }

    public function getChartData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $departmentId = $request->integer('department');
        $deptKey = $departmentId > 0 ? $departmentId : 'all';

        $data = Cache::remember("hr_chart_data_{$deptKey}", now()->addMinutes(10), function () use ($departmentId) {
            return $this->buildChartData($departmentId > 0 ? $departmentId : null);
        });

        return response()->json($data);
    }

    /**
     * Return employees matching a chart filter (department, gender, status, age_group, length_of_service).
     */
    public function getEmployeesByFilter(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $department = trim((string) $request->query('department', ''));
        $gender = trim((string) $request->query('gender', ''));
        $status = trim((string) $request->query('status', ''));
        $ageGroup = trim((string) $request->query('age_group', ''));
        $lengthOfService = trim((string) $request->query('length_of_service', ''));

        $query = User::query()
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->select(
                'users.id',
                'users.EmpNo',
                'users.name',
                'users.designation',
                'users.Status',
                'users.employee_type',
                'users.date_hired',
                'users.created_at',
                'departments.Dept_name'
            )
            ->whereRaw("LOWER(REPLACE(REPLACE(users.access_level, '-', ' '), '_', ' ')) = 'employee'");

        if ($department !== '') {
            // Allow either Dept_id or Dept_name
            if (ctype_digit($department)) {
                $query->where('users.Dept_id', (int) $department);
            } else {
                $query->where('departments.Dept_name', $department);
            }
        }

        if ($status !== '') {
            $query->where('users.Status', $status);
        }

        $rows = $query->orderBy('users.name')->get();

        $userIds = $rows->pluck('id');
        $pdsMap = $this->pdsByUserId($userIds);

        $employees = [];

        foreach ($rows as $row) {
            $pds = $pdsMap->get($row->id, []);
            $genderVal = $this->extractGender($pds);

            // compute age (number) if birth_date exists
            $personal = (array) ($pds['pds-personal-info'] ?? []);
            $birthDate = trim((string) ($personal['personal[birth_date]'] ?? ''));
            $age = null;
            if ($birthDate !== '') {
                try {
                    $age = Carbon::parse($birthDate)->age;
                } catch (\Throwable $e) {
                    $age = null;
                }
            }

            // compute service bucket
            if (! empty($row->date_hired)) {
                try {
                    $yearsOfService = Carbon::parse($row->date_hired)->diffInYears(now());
                } catch (\Throwable $e) {
                    $yearsOfService = $this->extractYearsOfService($row->created_at, $pds);
                }
            } else {
                $yearsOfService = $this->extractYearsOfService($row->created_at, $pds);
            }
            $serviceBucket = $this->serviceBucket($yearsOfService);

            $ageBucket = $this->extractAgeBucket($pds);

            $employees[] = [
                'emp_no' => $row->EmpNo,
                'name' => $row->name,
                'position' => $row->designation,
                'gender' => $genderVal,
                'age' => $age,
                'age_bucket' => $ageBucket,
                'employee_type' => $row->employee_type ?? null,
                'status' => $row->Status,
                'date_hired' => $row->date_hired ? Carbon::parse($row->date_hired)->toDateString() : null,
                'length_of_service' => $serviceBucket,
                'department' => $row->Dept_name,
            ];
        }

        // Apply client-side filters (age group, length of service, gender)
        $filtered = collect($employees)->filter(function ($emp) use ($gender, $ageGroup, $lengthOfService) {
            if ($gender !== '' && strcasecmp($emp['gender'], $gender) !== 0) {
                return false;
            }

            if ($ageGroup !== '' && $emp['age_bucket'] !== $ageGroup) {
                return false;
            }

            if ($lengthOfService !== '' && $emp['length_of_service'] !== $lengthOfService) {
                return false;
            }

            return true;
        })->values()->all();

        return response()->json($filtered);
    }

    public function records(Request $request): View
    {
        $this->ensureHrManager($request);

        $recordsPage = $this->recordsRows($request);

        return view('hr-manager.records', [
            'departments' => $this->departmentOptions(),
            'employees' => $recordsPage->items(),
            'recordsPagination' => $this->paginationPayload($recordsPage),
            'recordsDataUrl' => route('hr-manager.records.data'),
            'recordsFilters' => [
                'search' => trim((string) $request->query('search', '')),
                'department' => trim((string) $request->query('department', '')),
                'status' => trim((string) $request->query('status', '')),
            ],
        ]);
    }

    public function recordsData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $recordsPage = $this->recordsRows($request);

        return response()->json([
            'rows' => $recordsPage->items(),
            'pagination' => $this->paginationPayload($recordsPage),
        ]);
    }

    public function recordsAction(Request $request, User $user): JsonResponse
    {
        $this->ensureHrManager($request);

        $payload = $request->validate([
            'action' => ['required', 'in:edit,update,compliance-report'],
        ]);

        $this->storeAuditTrail(
            $request,
            'records',
            $payload['action'],
            User::class,
            (int) $user->id,
            [
                'employee_name' => $user->name,
                'employee_no' => $user->EmpNo,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Records action logged.',
        ]);
    }

    public function leave(Request $request): View
    {
        $this->ensureHrManager($request);

        $month = trim((string) $request->query('month', now()->format('Y-m')));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        $leavePage = $this->leaveRows($request, $month);

        return view('hr-manager.leave', [
            'departments' => $this->departmentOptions(),
            'requests' => $leavePage->items(),
            'leavePagination' => $this->paginationPayload($leavePage),
            'leaveDataUrl' => route('hr-manager.leave.data'),
            'leaveActionBaseUrl' => route('hr-manager.leave.action', ['leaveRequest' => '__ID__']),
            'leaveAnalyticsUrl' => route('hr-manager.leave.analytics'),
            'leaveNotifyManagerUrl' => route('hr-manager.leave.notify-manager'),
            'leaveFilters' => [
                'department' => trim((string) $request->query('department', '')),
                'status' => trim((string) $request->query('status', 'pending')),
                'month' => $month,
            ],
            'leaveChart' => $this->leaveUsageChart((int) $request->query('department', 0), $month),
            'holidayAlerts' => $this->buildHolidayLeaveAlerts(),
            'selectedMonth' => $month,
        ]);
    }

    public function leaveData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $month = trim((string) $request->query('month', now()->format('Y-m')));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        $leavePage = $this->leaveRows($request, $month);

        return response()->json([
            'rows' => $leavePage->items(),
            'pagination' => $this->paginationPayload($leavePage),
            'chart' => $this->leaveUsageChart((int) $request->query('department', 0), $month),
        ]);
    }

    public function leaveAction(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->ensureHrManager($request);

        $payload = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        if ($payload['action'] === 'approve') {
            $leaveRequest->status = 'approved';
            if (Schema::hasColumn('leave_requests', 'detailed_status')) {
                $leaveRequest->detailed_status = 'Approved';
            }
        } else {
            $leaveRequest->status = 'declined';
            if (Schema::hasColumn('leave_requests', 'detailed_status')) {
                $leaveRequest->detailed_status = 'Disapproved';
            }
            if (Schema::hasColumn('leave_requests', 'rejection_notes')) {
                $leaveRequest->rejection_notes = (string) ($payload['remarks'] ?? 'Rejected by HR Manager');
            }
        }

        $leaveRequest->save();

        $this->storeAuditTrail(
            $request,
            'leave',
            $payload['action'],
            LeaveRequest::class,
            (int) $leaveRequest->id,
            [
                'leave_type' => $leaveRequest->leave_type,
                'status' => $leaveRequest->status,
                'remarks' => $payload['remarks'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Leave request updated successfully.',
        ]);
    }

    public function frontdesk(Request $request): View
    {
        $this->ensureHrManager($request);

        $frontdeskPage = $this->frontdeskRows($request);

        return view('hr-manager.frontdesk', [
            'departments' => $this->departmentOptions(),
            'requests' => $frontdeskPage->items(),
            'frontdeskPagination' => $this->paginationPayload($frontdeskPage),
            'frontdeskDataUrl' => route('hr-manager.frontdesk.data'),
            'frontdeskActionBaseUrl' => route('hr-manager.frontdesk.action', ['documentRequest' => '__ID__']),
            'frontdeskCompleteBaseUrl' => route('hr-manager.frontdesk.complete', ['documentRequest' => '__ID__']),
            'headerImage' => asset('assets/login/mbs.jpg'),
            'footerImage' => asset('assets/login/mbs.jpg'),
        ]);
    }

    public function frontdeskData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $frontdeskPage = $this->frontdeskRows($request);

        return response()->json([
            'rows' => $frontdeskPage->items(),
            'pagination' => $this->paginationPayload($frontdeskPage),
        ]);
    }

    public function frontdeskAction(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->ensureHrManager($request);

        $payload = $request->validate([
            'action' => ['required', 'in:accept,reject,approve'],
        ]);

        $statusMap = [
            'accept' => 'Accepted',
            'reject' => 'Rejected',
            'approve' => 'Approved',
        ];

        $documentRequest->status = $statusMap[$payload['action']];
        $documentRequest->processed_by = (string) ($request->user()->name ?? 'HR Manager');
        $documentRequest->processed_on = now();
        $documentRequest->save();

        $this->storeAuditTrail(
            $request,
            'frontdesk',
            $payload['action'],
            DocumentRequest::class,
            (int) $documentRequest->id,
            [
                'document_type' => $documentRequest->document_type,
                'status' => $documentRequest->status,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Document request status updated.',
        ]);
    }

    public function frontdeskComplete(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->ensureHrManager($request);

        $documentRequest->status = 'Completed';
        $documentRequest->released_by = (string) ($request->user()->name ?? 'HR Manager');
        $documentRequest->released_on = now();
        $documentRequest->save();

        $this->storeAuditTrail(
            $request,
            'frontdesk',
            'complete',
            DocumentRequest::class,
            (int) $documentRequest->id,
            [
                'document_type' => $documentRequest->document_type,
                'status' => $documentRequest->status,
            ]
        );

        $employee = User::query()->where('EmpNo', $documentRequest->EmpNo)->first();
        if ($employee && $employee->email) {
            try {
                Mail::raw(
                    'Your requested document ('.$documentRequest->document_type.') is completed and ready for release.',
                    static function ($message) use ($employee): void {
                        $message->to($employee->email)->subject('HRIS Document Request Update');
                    }
                );
            } catch (\Throwable) {
                // Keep request completion successful when email transport is unavailable.
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Request completed and employee notification queued.',
        ]);
    }

    public function reports(Request $request): View
    {
        $this->ensureHrManager($request);

        $departmentId = $request->integer('department');

        return view('hr-manager.reports', [
            'departments' => $this->departmentOptions(),
            'initialChartData' => $this->buildChartData($departmentId > 0 ? $departmentId : null),
            'reportsChartUrl' => route('hr-manager.chart-data'),
            'exportPdfUrl' => route('hr-manager.reports.export', ['format' => 'pdf']),
            'exportExcelUrl' => route('hr-manager.reports.export', ['format' => 'excel']),
        ]);
    }

    public function exportReport(Request $request, string $format): StreamedResponse|RedirectResponse
    {
        $this->ensureHrManager($request);

        if (! in_array($format, ['pdf', 'excel'], true)) {
            return redirect()->route('hr-manager.reports');
        }

        $chart = $this->buildChartData(null);
        $filename = 'hr-workforce-report-'.now()->format('Ymd-His');

        return response()->streamDownload(function () use ($chart): void {
            $headers = ['Metric', 'Category', 'Value'];
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, $headers);

            foreach ($chart as $metric => $payload) {
                $labels = $payload['labels'] ?? [];
                $values = $payload['values'] ?? [];
                foreach ($labels as $index => $label) {
                    fputcsv($handle, [$metric, $label, (string) ($values[$index] ?? 0)]);
                }
            }

            fclose($handle);
        }, $filename.($format === 'pdf' ? '.pdf' : '.csv'), [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function audit(Request $request): View
    {
        $this->ensureHrManager($request);

        return view('hr-manager.audit', [
            'auditUsers' => $this->auditUsers(),
            'logs' => $this->auditRows($request),
            'auditDataUrl' => route('hr-manager.audit.data'),
        ]);
    }

    public function auditData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        return response()->json([
            'rows' => $this->auditRows($request),
        ]);
    }

    public function roles(Request $request): View
    {
        $this->ensureHrManager($request);

        $roles = ['records manager', 'leave manager', 'front desk', 'hr manager'];

        $users = User::query()
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->select('users.id', 'users.name', 'users.email', 'users.access_level', 'departments.Dept_name')
            ->whereIn(DB::raw("LOWER(REPLACE(REPLACE(users.access_level, '-', ' '), '_', ' '))"), $roles)
            ->orderBy('users.name')
            ->get();

        return view('hr-manager.roles', [
            'users' => $users,
            'availableRoles' => ['Records Manager', 'Leave Manager', 'Front Desk', 'HR Manager'],
        ]);
    }

    // ── Enhancement 1: Alerts ──────────────────────────────────────────────

    public function getAlerts(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $data = Cache::remember('hr_alerts', now()->addMinutes(5), fn () => $this->buildAlerts());

        return response()->json($data);
    }

    // ── Enhancement 3: Leave Analytics ────────────────────────────────────

    public function getLeaveAnalytics(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $departmentId = $request->integer('department');
        $deptKey = $departmentId > 0 ? $departmentId : 'all';

        $month = trim((string) $request->query('month', now()->format('Y-m')));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        $data = Cache::remember("hr_leave_analytics_{$deptKey}_{$month}", now()->addMinutes(5),
            fn () => $this->buildLeaveAnalytics($departmentId > 0 ? $departmentId : null, $month)
        );

        return response()->json($data);
    }

    public function notifyDeptManager(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $payload = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $employee = User::findOrFail($payload['user_id']);
        $deptService = app(DepartmentService::class);
        $dept = $deptService->resolveDepartmentForUser($employee);

        // Find department head
        $deptHead = null;
        if ($dept) {
            $deptHead = User::query()
                ->where('Dept_id', $dept->Dept_id)
                ->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'department head'")
                ->first();
        }

        if (! $deptHead || ! $deptHead->email) {
            return response()->json(['success' => false, 'message' => 'No department head email found.'], 422);
        }

        $balance = LeaveBalance::query()->where('user_id', $employee->id)->first();
        $vl = $balance ? round((float) $balance->VL, 1) : 0;
        $sl = $balance ? round((float) $balance->SL, 1) : 0;

        try {
            $deptHead->notify(new HrisTransactionNotification(
                'Leave Balance Alert',
                'Low Balance',
                [
                    'Employee' => $employee->name,
                    'Vacation Leave (VL)' => $vl.' days',
                    'Sick Leave (SL)' => $sl.' days',
                    'Department' => $dept?->Dept_name ?? 'N/A',
                ],
                $request->user()->name,
                'This employee may be at risk of filing Leave Without Pay (LWOP) if leave balances are not replenished soon.'
            ));
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Notification could not be sent.'], 500);
        }

        $this->storeAuditTrail($request, 'leave', 'notify_dept_manager', User::class, (int) $employee->id, [
            'employee' => $employee->name,
            'dept_head' => $deptHead->name,
            'vl' => $vl,
            'sl' => $sl,
        ]);

        return response()->json(['success' => true, 'message' => 'Department head notified successfully.']);
    }

    // ── Enhancement 5: Workforce Planning ─────────────────────────────────

    public function recordsPlanningData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $data = Cache::remember('hr_workforce_planning', now()->addMinutes(15), fn () => $this->buildWorkforcePlanning());

        return response()->json($data);
    }

    // ── Enhancement 2: Attendance Overview ────────────────────────────────

    public function attendanceOverview(Request $request): View
    {
        $this->ensureHrManager($request);

        return view('hr-manager.attendance-overview', [
            'departments' => $this->departmentOptions(),
            'attendanceDataUrl' => route('hr-manager.attendance.overview.data'),
        ]);
    }

    public function attendanceOverviewData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $month = trim((string) $request->query('month', now()->format('Y-m')));
        $departmentId = $request->integer('department');

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = now()->format('Y-m');
        }

        $deptKey = $departmentId > 0 ? $departmentId : 'all';
        $data = Cache::remember("hr_attendance_overview_{$month}_{$deptKey}", now()->addMinutes(10),
            fn () => $this->buildAttendanceOverview($month, $departmentId > 0 ? $departmentId : null)
        );

        return response()->json($data);
    }

    // ── Enhancement 4: Payroll Overview ───────────────────────────────────

    public function payrollOverview(Request $request): View
    {
        $this->ensureHrManager($request);

        return view('hr-manager.payroll-overview', [
            'payrollDataUrl' => route('hr-manager.payroll.overview.data'),
            'resolveBaseUrl' => route('hr-manager.payroll.exception.resolve', ['exception' => '__ID__']),
        ]);
    }

    public function payrollOverviewData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $data = Cache::remember('hr_payroll_overview', now()->addMinutes(5), fn () => $this->buildPayrollOverview());

        return response()->json($data);
    }

    public function resolvePayrollException(Request $request, PayrollException $exception): JsonResponse
    {
        $this->ensureHrManager($request);

        $exception->resolved_flag = true;
        $exception->save();

        Cache::forget('hr_payroll_overview');

        $this->storeAuditTrail($request, 'payroll', 'resolve_exception', PayrollException::class, (int) $exception->id, [
            'type' => $exception->type,
            'description' => $exception->description,
        ]);

        return response()->json(['success' => true, 'message' => 'Exception marked as resolved.']);
    }

    // ── System Settings ────────────────────────────────────────────────────

    public function settings(Request $request): View
    {
        $this->ensureHrManager($request);

        $settings = Setting::first();

        return view('hr-manager.settings', [
            'settings' => $settings,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->ensureHrManager($request);

        $validated = $request->validate([
            // General
            'system_name' => 'nullable|string|max:100',
            'org_name' => 'nullable|string|max:255',
            'support_email' => 'nullable|email|max:255',
            'timezone' => 'nullable|string|max:100',
            'date_format' => 'nullable|string|max:50',
            // Module toggles
            'records_enabled' => 'nullable|boolean',
            'leave_enabled' => 'nullable|boolean',
            'frontdesk_enabled' => 'nullable|boolean',
            'payroll_enabled' => 'nullable|boolean',
            'attendance_enabled' => 'nullable|boolean',
            'eta_enabled' => 'nullable|boolean',
            'pending_alert_threshold' => 'nullable|integer|min:1',
            // Email templates
            'email_template_subject' => 'nullable|string|max:255',
            'email_template_body' => 'nullable|string',
            // Attendance / shift schedule
            'work_start' => 'nullable|date_format:H:i',
            'lunch_return' => 'nullable|date_format:H:i',
            'work_end' => 'nullable|date_format:H:i',
            'morning_end' => 'nullable|date_format:H:i',
            'noon_end' => 'nullable|date_format:H:i',
            // Payroll
            'payroll_working_days_per_month' => 'nullable|integer|min:1|max:31',
            // Leave
            'leave_balance_decimals' => 'nullable|integer|min:0|max:5',
            // Signatories
            'mayor_name' => 'nullable|string|max:255',
            'mayor_designation' => 'nullable|string|max:255',
            'vice_mayor_name' => 'nullable|string|max:255',
            'vice_mayor_designation' => 'nullable|string|max:255',
            'hr_manager_name' => 'nullable|string|max:255',
            'hr_manager_designation' => 'nullable|string|max:255',
            // Notification / email from
            'mail_from_address' => 'nullable|email|max:255',
            'mail_from_name' => 'nullable|string|max:255',
            // Export
            'excel_sheet_password' => 'nullable|string|max:255',
            'excel_protection_enabled' => 'nullable|boolean',
            'pdf_font_family' => 'nullable|string|max:100',
            'pdf_font_size' => 'nullable|integer|min:6|max:72',
            // Dashboard
            'dashboard_cache_ttl' => 'nullable|integer|min:1|max:120',
        ]);

        $validated['records_enabled'] = $request->boolean('records_enabled');
        $validated['leave_enabled'] = $request->boolean('leave_enabled');
        $validated['frontdesk_enabled'] = $request->boolean('frontdesk_enabled');
        $validated['payroll_enabled'] = $request->boolean('payroll_enabled');
        $validated['attendance_enabled'] = $request->boolean('attendance_enabled');
        $validated['eta_enabled'] = $request->boolean('eta_enabled');
        $validated['excel_protection_enabled'] = $request->boolean('excel_protection_enabled');

        // Ensure email template fields are never null (database columns are NOT NULL)
        $validated['email_template_subject'] = $validated['email_template_subject'] ?? '';
        $validated['email_template_body'] = $validated['email_template_body'] ?? '';

        // Never overwrite the stored password with blank — only update when a new value is provided
        if (($validated['excel_sheet_password'] ?? '') === '') {
            unset($validated['excel_sheet_password']);
        }

        $settings = Setting::first();
        if ($settings) {
            $settings->update($validated);
        } else {
            $validated['email_template_subject'] ??= '';
            $validated['email_template_body'] ??= '';
            Setting::create($validated);
        }

        return redirect()->route('hr-manager.settings')->with('success', 'Settings updated successfully.');
    }

    public function backupDatabase(Request $request): StreamedResponse
    {
        $this->ensureHrManager($request);

        $dbName = config('database.connections.mysql.database');
        $filename = 'hris-backup-'.now()->format('Y-m-d_H-i-s').'.sql';

        return response()->streamDownload(function () use ($dbName) {
            $tables = collect(DB::select('SHOW TABLES'))->map(fn ($r) => array_values((array) $r)[0]);

            echo "-- HRIS Database Backup\n";
            echo "-- Database: {$dbName}\n";
            echo '-- Generated: '.now()->toDateTimeString()."\n\n";
            echo "SET FOREIGN_KEY_CHECKS=0;\n";
            echo "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";

            foreach ($tables as $table) {
                $createResult = DB::select("SHOW CREATE TABLE `{$table}`");
                $createSql = array_values((array) $createResult[0])[1];

                echo "-- Table: `{$table}`\n";
                echo "DROP TABLE IF EXISTS `{$table}`;\n";
                echo $createSql.";\n\n";

                $count = DB::table($table)->count();
                if ($count > 0) {
                    $firstRow = (array) DB::table($table)->first();
                    $cols = implode(', ', array_map(fn ($k) => "`{$k}`", array_keys($firstRow)));

                    DB::table($table)->orderByRaw('1')->chunk(500, function ($rows) use ($table, $cols) {
                        $valueLines = $rows->map(function ($row) {
                            $parts = array_map(function ($v) {
                                if (is_null($v)) {
                                    return 'NULL';
                                }

                                return "'".addslashes((string) $v)."'";
                            }, (array) $row);

                            return '('.implode(', ', $parts).')';
                        })->implode(",\n");

                        echo "INSERT INTO `{$table}` ({$cols}) VALUES\n{$valueLines};\n\n";
                    });
                }
            }

            echo "SET FOREIGN_KEY_CHECKS=1;\n";
        }, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    public function restoreDatabase(Request $request): RedirectResponse
    {
        $this->ensureHrManager($request);

        $request->validate([
            'backup_file' => 'required|file|max:102400|mimes:sql,txt',
            'restore_confirm' => 'required|accepted',
        ], [
            'restore_confirm.accepted' => 'You must tick the confirmation checkbox before restoring.',
        ]);

        $file = $request->file('backup_file');
        $sql = file_get_contents($file->getRealPath());

        // Split on statement-ending semicolons (handles both LF and CRLF line endings).
        // Each chunk may start with one or more SQL comment lines (-- ...) that our backup
        // generator emits before DROP TABLE statements. Strip those leading comments so the
        // actual SQL (e.g. DROP TABLE) is not silently discarded together with them.
        $statements = array_filter(
            array_map(function ($raw) {
                // Remove every leading "-- comment\n" line, then trim whitespace.
                $s = preg_replace('/\A(--[^\r\n]*\r?\n)+/', '', trim($raw));

                return trim($s);
            }, preg_split('/;\s*\r?\n/', $sql)),
            fn ($s) => $s !== ''
        );

        set_time_limit(300);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($statements as $stmt) {
                if (trim($stmt) === '') {
                    continue;
                }
                DB::unprepared($stmt);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        HRAuditTrail::create([
            'actor_user_id' => $request->user()->id,
            'module' => 'settings',
            'action' => 'database_restore',
            'target_type' => 'database',
            'target_id' => 0,
            'details' => ['file' => $file->getClientOriginalName()],
        ]);

        return redirect()->route('hr-manager.settings')
            ->with('success', 'Database restored successfully from "'.$file->getClientOriginalName().'".');
    }

    /**
     * @return Collection<int, Department>
     */
    private function departmentOptions(): Collection
    {
        return Department::query()->orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function recordsRows(Request $request): LengthAwarePaginator
    {
        $search = trim((string) $request->query('search', ''));
        $department = trim((string) $request->query('department', ''));
        $status = trim((string) $request->query('status', ''));

        $query = User::query()
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->select(
                'users.id',
                'users.EmpNo',
                'users.name',
                'users.designation',
                'users.Status',
                'users.updated_at',
                'departments.Dept_name'
            )
            ->whereRaw("LOWER(REPLACE(REPLACE(users.access_level, '-', ' '), '_', ' ')) = 'employee'");

        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('users.name', 'like', '%'.$search.'%')
                    ->orWhere('users.EmpNo', 'like', '%'.$search.'%')
                    ->orWhere('users.designation', 'like', '%'.$search.'%');
            });
        }

        if ($department !== '') {
            $query->where('users.Dept_id', $department);
        }

        if ($status !== '') {
            $query->where('users.Status', $status);
        }

        return $query
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->paginate(10)
            ->withQueryString()
            ->through(function ($row): array {
                return [
                    'id' => $row->id,
                    'emp_no' => $row->EmpNo,
                    'name' => $row->name,
                    'department' => $row->Dept_name,
                    'position' => $row->designation,
                    'employment_status' => $row->Status,
                    'history' => $this->formatDateTime($row->updated_at),
                ];
            });
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function leaveRows(Request $request, ?string $month = null): LengthAwarePaginator
    {
        $department = trim((string) $request->query('department', ''));
        $status = strtolower(trim((string) $request->query('status', 'pending')));

        $query = LeaveRequest::query()
            ->leftJoin('users', 'users.id', '=', 'leave_requests.user_id')
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->select(
                'leave_requests.id',
                'leave_requests.leave_type',
                'leave_requests.start_date',
                'leave_requests.end_date',
                'leave_requests.total_days',
                'leave_requests.status',
                'users.name as employee_name',
                'departments.Dept_name'
            );

        if ($department !== '') {
            $query->where('users.Dept_id', (int) $department);
        }

        if ($status !== '' && $status !== 'all') {
            $query->whereRaw('LOWER(leave_requests.status) = ?', [$status]);
        }

        if ($month !== null) {
            try {
                $start = Carbon::parse($month)->startOfMonth()->toDateString();
                $end = Carbon::parse($month)->endOfMonth()->toDateString();
                $query->where(function ($q) use ($start, $end): void {
                    $q->whereBetween('leave_requests.start_date', [$start, $end])
                        ->orWhereBetween('leave_requests.end_date', [$start, $end]);
                });
            } catch (\Throwable) {
            }
        }

        return $query
            ->orderByDesc('leave_requests.id')
            ->paginate(10)
            ->withQueryString()
            ->through(function ($row): array {
                return [
                    'id' => $row->id,
                    'employee_name' => $row->employee_name,
                    'department' => $row->Dept_name,
                    'leave_type' => $row->leave_type,
                    'period' => $row->start_date.' to '.$row->end_date,
                    'days' => $row->total_days,
                    'status' => strtolower((string) $row->status),
                ];
            });
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function leaveUsageChart(int $departmentId = 0, ?string $month = null): array
    {
        $query = LeaveRequest::query()
            ->leftJoin('users', 'users.id', '=', 'leave_requests.user_id')
            ->select('leave_requests.leave_type', DB::raw('COUNT(*) as total'))
            ->groupBy('leave_requests.leave_type')
            ->orderBy('leave_requests.leave_type');

        if ($departmentId > 0) {
            $query->where('users.Dept_id', $departmentId);
        }

        if ($month !== null) {
            try {
                $start = Carbon::parse($month)->startOfMonth()->toDateString();
                $end = Carbon::parse($month)->endOfMonth()->toDateString();
                $query->where(function ($q) use ($start, $end): void {
                    $q->whereBetween('leave_requests.start_date', [$start, $end])
                        ->orWhereBetween('leave_requests.end_date', [$start, $end]);
                });
            } catch (\Throwable) {
            }
        }

        $rows = $query->get();

        return [
            'labels' => $rows->pluck('leave_type')->map(fn ($type) => (string) $type)->all(),
            'values' => $rows->pluck('total')->map(fn ($value) => (int) $value)->all(),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function frontdeskRows(Request $request): LengthAwarePaginator
    {
        $department = trim((string) $request->query('department', ''));
        $status = trim((string) $request->query('status', ''));

        $query = DocumentRequest::query()
            ->leftJoin('users', 'users.EmpNo', '=', 'document_requests.EmpNo')
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->select(
                'document_requests.id',
                'document_requests.EmpNo',
                'document_requests.document_type',
                'document_requests.purpose',
                'document_requests.status',
                'document_requests.requested_on',
                'users.name as employee_name',
                'departments.Dept_name'
            );

        if ($department !== '') {
            $query->where('users.Dept_id', $department);
        }

        if ($status !== '' && strtolower($status) !== 'all') {
            $query->whereRaw('LOWER(document_requests.status) = ?', [strtolower($status)]);
        }

        return $query
            ->orderByDesc('document_requests.id')
            ->paginate(10)
            ->withQueryString()
            ->through(function ($row): array {
                return [
                    'id' => $row->id,
                    'emp_no' => $row->EmpNo,
                    'employee_name' => $row->employee_name,
                    'department' => $row->Dept_name,
                    'document_type' => $row->document_type,
                    'purpose' => $row->purpose,
                    'status' => strtolower((string) $row->status),
                    'requested_on' => $this->formatDateTime($row->requested_on),
                ];
            });
    }

    /**
     * @return array<int, string>
     */
    private function auditUsers(): array
    {
        return HRAuditTrail::query()
            ->leftJoin('users', 'users.id', '=', 'hr_audit_trails.actor_user_id')
            ->whereNotNull('users.name')
            ->orderBy('users.name')
            ->distinct()
            ->pluck('users.name')
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function auditRows(Request $request): array
    {
        $user = trim((string) $request->query('user', ''));
        $action = trim((string) $request->query('action', ''));
        $date = trim((string) $request->query('date', ''));

        $query = HRAuditTrail::query()
            ->leftJoin('users', 'users.id', '=', 'hr_audit_trails.actor_user_id')
            ->select(
                'hr_audit_trails.module',
                'hr_audit_trails.action',
                'hr_audit_trails.created_at',
                'users.name as actor_name',
                'users.access_level'
            )
            ->orderByDesc('hr_audit_trails.id');

        if ($user !== '') {
            $query->where('users.name', $user);
        }

        if ($action !== '') {
            $query->where('hr_audit_trails.action', $action);
        }

        if ($date !== '' && strtotime($date) !== false) {
            $query->whereDate('hr_audit_trails.created_at', $date);
        }

        return $query
            ->limit(200)
            ->get()
            ->map(function ($row): array {
                return [
                    'user' => (string) ($row->actor_name ?? 'System'),
                    'role' => ucwords($this->normalizeRole((string) ($row->access_level ?? 'hr manager'))),
                    'action' => strtoupper((string) $row->module).': '.strtoupper((string) $row->action),
                    'timestamp' => $this->formatDateTime($row->created_at),
                ];
            })
            ->all();
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @return array<string, int>
     */
    private function paginationPayload(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function storeAuditTrail(Request $request, string $module, string $action, string $targetType, int $targetId, array $details = []): void
    {
        HRAuditTrail::query()->create([
            'actor_user_id' => (int) $request->user()->id,
            'module' => $module,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'details' => $details,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function buildSummaryCards(): array
    {
        return Cache::remember('hr_summary_cards', now()->addMinutes(5), function () {
            return [
                'total_requests' => $this->totalRequests(),
                'pending' => $this->countRequestsByBucket('pending'),
                'approved' => $this->countRequestsByBucket('approved'),
                'completed' => $this->countRequestsByBucket('completed'),
            ];
        });
    }

    private function totalRequests(): int
    {
        $total = 0;

        foreach (['leave_requests', 'document_requests', 'eta', 'locators'] as $table) {
            if (Schema::hasTable($table)) {
                $total += (int) DB::table($table)->count();
            }
        }

        return $total;
    }

    private function countRequestsByBucket(string $bucket): int
    {
        $statusMap = [
            'pending' => ['pending', 'requested', 'for recommendation', 'pending recommendation', 'pending approval'],
            'approved' => ['approved', 'recommended'],
            'completed' => ['completed', 'released', 'final / archived'],
        ];

        $statuses = $statusMap[$bucket] ?? [];

        if ($statuses === []) {
            return 0;
        }

        $total = 0;

        if (Schema::hasTable('leave_requests')) {
            $total += (int) DB::table('leave_requests')
                ->where(function ($query) use ($statuses): void {
                    $query->whereIn(DB::raw('LOWER(status)'), $statuses)
                        ->orWhereIn(DB::raw('LOWER(detailed_status)'), $statuses);
                })
                ->count();
        }

        foreach (['document_requests', 'eta', 'locators'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $total += (int) DB::table($table)
                ->whereIn(DB::raw('LOWER(status)'), $statuses)
                ->count();
        }

        return $total;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildChartData(?int $departmentId): array
    {
        $employees = $this->employeeQuery($departmentId)->get([
            'id',
            'Dept_id',
            'Status',
            'employee_type',
            'created_at',
            'date_hired',
        ]);

        $workforcePerDepartment = $this->workforcePerDepartment($departmentId);
        $totalWorkforce = $this->countByKey($employees, 'employee_type', 'Unspecified');
        // Use employee_type for the employment status pie chart (counts by employee type)
        $employmentStatus = $this->countByKey($employees, 'employee_type', 'Unknown');

        $pdsByUser = $this->pdsByUserId($employees->pluck('id'));

        $genderCounts = [
            'Male' => 0,
            'Female' => 0,
            'Not Specified' => 0,
        ];

        $ageGroupCounts = [
            '18-25' => 0,
            '26-35' => 0,
            '36-45' => 0,
            '46-55' => 0,
            '56+' => 0,
            'Unknown' => 0,
        ];

        $serviceCounts = [
            '< 1 year' => 0,
            '1-3 years' => 0,
            '4-7 years' => 0,
            '8-12 years' => 0,
            '13+ years' => 0,
        ];

        foreach ($employees as $employee) {
            $pds = $pdsByUser->get($employee->id, []);

            $gender = $this->extractGender($pds);
            $genderCounts[$gender] = ($genderCounts[$gender] ?? 0) + 1;

            $ageBucket = $this->extractAgeBucket($pds);
            $ageGroupCounts[$ageBucket] = ($ageGroupCounts[$ageBucket] ?? 0) + 1;

            if (! empty($employee->date_hired)) {
                try {
                    $yearsOfService = Carbon::parse($employee->date_hired)->diffInYears(now());
                } catch (\Throwable $e) {
                    $yearsOfService = $this->extractYearsOfService($employee->created_at, $pds);
                }
            } else {
                $yearsOfService = $this->extractYearsOfService($employee->created_at, $pds);
            }
            $serviceBucket = $this->serviceBucket($yearsOfService);
            $serviceCounts[$serviceBucket] = ($serviceCounts[$serviceBucket] ?? 0) + 1;
        }

        return [
            'workforce_per_department' => $workforcePerDepartment,
            'total_workforce' => $this->barChartFromAssoc($totalWorkforce),
            'gender_distribution' => $this->pieChartFromAssoc($genderCounts),
            'employment_status' => $this->pieChartFromAssoc($employmentStatus),
            'age_group_distribution' => $this->barChartFromAssoc($ageGroupCounts),
            'length_of_service' => $this->barChartFromAssoc($serviceCounts),
        ];
    }

    /**
     * @return Builder<User>
     */
    private function employeeQuery(?int $departmentId)
    {
        $query = User::query()
            ->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = ?", ['employee']);

        if ($departmentId !== null) {
            $query->where('Dept_id', $departmentId);
        }

        return $query;
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function workforcePerDepartment(?int $departmentId): array
    {
        $departmentQuery = Department::query()
            ->select('departments.Dept_name')
            ->selectRaw('COUNT(users.id) as total')
            ->leftJoin('users', function ($join): void {
                $join->on('users.Dept_id', '=', 'departments.Dept_id')
                    ->whereRaw("LOWER(REPLACE(REPLACE(users.access_level, '-', ' '), '_', ' ')) = 'employee'");
            })
            ->groupBy('departments.Dept_id', 'departments.Dept_name')
            ->orderBy('departments.Dept_name');

        if ($departmentId !== null) {
            $departmentQuery->where('departments.Dept_id', $departmentId);
        }

        $rows = $departmentQuery->get();

        return [
            'labels' => $rows->pluck('Dept_name')->all(),
            'values' => $rows->pluck('total')->map(fn ($value) => (int) $value)->all(),
        ];
    }

    /**
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, array<string, mixed>>
     */
    private function pdsByUserId(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        return Pds::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->mapWithKeys(function (Pds $pds): array {
                return [$pds->user_id => $pds->getAllSectionData()];
            });
    }

    /**
     * @param  array<string, mixed>  $pds
     */
    private function extractGender(array $pds): string
    {
        $personal = (array) ($pds['pds-personal-info'] ?? []);
        $sex = strtolower(trim((string) ($personal['personal[sex]'] ?? '')));

        if ($sex === 'male') {
            return 'Male';
        }

        if ($sex === 'female') {
            return 'Female';
        }

        return 'Not Specified';
    }

    /**
     * @param  array<string, mixed>  $pds
     */
    private function extractAgeBucket(array $pds): string
    {
        $personal = (array) ($pds['pds-personal-info'] ?? []);
        $birthDate = trim((string) ($personal['personal[birth_date]'] ?? ''));

        if ($birthDate === '') {
            return 'Unknown';
        }

        try {
            $age = Carbon::parse($birthDate)->age;
        } catch (\Throwable) {
            return 'Unknown';
        }

        if ($age <= 25) {
            return '18-25';
        }

        if ($age <= 35) {
            return '26-35';
        }

        if ($age <= 45) {
            return '36-45';
        }

        if ($age <= 55) {
            return '46-55';
        }

        return '56+';
    }

    /**
     * @param  array<string, mixed>  $pds
     */
    private function extractYearsOfService(mixed $createdAt, array $pds): int
    {
        $workSection = (array) ($pds['pds-work-experience'] ?? []);
        $earliestWorkDate = null;

        foreach ($workSection as $key => $value) {
            if (! preg_match('/^work\[\d+\]\[from\]$/', (string) $key)) {
                continue;
            }

            $dateValue = trim((string) $value);
            if ($dateValue === '') {
                continue;
            }

            try {
                $parsed = Carbon::parse($dateValue);
            } catch (\Throwable) {
                continue;
            }

            if ($earliestWorkDate === null || $parsed->lt($earliestWorkDate)) {
                $earliestWorkDate = $parsed;
            }
        }

        $startDate = $earliestWorkDate;

        if ($startDate === null && $createdAt !== null) {
            try {
                $startDate = $createdAt instanceof Carbon ? $createdAt : Carbon::parse((string) $createdAt);
            } catch (\Throwable) {
                $startDate = null;
            }
        }

        if ($startDate === null) {
            return 0;
        }

        return max(0, $startDate->diffInYears(now()));
    }

    private function serviceBucket(int $yearsOfService): string
    {
        if ($yearsOfService < 1) {
            return '< 1 year';
        }

        if ($yearsOfService <= 3) {
            return '1-3 years';
        }

        if ($yearsOfService <= 7) {
            return '4-7 years';
        }

        if ($yearsOfService <= 12) {
            return '8-12 years';
        }

        return '13+ years';
    }

    /**
     * @param  Collection<int, User>  $employees
     * @return array<string, int>
     */
    private function countByKey(Collection $employees, string $key, string $fallback): array
    {
        return $employees
            ->map(function (User $employee) use ($key, $fallback): string {
                $value = trim((string) ($employee->{$key} ?? ''));

                return $value !== '' ? $value : $fallback;
            })
            ->countBy()
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, int>  $assoc
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function barChartFromAssoc(array $assoc): array
    {
        return [
            'labels' => array_keys($assoc),
            'values' => array_values($assoc),
        ];
    }

    /**
     * @param  array<string, int>  $assoc
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function pieChartFromAssoc(array $assoc): array
    {
        return [
            'labels' => array_keys($assoc),
            'values' => array_values($assoc),
        ];
    }

    // ── Enhancement 6: Holiday-Leave Overlap Alerts ───────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildHolidayLeaveAlerts(): array
    {
        if (! Schema::hasTable('holidays') || ! Schema::hasTable('leave_requests')) {
            return [];
        }

        $holidays = Holiday::query()
            ->whereBetween('holiday_date', [today(), today()->addDays(30)])
            ->orderBy('holiday_date')
            ->get(['title', 'holiday_date', 'type']);

        $alerts = [];
        foreach ($holidays as $holiday) {
            $date = $holiday->holiday_date->toDateString();
            $count = (int) DB::table('leave_requests')
                ->whereRaw('LOWER(status) = ?', ['pending'])
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->count();

            if ($count > 0) {
                $alerts[] = [
                    'title' => $holiday->title,
                    'date' => $date,
                    'type' => $holiday->type,
                    'count' => $count,
                ];
            }
        }

        return $alerts;
    }

    // ── Enhancement 1: Actionable Alerts ─────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildAlerts(): array
    {
        $staleDays = 3;

        $staleLeave = Schema::hasTable('leave_requests')
            ? (int) DB::table('leave_requests')
                ->whereRaw('LOWER(status) = ?', ['pending'])
                ->where('created_at', '<', now()->subDays($staleDays))
                ->count()
            : 0;

        $openPayroll = null;
        if (Schema::hasTable('payroll_runs')) {
            $run = DB::table('payroll_runs')
                ->where('status', 'draft')
                ->whereNull('locked_at')
                ->orderByDesc('id')
                ->first(['id', 'period']);
            if ($run) {
                $openPayroll = ['period' => $run->period, 'run_id' => $run->id];
            }
        }

        $unresolvedExceptions = Schema::hasTable('payroll_exceptions')
            ? (int) DB::table('payroll_exceptions')->where('resolved_flag', false)->count()
            : 0;

        $upcomingHolidays = [];
        if (Schema::hasTable('holidays')) {
            $upcomingHolidays = DB::table('holidays')
                ->whereBetween('holiday_date', [today(), today()->addDays(14)])
                ->orderBy('holiday_date')
                ->get(['title', 'holiday_date', 'type'])
                ->map(fn ($h) => [
                    'title' => $h->title,
                    'date' => $h->holiday_date,
                    'type' => $h->type,
                    'days_away' => (int) today()->diffInDays($h->holiday_date),
                ])
                ->all();
        }

        $staleTravelOrders = Schema::hasTable('travel_orders')
            ? (int) DB::table('travel_orders')
                ->whereRaw('LOWER(status) = ?', ['pending'])
                ->where('created_at', '<', now()->subDays($staleDays))
                ->count()
            : 0;

        $staleDocuments = Schema::hasTable('document_requests')
            ? (int) DB::table('document_requests')
                ->whereRaw('LOWER(status) = ?', ['requested'])
                ->where('requested_on', '<', now()->subDays($staleDays))
                ->count()
            : 0;

        return [
            'stale_leave' => ['count' => $staleLeave, 'days' => $staleDays],
            'open_payroll' => $openPayroll,
            'unresolved_exceptions' => $unresolvedExceptions,
            'upcoming_holidays' => $upcomingHolidays,
            'stale_travel' => $staleTravelOrders,
            'stale_documents' => $staleDocuments,
            'total_alerts' => $staleLeave + $staleTravelOrders + $staleDocuments + $unresolvedExceptions + ($openPayroll ? 1 : 0),
        ];
    }

    // ── Enhancement 3: Leave Analytics ────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildLeaveAnalytics(?int $departmentId, string $month = ''): array
    {
        $types = ['VL', 'SL', 'WLNS', 'SPL', 'CTO', 'SP'];
        $balanceSummary = [];

        if (Schema::hasTable('leave_balances')) {
            $query = DB::table('leave_balances')
                ->leftJoin('users', 'users.id', '=', 'leave_balances.user_id');

            if ($departmentId !== null) {
                $query->where('users.Dept_id', $departmentId);
            }

            $rows = $query->select('leave_balances.*')->get();

            // Consumption reference: use selected month end, fallback to now
            $now = ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month))
                ? Carbon::parse($month)->endOfMonth()
                : now();
            $prevMonth = $now->copy()->subMonth();

            $consumptionQuery = fn (int $year, int $month) => DB::table('leave_dates')
                ->join('leave_requests', 'leave_requests.id', '=', 'leave_dates.leave_request_id')
                ->when($departmentId !== null, function ($q) use ($departmentId) {
                    $q->join('users', 'users.id', '=', 'leave_requests.user_id')
                        ->where('users.Dept_id', $departmentId);
                })
                ->whereYear('leave_dates.leave_date', $year)
                ->whereMonth('leave_dates.leave_date', $month)
                ->where('leave_dates.is_cancelled', false)
                ->select('leave_requests.leave_type', DB::raw('COUNT(*) as cnt'))
                ->groupBy('leave_requests.leave_type')
                ->get()
                ->pluck('cnt', 'leave_type');

            $thisMonthConsumption = Schema::hasTable('leave_dates')
                ? $consumptionQuery($now->year, $now->month)
                : collect();
            $lastMonthConsumption = Schema::hasTable('leave_dates')
                ? $consumptionQuery($prevMonth->year, $prevMonth->month)
                : collect();

            $typeMap = ['VL' => 'Vacation Leave', 'SL' => 'Sick Leave', 'WLNS' => 'Wellness', 'SPL' => 'Solo Parent', 'CTO' => 'CTO', 'SP' => 'Special Privilege'];

            foreach ($types as $type) {
                $col = $rows->pluck($type)->filter(fn ($v) => $v !== null);
                $avg = $col->count() > 0 ? round($col->avg(), 1) : 0;
                $lowCount = $col->filter(fn ($v) => (float) $v < 2)->count();
                $zeroCount = $col->filter(fn ($v) => (float) $v <= 0)->count();

                $thisMonth = (int) ($thisMonthConsumption[$typeMap[$type] ?? $type] ?? 0);
                $lastMonth = (int) ($lastMonthConsumption[$typeMap[$type] ?? $type] ?? 0);
                $trend = $thisMonth > $lastMonth ? 'down' : ($thisMonth < $lastMonth ? 'up' : 'stable');

                $balanceSummary[$type] = [
                    'avg' => $avg,
                    'low_count' => $lowCount,
                    'zero_count' => $zeroCount,
                    'trend' => $trend,
                ];
            }
        }

        // Critical employees: VL < 2 OR SL < 2
        $criticalEmployees = [];
        if (Schema::hasTable('leave_balances')) {
            $query = DB::table('leave_balances')
                ->leftJoin('users', 'users.id', '=', 'leave_balances.user_id')
                ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
                ->select(
                    'users.id',
                    'users.name',
                    'departments.Dept_name',
                    'leave_balances.VL',
                    'leave_balances.SL'
                )
                ->where(function ($q) {
                    $q->where('leave_balances.VL', '<', 2)
                        ->orWhere('leave_balances.SL', '<', 2);
                });

            if ($departmentId !== null) {
                $query->where('users.Dept_id', $departmentId);
            }

            $criticalEmployees = $query->orderBy('users.name')->limit(50)->get()
                ->map(fn ($r) => [
                    'user_id' => $r->id,
                    'name' => $r->name,
                    'department' => $r->Dept_name,
                    'vl' => round((float) $r->VL, 1),
                    'sl' => round((float) $r->SL, 1),
                ])
                ->all();
        }

        // 6-month org-wide submitted vs approved trend anchored to the selected month
        $refDate = ($month !== '' && preg_match('/^\d{4}-\d{2}$/', $month))
            ? Carbon::parse($month)->endOfMonth()
            : now();
        $sixMonthsAgo = $refDate->copy()->subMonths(5)->startOfMonth();
        $trendLabels = [];
        $trendSubmitted = [];
        $trendApproved = [];

        $submittedTrend = LeaveRequest::selectRaw('MONTH(created_at) as m, YEAR(created_at) as y, COUNT(*) as cnt')
            ->when($departmentId !== null, function ($q) use ($departmentId) {
                $q->leftJoin('users', 'users.id', '=', 'leave_requests.user_id')
                    ->where('users.Dept_id', $departmentId);
            })
            ->where('created_at', '>=', $sixMonthsAgo)
            ->groupByRaw('YEAR(created_at), MONTH(created_at)')
            ->get()
            ->keyBy(fn ($r) => $r->y.'-'.$r->m);

        $approvedTrend = LeaveRequest::selectRaw('MONTH(updated_at) as m, YEAR(updated_at) as y, COUNT(*) as cnt')
            ->when($departmentId !== null, function ($q) use ($departmentId) {
                $q->leftJoin('users', 'users.id', '=', 'leave_requests.user_id')
                    ->where('users.Dept_id', $departmentId);
            })
            ->where('status', 'approved')
            ->where('updated_at', '>=', $sixMonthsAgo)
            ->groupByRaw('YEAR(updated_at), MONTH(updated_at)')
            ->get()
            ->keyBy(fn ($r) => $r->y.'-'.$r->m);

        for ($i = 5; $i >= 0; $i--) {
            $dt = $refDate->copy()->subMonths($i);
            $trendLabels[] = $dt->format('M');
            $key = $dt->year.'-'.$dt->month;
            $trendSubmitted[] = (int) ($submittedTrend->get($key)?->cnt ?? 0);
            $trendApproved[] = (int) ($approvedTrend->get($key)?->cnt ?? 0);
        }

        return [
            'balance_summary' => $balanceSummary,
            'critical_employees' => $criticalEmployees,
            'trend' => [
                'labels' => $trendLabels,
                'submitted' => $trendSubmitted,
                'approved' => $trendApproved,
            ],
        ];
    }

    // ── Enhancement 5: Workforce Planning ─────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildWorkforcePlanning(): array
    {
        $milestoneYears = [10, 15, 20, 25, 30];
        $milestones = [];

        $activeEmployees = User::query()
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->select('users.id', 'users.name', 'users.date_hired', 'departments.Dept_name')
            ->where('users.Status', 'Active')
            ->whereNotNull('users.date_hired')
            ->get();

        foreach ($activeEmployees as $emp) {
            try {
                $hired = Carbon::parse($emp->date_hired);
            } catch (\Throwable) {
                continue;
            }

            foreach ($milestoneYears as $milestone) {
                $anniversary = $hired->copy()->addYears($milestone);
                $daysUntil = (int) now()->diffInDays($anniversary, false);

                if ($daysUntil >= 0 && $daysUntil <= 90) {
                    $milestones[] = [
                        'name' => $emp->name,
                        'department' => $emp->Dept_name ?? 'N/A',
                        'years' => $milestone,
                        'anniversary' => $anniversary->toDateString(),
                        'days_away' => $daysUntil,
                    ];
                }
            }
        }

        usort($milestones, fn ($a, $b) => $a['days_away'] <=> $b['days_away']);

        $now30Start = now()->subDays(30)->startOfDay();
        $prev30Start = now()->subDays(60)->startOfDay();
        $prev30End = now()->subDays(30)->startOfDay();

        $hiredLast30 = (int) User::query()
            ->where('date_hired', '>=', $now30Start)
            ->count();

        $hiredPrev30 = (int) User::query()
            ->where('date_hired', '>=', $prev30Start)
            ->where('date_hired', '<', $prev30End)
            ->count();

        $separatedLast30 = (int) User::query()
            ->whereIn('Status', ['Separated', 'Inactive'])
            ->where('updated_at', '>=', $now30Start)
            ->count();

        $separatedPrev30 = (int) User::query()
            ->whereIn('Status', ['Separated', 'Inactive'])
            ->where('updated_at', '>=', $prev30Start)
            ->where('updated_at', '<', $prev30End)
            ->count();

        $hiredPctChange = $hiredPrev30 > 0
            ? round((($hiredLast30 - $hiredPrev30) / $hiredPrev30) * 100, 1)
            : ($hiredLast30 > 0 ? 100.0 : 0.0);

        $separatedPctChange = $separatedPrev30 > 0
            ? round((($separatedLast30 - $separatedPrev30) / $separatedPrev30) * 100, 1)
            : ($separatedLast30 > 0 ? 100.0 : 0.0);

        // 12-month hiring trend
        $trendLabels = [];
        $trendValues = [];
        for ($i = 11; $i >= 0; $i--) {
            $dt = now()->subMonths($i);
            $trendLabels[] = $dt->format('M');
            $count = (int) User::query()
                ->whereYear('date_hired', $dt->year)
                ->whereMonth('date_hired', $dt->month)
                ->count();
            $trendValues[] = $count;
        }

        return [
            'milestones' => $milestones,
            'headcount' => [
                'hired_30d' => $hiredLast30,
                'hired_pct_change' => $hiredPctChange,
                'separated_30d' => $separatedLast30,
                'separated_pct_change' => $separatedPctChange,
                'net' => $hiredLast30 - $separatedLast30,
            ],
            'trend' => [
                'labels' => $trendLabels,
                'values' => $trendValues,
            ],
        ];
    }

    // ── Enhancement 2: Attendance Overview ────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildAttendanceOverview(string $month, ?int $departmentId): array
    {
        if (! Schema::hasTable('dtrs')) {
            return ['summary' => [], 'daily_absences' => [], 'dept_late' => [], 'top_employees' => []];
        }

        [$year, $mon] = explode('-', $month);
        $year = (int) $year;
        $mon = (int) $mon;

        $baseQuery = fn () => DB::table('dtrs')
            ->join('users', 'users.id', '=', 'dtrs.employee_id')
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->whereYear('dtrs.date', $year)
            ->whereMonth('dtrs.date', $mon)
            ->when($departmentId !== null, fn ($q) => $q->where('users.Dept_id', $departmentId));

        $summary = $baseQuery()->selectRaw('
            SUM(dtrs.is_absent) as total_absences,
            SUM(dtrs.late_minutes) as total_late,
            SUM(dtrs.undertime_minutes) as total_undertime
        ')->first();

        $totalDays = Carbon::createFromDate($year, $mon, 1)->daysInMonth;
        $daysWithAbsences = (int) $baseQuery()
            ->where('dtrs.is_absent', true)
            ->distinct()
            ->count(DB::raw('DATE(dtrs.date)'));

        $summaryCards = [
            'total_absences' => (int) ($summary->total_absences ?? 0),
            'total_late_minutes' => (int) ($summary->total_late ?? 0),
            'total_undertime_minutes' => (int) ($summary->total_undertime ?? 0),
            'clean_days' => $totalDays - $daysWithAbsences,
        ];

        // Daily absent count (last 30 days of the selected month)
        $dailyAbsences = $baseQuery()
            ->selectRaw('DATE(dtrs.date) as day, SUM(dtrs.is_absent) as absent_count')
            ->groupBy(DB::raw('DATE(dtrs.date)'))
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => ['day' => $r->day, 'count' => (int) $r->absent_count])
            ->all();

        // Late minutes by department
        $deptLate = $baseQuery()
            ->selectRaw('departments.Dept_name, SUM(dtrs.late_minutes) as total_late')
            ->groupBy('departments.Dept_id', 'departments.Dept_name')
            ->orderByDesc('total_late')
            ->get()
            ->map(fn ($r) => ['department' => $r->Dept_name ?? 'Unknown', 'late_minutes' => (int) $r->total_late])
            ->all();

        // Top 15 employees by total tardiness
        $hasSource = Schema::hasColumn('dtrs', 'source');
        $topEmployees = $baseQuery()
            ->selectRaw('
                users.id,
                users.name,
                departments.Dept_name,
                SUM(dtrs.late_minutes) as late_min,
                SUM(dtrs.undertime_minutes) as undertime_min,
                SUM(dtrs.is_absent) as absences'
                .($hasSource ? ', GROUP_CONCAT(DISTINCT dtrs.source) as sources' : ''))
            ->groupBy('users.id', 'users.name', 'departments.Dept_name')
            ->orderByDesc(DB::raw('SUM(dtrs.late_minutes) + SUM(dtrs.undertime_minutes) + SUM(dtrs.is_absent) * 60'))
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'user_id' => $r->id,
                'name' => $r->name,
                'department' => $r->Dept_name ?? 'Unknown',
                'late_minutes' => (int) $r->late_min,
                'undertime_minutes' => (int) $r->undertime_min,
                'absences' => (int) $r->absences,
                'source' => $hasSource ? ($r->sources ?? 'N/A') : 'N/A',
            ])
            ->all();

        return [
            'summary' => $summaryCards,
            'daily_absences' => $dailyAbsences,
            'dept_late' => $deptLate,
            'top_employees' => $topEmployees,
        ];
    }

    // ── Enhancement 4: Payroll Overview ───────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildPayrollOverview(): array
    {
        if (! Schema::hasTable('payroll_runs')) {
            return ['runs' => [], 'exceptions' => [], 'dept_net_pay' => ['labels' => [], 'values' => []]];
        }

        $runs = PayrollRun::query()
            ->withCount(['exceptions as unresolved_count' => fn ($q) => $q->where('resolved_flag', false)])
            ->withCount('details as employee_count')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'period' => $r->period,
                'period_start' => $r->period_start?->toDateString(),
                'period_end' => $r->period_end?->toDateString(),
                'status' => $r->status,
                'locked_at' => $r->locked_at?->toDateTimeString(),
                'employee_count' => $r->employee_count,
                'unresolved_exceptions' => $r->unresolved_count,
            ])
            ->all();

        $exceptions = [];
        if (Schema::hasTable('payroll_exceptions')) {
            $latestRunId = PayrollRun::query()->orderByDesc('id')->value('id');
            if ($latestRunId) {
                $exceptions = PayrollException::query()
                    ->with('payrollRun:id,period')
                    ->where('payroll_run_id', $latestRunId)
                    ->where('resolved_flag', false)
                    ->orderByDesc('id')
                    ->limit(50)
                    ->get()
                    ->map(fn ($e) => [
                        'id' => $e->id,
                        'period' => $e->payrollRun?->period ?? 'N/A',
                        'type' => $e->type,
                        'description' => $e->description,
                    ])
                    ->all();
            }
        }

        // Net pay by department for the latest locked run
        $deptNetPay = ['labels' => [], 'values' => []];
        if (Schema::hasTable('payroll_details')) {
            $lockedRun = PayrollRun::query()
                ->whereNotNull('locked_at')
                ->orderByDesc('id')
                ->first(['id']);

            if ($lockedRun) {
                $rows = DB::table('payroll_details')
                    ->join('users', 'users.id', '=', 'payroll_details.employee_id')
                    ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
                    ->where('payroll_details.payroll_run_id', $lockedRun->id)
                    ->selectRaw('departments.Dept_name, SUM(payroll_details.net_pay) as total_net')
                    ->groupBy('departments.Dept_id', 'departments.Dept_name')
                    ->orderByDesc('total_net')
                    ->get();

                $deptNetPay = [
                    'labels' => $rows->pluck('Dept_name')->map(fn ($n) => $n ?? 'Unknown')->all(),
                    'values' => $rows->pluck('total_net')->map(fn ($v) => round((float) $v, 2))->all(),
                ];
            }
        }

        return [
            'runs' => $runs,
            'exceptions' => $exceptions,
            'dept_net_pay' => $deptNetPay,
        ];
    }

    private function ensureHrManager(Request $request): void
    {
        $normalizedRole = $this->normalizeRole((string) ($request->user()->access_level ?? ''));
        abort_unless($normalizedRole === 'hr manager', 403, 'Only HR Manager can access this page.');
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return now()->toDateTimeString();
        }

        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return now()->toDateTimeString();
        }
    }
}
