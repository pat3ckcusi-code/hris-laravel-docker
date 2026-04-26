<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DocumentRequest;
use App\Models\HRAuditTrail;
use App\Models\LeaveRequest;
use App\Models\Pds;
use App\Models\Setting;
use App\Models\User;
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
            if (!empty($row->date_hired)) {
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

        $leavePage = $this->leaveRows($request);

        return view('hr-manager.leave', [
            'departments' => $this->departmentOptions(),
            'requests' => $leavePage->items(),
            'leavePagination' => $this->paginationPayload($leavePage),
            'leaveDataUrl' => route('hr-manager.leave.data'),
            'leaveActionBaseUrl' => route('hr-manager.leave.action', ['leaveRequest' => '__ID__']),
            'leaveFilters' => [
                'department' => trim((string) $request->query('department', '')),
                'status' => trim((string) $request->query('status', 'pending')),
            ],
            'leaveChart' => $this->leaveUsageChart((int) $request->query('department', 0)),
        ]);
    }

    public function leaveData(Request $request): JsonResponse
    {
        $this->ensureHrManager($request);

        $leavePage = $this->leaveRows($request);

        return response()->json([
            'rows' => $leavePage->items(),
            'pagination' => $this->paginationPayload($leavePage),
            'chart' => $this->leaveUsageChart((int) $request->query('department', 0)),
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
                    'Your requested document (' . $documentRequest->document_type . ') is completed and ready for release.',
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

        if (!in_array($format, ['pdf', 'excel'], true)) {
            return redirect()->route('hr-manager.reports');
        }

        $chart = $this->buildChartData(null);
        $filename = 'hr-workforce-report-' . now()->format('Ymd-His');

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
        }, $filename . ($format === 'pdf' ? '.pdf' : '.csv'), [
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
            'records_enabled'         => 'nullable|boolean',
            'leave_enabled'           => 'nullable|boolean',
            'frontdesk_enabled'       => 'nullable|boolean',
            'pending_alert_threshold' => 'nullable|integer|min:1',
            'email_template_subject'  => 'nullable|string|max:255',
            'email_template_body'     => 'nullable|string',
            'mayor_name'              => 'nullable|string|max:255',
            'mayor_designation'       => 'nullable|string|max:255',
            'vice_mayor_name'         => 'nullable|string|max:255',
            'vice_mayor_designation'  => 'nullable|string|max:255',
            'hr_manager_name'         => 'nullable|string|max:255',
            'hr_manager_designation'  => 'nullable|string|max:255',
        ]);

        $validated['records_enabled']   = $request->boolean('records_enabled');
        $validated['leave_enabled']     = $request->boolean('leave_enabled');
        $validated['frontdesk_enabled'] = $request->boolean('frontdesk_enabled');

        // Ensure email template fields are never null (database columns are NOT NULL)
        $validated['email_template_subject'] = $validated['email_template_subject'] ?? '';
        $validated['email_template_body'] = $validated['email_template_body'] ?? '';

        $settings = Setting::first();
        if ($settings) {
            $settings->update($validated);
        } else {
            $validated['email_template_subject'] ??= '';
            $validated['email_template_body']    ??= '';
            Setting::create($validated);
        }

        return redirect()->route('hr-manager.settings')->with('success', 'Settings updated successfully.');
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
                $inner->where('users.name', 'like', '%' . $search . '%')
                    ->orWhere('users.EmpNo', 'like', '%' . $search . '%')
                    ->orWhere('users.designation', 'like', '%' . $search . '%');
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
    private function leaveRows(Request $request): LengthAwarePaginator
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
            $query->where('users.Dept_id', $department);
        }

        if ($status !== '' && $status !== 'all') {
            $query->whereRaw('LOWER(leave_requests.status) = ?', [$status]);
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
                    'period' => $row->start_date . ' to ' . $row->end_date,
                    'days' => $row->total_days,
                    'status' => strtolower((string) $row->status),
                ];
            });
    }

    /**
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function leaveUsageChart(int $departmentId = 0): array
    {
        $query = LeaveRequest::query()
            ->leftJoin('users', 'users.id', '=', 'leave_requests.user_id')
            ->select('leave_requests.leave_type', DB::raw('COUNT(*) as total'))
            ->groupBy('leave_requests.leave_type')
            ->orderBy('leave_requests.leave_type');

        if ($departmentId > 0) {
            $query->where('users.Dept_id', $departmentId);
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

        if ($date !== '') {
            $query->whereDate('hr_audit_trails.created_at', $date);
        }

        return $query
            ->limit(200)
            ->get()
            ->map(function ($row): array {
                return [
                    'user' => (string) ($row->actor_name ?? 'System'),
                    'role' => ucwords($this->normalizeRole((string) ($row->access_level ?? 'hr manager'))),
                    'action' => strtoupper((string) $row->module) . ': ' . strtoupper((string) $row->action),
                    'timestamp' => $this->formatDateTime($row->created_at),
                ];
            })
            ->all();
    }

    /**
     * @param LengthAwarePaginator<int, mixed> $paginator
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
     * @param array<string, mixed> $details
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
            if (!Schema::hasTable($table)) {
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

            if (!empty($employee->date_hired)) {
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
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\User>
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
     * @param Collection<int, int> $userIds
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
     * @param array<string, mixed> $pds
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
     * @param array<string, mixed> $pds
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
     * @param array<string, mixed> $pds
     */
    private function extractYearsOfService(mixed $createdAt, array $pds): int
    {
        $workSection = (array) ($pds['pds-work-experience'] ?? []);
        $earliestWorkDate = null;

        foreach ($workSection as $key => $value) {
            if (!preg_match('/^work\[\d+\]\[from\]$/', (string) $key)) {
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
     * @param Collection<int, User> $employees
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
     * @param array<string, int> $assoc
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
     * @param array<string, int> $assoc
     * @return array{labels: array<int, string>, values: array<int, int>}
     */
    private function pieChartFromAssoc(array $assoc): array
    {
        return [
            'labels' => array_keys($assoc),
            'values' => array_values($assoc),
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
