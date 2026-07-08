<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DocumentRequest;
use App\Models\HRAuditTrail;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\PayrollException;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use App\Services\DepartmentService;
use App\Services\EmployeeAssignmentService;
use App\Services\HRDashboardService;
use App\Services\LeaveCardExportService;
use App\Services\LeaveRequestService;
use App\Support\HrisConstants;
use App\Support\RoleNormalizer;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HRManagerController extends Controller
{
    public function __construct(
        private HRDashboardService $dashboardService,
        private EmployeeAssignmentService $employeeAssignmentService,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureHrManager($request);

        $departmentId = $request->integer('department');
        $employeeType = trim((string) $request->query('employee_type', ''));

        return view('hr-manager.dashboard', [
            'departments' => $this->departmentOptions(),
            'employeeTypes' => HrisConstants::EMPLOYEE_TYPES,
            'workforceCards' => $this->dashboardService->buildWorkforceCards(),
            'chartDataUrl' => route('hr-manager.chart-data'),
            'exportUrl' => route('export-jobs.create'),
            'initialChartData' => $this->dashboardService->buildChartData(
                $departmentId > 0 ? $departmentId : null,
                $employeeType !== '' ? $employeeType : null
            ),
        ]);
    }

    public function getChartData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $departmentId = $request->integer('department');
        $deptKey = $departmentId > 0 ? $departmentId : 'all';

        $employeeType = trim((string) $request->query('employee_type', ''));
        $typeKey = $employeeType !== '' ? $employeeType : 'all';

        $data = Cache::remember("hr_chart_data_{$deptKey}_{$typeKey}", now()->addMinutes(10), function () use ($departmentId, $employeeType) {
            return $this->dashboardService->buildChartData(
                $departmentId > 0 ? $departmentId : null,
                $employeeType !== '' ? $employeeType : null
            );
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
        $employeeType = trim((string) $request->query('employee_type', ''));
        $ageGroup = trim((string) $request->query('age_group', ''));
        $lengthOfService = trim((string) $request->query('length_of_service', ''));
        $awardRecipients = trim((string) $request->query('award_recipients', ''));
        $sixtyPlus = trim((string) $request->query('sixty_plus', ''));

        $query = User::query()
            ->leftJoin('departments', 'departments.Dept_id', '=', 'users.Dept_id')
            ->select(
                'users.id',
                'users.EmpNo',
                'users.last_name',
                'users.first_name',
                'users.middle_name',
                'users.name_extension',
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

        if ($employeeType !== '') {
            if (strcasecmp($employeeType, 'Unspecified') === 0) {
                $query->where(fn ($q) => $q->whereNull('users.employee_type')->orWhere('users.employee_type', ''));
            } else {
                $query->where('users.employee_type', $employeeType);
            }
        }

        $rows = $query->orderBy('users.last_name')->orderBy('users.first_name')->get();

        $userIds = $rows->pluck('id');
        $pdsMap = $this->dashboardService->pdsByUserId($userIds);

        $employees = [];

        foreach ($rows as $row) {
            $pds = $pdsMap->get($row->id, []);
            $genderVal = $this->dashboardService->extractGender($pds);

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
                    $yearsOfService = $this->dashboardService->extractYearsOfService($row->created_at, $pds);
                }
            } else {
                $yearsOfService = $this->dashboardService->extractYearsOfService($row->created_at, $pds);
            }
            $serviceBucket = $this->dashboardService->serviceBucket($yearsOfService);

            $ageBucket = $this->dashboardService->extractAgeBucket($pds);

            $fullName = trim(
                ($row->last_name ?? '').', '
                .($row->first_name ?? '')
                .($row->middle_name ? ' '.mb_substr(trim($row->middle_name), 0, 1).'.' : '')
                .($row->name_extension ? ' '.$row->name_extension : '')
            );

            $employees[] = [
                'emp_no' => $row->EmpNo,
                'name' => $fullName,
                'position' => $row->designation,
                'gender' => $genderVal,
                'age' => $age,
                'age_bucket' => $ageBucket,
                'employee_type' => $row->employee_type ?? null,
                'status' => $row->Status,
                'date_hired' => $row->date_hired ? Carbon::parse($row->date_hired)->toDateString() : null,
                'length_of_service' => $serviceBucket,
                'years_of_service_int' => $yearsOfService,
                'department' => $row->Dept_name,
            ];
        }

        $awardMilestones = [10, 15, 20, 25, 30, 35, 40];
        $currentYear = now()->year;

        // Apply client-side filters (age group, length of service, gender, award recipients, 60+)
        $filtered = collect($employees)->filter(function ($emp) use ($gender, $ageGroup, $lengthOfService, $awardRecipients, $sixtyPlus, $awardMilestones, $currentYear) {
            if ($gender !== '' && strcasecmp($emp['gender'], $gender) !== 0) {
                return false;
            }

            if ($ageGroup !== '' && $emp['age_bucket'] !== $ageGroup) {
                return false;
            }

            if ($lengthOfService !== '' && $emp['length_of_service'] !== $lengthOfService) {
                return false;
            }

            if ($awardRecipients !== '') {
                if (empty($emp['date_hired'])) {
                    return false;
                }
                $hireYear = (int) substr((string) $emp['date_hired'], 0, 4);
                if (! in_array($currentYear - $hireYear, $awardMilestones, true)) {
                    return false;
                }
            }

            if ($sixtyPlus !== '' && ($emp['age'] === null || $emp['age'] < 60)) {
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

    public function recordsUpdate(Request $request, User $user): JsonResponse
    {
        $this->ensureHrManager($request);

        $validated = $request->validate([
            'Status' => ['nullable', Rule::in(User::STATUSES)],
        ]);

        $previousStatus = $user->Status;
        $newStatus = $validated['Status'] ?? null;

        $user->forceFill(['Status' => $newStatus])->save();

        if ($newStatus !== $previousStatus) {
            $this->storeAuditTrail(
                $request,
                'records',
                'status_changed',
                User::class,
                (int) $user->id,
                [
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'employee_name' => $user->name,
                    'employee_no' => $user->EmpNo,
                ]
            );

            if ($user->isInactive() || $user->isSeparated()) {
                $this->employeeAssignmentService->endActiveAssignmentForStatusChange($user->id, (string) $newStatus);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Employment status updated.',
            'employment_status' => $newStatus,
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
            'holidayAlerts' => $this->dashboardService->buildHolidayLeaveAlerts(),
            'selectedMonth' => $month,
            'criticalBalances' => app(LeaveRequestService::class)->criticalBalances(),
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

    public function audit(Request $request): View
    {
        $this->ensureHrManager($request);

        $page = $this->auditRows($request);

        return view('hr-manager.audit', [
            'auditUsers' => $this->auditUsers(),
            'logs' => $page->items(),
            'auditPagination' => $this->paginationPayload($page),
            'auditDataUrl' => route('hr-manager.audit.data'),
        ]);
    }

    public function auditData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $page = $this->auditRows($request);

        return response()->json([
            'rows' => $page->items(),
            'pagination' => $this->paginationPayload($page),
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

        $data = Cache::remember('hr_alerts', now()->addMinutes(5), fn () => $this->dashboardService->buildAlerts());

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
            fn () => $this->dashboardService->buildLeaveAnalytics($departmentId > 0 ? $departmentId : null, $month)
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

        $data = Cache::remember('hr_workforce_planning', now()->addMinutes(15), fn () => $this->dashboardService->buildWorkforcePlanning());

        return response()->json($data);
    }

    public function serviceMilestones(Request $request): View
    {
        $this->ensureHrManager($request);

        return view('hr-manager.service-milestones', [
            'planningDataUrl' => route('hr-manager.records.planning-data'),
        ]);
    }

    // ── Enhancement 2: Attendance Overview ────────────────────────────────

    public function attendanceOverview(Request $request): View
    {
        $this->ensureHrManager($request);

        return view('hr-manager.attendance-overview', [
            'departments' => $this->departmentOptions(),
            'attendanceDataUrl' => route('hr-manager.attendance.overview.data'),
            'attendanceNotifyUrl' => route('hr-manager.attendance.notify-dept-head'),
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
            fn () => $this->dashboardService->buildAttendanceOverview($month, $departmentId > 0 ? $departmentId : null)
        );

        return response()->json($data);
    }

    public function attendanceNotifyDeptHead(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $payload = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'tardiness_count' => ['nullable', 'integer', 'min:0'],
            'undertime_count' => ['nullable', 'integer', 'min:0'],
        ]);

        $employee = User::findOrFail($payload['user_id']);
        $deptService = app(DepartmentService::class);
        $dept = $deptService->resolveDepartmentForUser($employee);

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

        try {
            $deptHead->notify(new HrisTransactionNotification(
                'Attendance Alert',
                'Action Required',
                [
                    'Employee' => $employee->name,
                    'Department' => $dept?->Dept_name ?? 'N/A',
                    'Tardiness Days' => ($payload['tardiness_count'] ?? '-').' day(s)',
                    'Undertime Days' => ($payload['undertime_count'] ?? '-').' day(s)',
                ],
                $request->user()->name,
                'This employee has exceeded the tardiness/undertime threshold this month. Please take appropriate action.'
            ));
        } catch (\Throwable) {
            return response()->json(['success' => false, 'message' => 'Notification could not be sent.'], 500);
        }

        $this->storeAuditTrail($request, 'attendance', 'notify_dept_head', User::class, (int) $employee->id, [
            'employee' => $employee->name,
            'dept_head' => $deptHead->name,
            'tardiness_count' => $payload['tardiness_count'] ?? null,
            'undertime_count' => $payload['undertime_count'] ?? null,
        ]);

        return response()->json(['success' => true, 'message' => 'Department head notified successfully.']);
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

        $data = Cache::remember('hr_payroll_overview', now()->addMinutes(5), fn () => $this->dashboardService->buildPayrollOverview());

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
        $departments = Department::orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);

        return view('hr-manager.settings', [
            'settings' => $settings,
            'departments' => $departments,
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
            // Auto-import
            'auto_import_enabled' => 'nullable|boolean',
            'auto_import_interval_minutes' => 'nullable|integer|min:1|max:1440',
            'auto_import_dept_id' => 'nullable|integer|exists:departments,Dept_id',
            'auto_import_page_size' => 'nullable|integer|min:10|max:5000',
        ]);

        $validated['records_enabled'] = $request->boolean('records_enabled');
        $validated['leave_enabled'] = $request->boolean('leave_enabled');
        $validated['frontdesk_enabled'] = $request->boolean('frontdesk_enabled');
        $validated['payroll_enabled'] = $request->boolean('payroll_enabled');
        $validated['attendance_enabled'] = $request->boolean('attendance_enabled');
        $validated['eta_enabled'] = $request->boolean('eta_enabled');
        $validated['excel_protection_enabled'] = $request->boolean('excel_protection_enabled');
        $validated['auto_import_enabled'] = $request->boolean('auto_import_enabled');

        // Ensure email template fields are never null (database columns are NOT NULL)
        $validated['email_template_subject'] = $validated['email_template_subject'] ?? '';
        $validated['email_template_body'] = $validated['email_template_body'] ?? '';

        // Never overwrite the stored password with blank - only update when a new value is provided
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

        if ($request->boolean('auto_import_enabled')) {
            Cache::forget('attendance_auto_import_last_run');
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
     * @return LengthAwarePaginator<int, array<string, string>>
     */
    private function auditRows(Request $request): LengthAwarePaginator
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
            ->paginate(25)
            ->through(fn ($row): array => [
                'user' => (string) ($row->actor_name ?? 'System'),
                'role' => ucwords($this->normalizeRole((string) ($row->access_level ?? 'hr manager'))),
                'action' => strtoupper((string) $row->module).': '.strtoupper((string) $row->action),
                'timestamp' => $this->formatDateTime($row->created_at),
            ]);
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

    private function ensureHrManager(Request $request): void
    {
        $normalizedRole = $this->normalizeRole((string) ($request->user()->access_level ?? ''));
        abort_unless($normalizedRole === 'hr manager', 403, 'Only HR Manager can access this page.');
    }

    private function normalizeRole(string $role): string
    {
        return RoleNormalizer::normalize($role);
    }

    public function downloadLeaveCard(Request $request): StreamedResponse
    {
        $this->ensureHrManager($request);

        $userId = (int) $request->input('user_id');
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        if (! $userId || ! $year || $month < 1 || $month > 12) {
            abort(422, 'Invalid parameters: user_id, year, and month (1–12) are required.');
        }

        $user = User::findOrFail($userId);

        return app(LeaveCardExportService::class)->generateExcelResponse($user, $year, $month);
    }

    public function leaveLedger(Request $request): View
    {
        $this->ensureHrManager($request);

        $employees = User::query()
            ->whereHas('leaveBalance')
            ->where('Status', 'Active')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'EmpNo', 'Dept_id']);

        $departments = Department::pluck('Dept_name', 'Dept_id')->toArray();
        $currentYear = now()->year;
        $years = range($currentYear, max(2020, $currentYear - 6));

        return view('leave-manager.leave-ledger', [
            'employees' => $employees,
            'departments' => $departments,
            'years' => $years,
            'currentYear' => $currentYear,
        ]);
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
