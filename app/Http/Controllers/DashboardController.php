<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Pds;
use App\Models\User;
use App\Services\LeaveRequestService;
use App\Services\PdsService;
use App\Services\RecordsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DashboardController extends Controller
{
    private const ROLE_VIEW_MAP = [
        'employee' => 'dashboards.employee',
        'department head' => 'dashboards.department-head',
        'administrative officer' => 'dashboards.administrative-officer',
        'hr manager' => 'hr-manager.dashboard',
        'mayor' => 'dashboards.mayor',
        'leave manager' => 'dashboards.leave-manager',
        'time keeper' => 'dashboards.time-keeper',
        'payroll manager' => 'dashboards.payroll-manager',
        'records manager' => 'dashboards.records-manager',
        'front desk' => 'dashboards.front-desk',
    ];

    private const EMPLOYEE_TYPES = [
        'Permanent',
        'Elected Officials',
        'Co-Terminus',
        'Casual',
        'Job Orders',
        'Contractual',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $role = $this->normalizeRole((string) $user->access_level);

        if ($role === 'records manager') {
            return redirect()->route('dashboard.records-manager');
        }

        // Department heads have a dedicated controller and route group.
        // Redirect them to the Department Head area instead of rendering
        // the duplicate dashboards.department-head view.
        if ($role === 'department head') {
            return redirect()->route('department-head.index');
        }

        // Administrative Officers have their own controller with Department
        // Management functions (same as Department Head, excluding Self-Service).
        if ($role === 'administrative officer') {
            return redirect()->route('admin-officer.index');
        }

        if ($role === 'front desk') {
            return redirect()->route('front-desk.index');
        }

        if ($role === 'hr manager') {
            return redirect()->route('hr-manager.dashboard');
        }

        if ($role === 'payroll manager') {
            return redirect()->route('payroll.dashboard');
        }

        $view = self::ROLE_VIEW_MAP[$role] ?? 'dashboards.generic';

        $viewData = ['user' => $user, 'role' => $role];

        if ($role === 'leave manager') {
            $now = now();
            $yearStart = $now->copy()->startOfYear()->toDateString();
            $threeMonthsAgo = $now->copy()->subMonths(3)->toDateString();

            $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();
            $leaveService = app(LeaveRequestService::class);

            // Summary cards
            $totalFiled = LeaveRequest::where('date_filed', '>=', $yearStart)->count();
            $approvedCount = LeaveRequest::where('date_filed', '>=', $yearStart)->where('status', 'approved')->count();
            $cancelledCount = LeaveRequest::where('date_filed', '>=', $yearStart)->where('status', 'cancelled')->count();
            $pendingCancellationCount = LeaveRequest::where('cancellation_status', 'Pending Cancellation')->count();
            $employeeBalanceCount = LeaveBalance::count();
            $lowBalanceCount = LeaveBalance::where(function ($q): void {
                $q->where(function ($q2): void {
                    $q2->whereNotNull('VL')->where('VL', '<=', 5);
                })
                    ->orWhere(function ($q2): void {
                        $q2->whereNotNull('SL')->where('SL', '<=', 5);
                    });
            })->count();

            // Anomaly: dept with most sick leave in last 3 months
            $anomalyDept = null;
            $sickRow = LeaveRequest::join('users', 'leave_requests.user_id', '=', 'users.id')
                ->where('leave_requests.status', 'approved')
                ->where('leave_requests.leave_type', 'LIKE', '%Sick%')
                ->where('leave_requests.date_filed', '>=', $threeMonthsAgo)
                ->whereNotNull('users.Dept_id')
                ->selectRaw('users.Dept_id, COUNT(*) as cnt')
                ->groupBy('users.Dept_id')
                ->orderByDesc('cnt')
                ->first();
            if ($sickRow && $sickRow->cnt >= 3) {
                $anomalyDept = [
                    'name' => $departments[$sickRow->Dept_id] ?? 'Unknown Department',
                    'count' => (int) $sickRow->cnt,
                ];
            }

            $viewData = array_merge($viewData, [
                'pendingCancellationCount' => $pendingCancellationCount,
                'employeeBalanceCount' => $employeeBalanceCount,
                'totalFiled' => $totalFiled,
                'approvedCount' => $approvedCount,
                'cancelledCount' => $cancelledCount,
                'lowBalanceCount' => $lowBalanceCount,
                'criticalBalances' => $leaveService->criticalBalances(),
                'anomalyDept' => $anomalyDept,
            ]);
        }

        return view($view, $viewData);
    }

    public function employeePds(Request $request): View
    {
        $this->ensureEmployee($request);

        $user = $request->user();
        $pds = Pds::firstOrCreate(
            ['user_id' => $user->id],
            ['section_data' => []]
        );

        return view('dashboards.employee-pds', [
            'user' => $user,
            'role' => $this->normalizeRole((string) $user->access_level),
            'pds' => $pds,
        ]);
    }

    public function savePdsDraft(Request $request): JsonResponse
    {
        try {
            $this->ensureEmployee($request);

            $request->validate([
                'section_key' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9\-]+$/'],
            ]);

            $user = $request->user();
            $sectionKey = $request->input('section_key');
            $sectionDataRaw = $request->input('section_data', []);

            // Reject payloads that would serialize to more than 100 KB
            if (strlen((string) json_encode($sectionDataRaw)) > 102400) {
                return response()->json(['error' => 'Section data payload is too large.'], 413);
            }

            // Parse JSON string if necessary
            $sectionData = is_string($sectionDataRaw)
                ? json_decode($sectionDataRaw, true) ?? []
                : $sectionDataRaw;

            // Server-side validation: for work experience, ensure only one "to_present" is set
            if ($sectionKey === 'pds-work-experience' && is_array($sectionData)) {
                $presentCount = 0;
                foreach ($sectionData as $fieldName => $value) {
                    if (preg_match('/^work\[\d+\]\[to_present\]$/', (string) $fieldName)) {
                        if ($value === true || $value === '1' || $value === 1 || $value === 'true') {
                            $presentCount++;
                        }
                    }
                }

                if ($presentCount > 1) {
                    return response()->json(['error' => 'Only one work entry can be marked Present.'], 400);
                }
            }

            $pds = Pds::firstOrCreate(
                ['user_id' => $user->id],
                ['section_data' => []]
            );

            $pds->saveSectionData($sectionKey, $sectionData);

            // Synchronize name fields with users table when personal info is saved
            if ($sectionKey === 'pds-personal-info' && is_array($sectionData)) {
                $nameUpdates = [];

                $surname = $sectionData['personal[surname]'] ?? null;
                if ($surname !== null && trim($surname) !== '') {
                    $nameUpdates['last_name'] = trim($surname);
                }

                $firstName = $sectionData['personal[first_name]'] ?? null;
                if ($firstName !== null && trim($firstName) !== '') {
                    $nameUpdates['first_name'] = trim($firstName);
                }

                $middleName = $sectionData['personal[middle_name]'] ?? null;
                if ($middleName !== null && trim($middleName) !== '') {
                    $nameUpdates['middle_name'] = trim($middleName);
                }

                $nameExt = $sectionData['personal[name_extension]'] ?? null;
                if ($nameExt !== null && trim($nameExt) !== '') {
                    $nameUpdates['name_extension'] = trim($nameExt);
                }

                if (! empty($nameUpdates)) {
                    // Rebuild the composite "name" column: LAST, FIRST MIDDLE EXT
                    $last = $nameUpdates['last_name'] ?? $user->last_name ?? '';
                    $first = $nameUpdates['first_name'] ?? $user->first_name ?? '';
                    $middle = $nameUpdates['middle_name'] ?? $user->middle_name ?? '';
                    $ext = $nameUpdates['name_extension'] ?? $user->name_extension ?? '';
                    $fullName = trim("$last, $first $middle $ext");
                    $fullName = preg_replace('/\s+/', ' ', $fullName);
                    $nameUpdates['name'] = $fullName;

                    $user->forceFill($nameUpdates)->save();
                }
            }

            // Audit log
            HRAuditTrail::create([
                'actor_user_id' => $user->id,
                'module' => 'PDS',
                'action' => 'save_draft',
                'target_type' => 'App\\Models\\Pds',
                'target_id' => $pds->id,
                'details' => [
                    'section_key' => $sectionKey,
                    'EmpNo' => $user->EmpNo,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Section saved successfully.',
                'section_key' => $sectionKey,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function exportPdsExcel(Request $request, PdsService $pdsService): StreamedResponse|JsonResponse
    {
        $this->ensureEmployee($request);

        $user = $request->user();
        $tmpFile = null;

        try {
            ini_set('memory_limit', '256M');

            $pdsRecord = Pds::where('user_id', $user->id)->first();

            Log::info('PDS export started', [
                'user_id' => $user->id,
                'EmpNo' => $user->EmpNo,
                'pds_id' => $pdsRecord?->id,
                'sections_filled' => $pdsRecord ? count(array_filter((array) $pdsRecord->section_data)) : 0,
                'template_exists' => is_file(storage_path('app/templates/PDS.xlsx')),
                'memory_limit' => ini_get('memory_limit'),
            ]);

            $spreadsheet = $pdsService->exportToExcel($user);

            // Write to a temp file so any serialization failure is caught here,
            // not after headers have been sent inside the streamDownload callback.
            $tmpFile = tempnam(sys_get_temp_dir(), 'pds_');
            $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tmpFile);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            $filename = 'PDS_'.strtoupper(str_replace(' ', '_', (string) $user->name)).'_'.now()->format('Y-m-d').'.xlsx';

            HRAuditTrail::create([
                'actor_user_id' => $user->id,
                'module' => 'PDS',
                'action' => 'export',
                'target_type' => 'App\\Models\\Pds',
                'target_id' => $pdsRecord?->id,
                'details' => [
                    'EmpNo' => $user->EmpNo,
                    'filename' => $filename,
                ],
            ]);

            $localTmp = $tmpFile;

            return response()->streamDownload(
                static function () use ($localTmp): void {
                    $handle = fopen($localTmp, 'rb');
                    if ($handle !== false) {
                        while (! feof($handle)) {
                            echo fread($handle, 65536);
                        }
                        fclose($handle);
                    }
                    @unlink($localTmp);
                },
                $filename,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            );
        } catch (Throwable $e) {
            if ($tmpFile !== null && is_file($tmpFile)) {
                @unlink($tmpFile);
            }

            Log::error('PDS export failed', [
                'user_id' => $user->id ?? null,
                'EmpNo' => $user->EmpNo ?? null,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'trace' => array_slice(
                    array_map(
                        static fn (array $f) => ($f['file'] ?? '?').':'.($f['line'] ?? '?'),
                        $e->getTrace()
                    ),
                    0, 8
                ),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'PDS export failed. Please try again or contact support.',
            ], 500);
        }
    }

    public function recordsManager(Request $request, RecordsService $recordsService): View
    {
        $this->ensureRecordsManager($request);

        $data = $recordsService->getRecordsManagerData();

        return view('dashboards.records-manager', [
            'user' => $request->user(),
            'accessLevels' => array_keys(self::ROLE_VIEW_MAP),
            'employeeTypes' => self::EMPLOYEE_TYPES,
            'employees' => $data['employees'],
            'departments' => $data['departments'],
            'statusSummary' => $data['statusSummary'],
            'statusByGroup' => $data['statusByGroup'],
            'topDepartments' => $data['topDepartments'],
            'largestDepartmentCount' => $data['largestDepartmentCount'],
            'accessDistribution' => $data['accessDistribution'],
            'employeeTypeDistribution' => $data['employeeTypeDistribution'],
            'dataQuality' => $data['dataQuality'],
            'averageGapScore' => $data['averageGapScore'],
            'profileCompletenessRate' => $data['profileCompletenessRate'],
        ]);
    }

    public function recordsManagerDepartments(Request $request): View
    {
        $this->ensureRecordsManager($request);
        [$employees] = app(RecordsService::class)->collections();
        $departmentEmployeeCounts = $employees
            ->groupBy('Dept_id')
            ->map(fn ($group) => $group->count());

        $search = trim((string) $request->query('search', ''));
        $statusFilter = trim((string) $request->query('status', ''));
        $assignedDepartmentIds = $departmentEmployeeCounts
            ->filter(fn ($count) => (int) $count > 0)
            ->keys()
            ->map(fn ($deptId) => (int) $deptId)
            ->all();

        $departmentsQuery = Department::query()->orderBy('Dept_name');

        if ($search !== '') {
            $departmentsQuery->where('Dept_name', 'like', '%'.$search.'%');
        }

        if ($statusFilter === 'assigned') {
            if ($assignedDepartmentIds === []) {
                $departmentsQuery->whereRaw('1 = 0');
            } else {
                $departmentsQuery->whereIn('Dept_id', $assignedDepartmentIds);
            }
        }

        if ($statusFilter === 'unassigned') {
            if ($assignedDepartmentIds !== []) {
                $departmentsQuery->whereNotIn('Dept_id', $assignedDepartmentIds);
            }
        }

        // Load all department rows and let DataTables handle client-side pagination.
        $departments = $departmentsQuery->get();
        $allDepartments = Department::query()
            ->orderBy('Dept_name')
            ->get(['Dept_id', 'Dept_name']);

        $totalDepartments = Department::query()->count();
        $assignedDepartmentsCount = count($assignedDepartmentIds);
        $unassignedDepartmentsCount = $totalDepartments - $assignedDepartmentsCount;

        $departmentHeadUsers = User::query()
            ->whereRaw('LOWER(access_level) = ?', ['department head'])
            ->whereNotNull('EmpNo')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'EmpNo', 'last_name', 'first_name', 'middle_name']);

        $adminOfficerUsers = User::query()
            ->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'administrative officer'")
            ->whereNotNull('EmpNo')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'EmpNo', 'last_name', 'first_name', 'middle_name']);

        return view('dashboards.records-manager-departments', [
            'user' => $request->user(),
            'departments' => $departments,
            'allDepartments' => $allDepartments,
            'departmentEmployeeCounts' => $departmentEmployeeCounts,
            'totalDepartments' => $totalDepartments,
            'assignedDepartmentsCount' => $assignedDepartmentsCount,
            'unassignedDepartmentsCount' => $unassignedDepartmentsCount,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'departmentHeadUsers' => $departmentHeadUsers,
            'adminOfficerUsers' => $adminOfficerUsers,
        ]);
    }

    public function storeDepartmentRecord(Request $request): RedirectResponse
    {
        $this->ensureRecordsManager($request);

        $validated = $request->validate([
            // DeptCode may be omitted to allow server-side auto-generation
            'DeptCode' => ['nullable', 'string', 'max:255', Rule::unique('departments', 'DeptCode')],
            'Dept_name' => ['required', 'string', 'max:255', Rule::unique('departments', 'Dept_name')],
            'EmpNo' => ['required', 'string', 'max:255', 'exists:users,EmpNo'],
            'ao_emp_no' => ['nullable', 'string', 'max:255', 'exists:users,EmpNo'],
            'Designation' => ['required', 'string', 'max:255'],
            'parent_dept_id' => ['nullable', 'exists:departments,Dept_id'],
        ], [
            'DeptCode.unique' => 'Department code already exists. Please use a different code.',
            'Dept_name.unique' => 'Department name already exists. Please use a different name.',
            'EmpNo.exists' => 'Invalid EmpNo: no user found with this employee number.',
            'ao_emp_no.exists' => 'Invalid Admin Officer EmpNo: no user found with this employee number.',
        ]);

        // Verify the selected employee has department head role
        $storeUser = User::query()->where('EmpNo', $validated['EmpNo'])->first(['id', 'access_level']);
        if ($storeUser && mb_strtolower(trim((string) $storeUser->access_level)) !== 'department head') {
            return back()
                ->withInput()
                ->withErrors([
                    'EmpNo' => 'Invalid EmpNo: must belong to a valid user with department head role.',
                ]);
        }

        // Verify the selected admin officer has administrative officer role
        $aoEmpNo = $validated['ao_emp_no'] ?? null;
        if ($aoEmpNo !== null && $aoEmpNo !== '') {
            $aoEmpNoUpper = mb_strtoupper(trim($aoEmpNo));
            $aoUser = User::query()->where('EmpNo', $aoEmpNoUpper)->first(['id', 'access_level']);
            if ($aoUser) {
                $aoRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($aoUser->access_level ?? ''))));
                if ($aoRole !== 'administrative officer') {
                    return back()->withInput()->withErrors([
                        'ao_emp_no' => 'Invalid Admin Officer EmpNo: must belong to a user with administrative officer role.',
                    ]);
                }
            }
            $aoEmpNo = $aoEmpNoUpper;
        } else {
            $aoEmpNo = null;
        }

        $validated = $this->normalizeDepartmentTextInput($validated);

        if (empty($validated['DeptCode'])) {
            $validated['DeptCode'] = $this->generateDepartmentCode(isset($validated['parent_dept_id']) ? (int) $validated['parent_dept_id'] : null);
        }

        Department::query()->create([
            'DeptCode' => $validated['DeptCode'],
            'Dept_name' => $validated['Dept_name'],
            'EmpNo' => $validated['EmpNo'],
            'ao_emp_no' => $aoEmpNo,
            'Designation' => $validated['Designation'],
            'parent_dept_id' => $validated['parent_dept_id'] ?? null,
        ]);

        return back()->with('status', 'Department created successfully.');
    }

    public function updateDepartmentRecord(Request $request, Department $department): RedirectResponse
    {
        $this->ensureRecordsManager($request);

        $validated = $request->validate([
            // Allow DeptCode to be omitted so it can be auto-generated on update
            'DeptCode' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('departments', 'DeptCode')->ignore($department->Dept_id, 'Dept_id'),
            ],
            'Dept_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('departments', 'Dept_name')->ignore($department->Dept_id, 'Dept_id'),
            ],
            'EmpNo' => ['nullable', 'string', 'max:255'],
            'ao_emp_no' => ['nullable', 'string', 'max:255'],
            'Designation' => ['required', 'string', 'max:255'],
            'parent_dept_id' => ['nullable', 'exists:departments,Dept_id'],
        ], [
            'DeptCode.unique' => 'Department code already exists. Please use a different code.',
            'Dept_name.unique' => 'Department name already exists. Please use a different name.',
        ]);

        // Validate EmpNo against users table when provided
        $empNo = $validated['EmpNo'] ?? null;
        if ($empNo !== null && $empNo !== '') {
            $empNoUpper = mb_strtoupper(trim($empNo));
            $matchingUser = User::query()
                ->where('EmpNo', $empNoUpper)
                ->first(['id', 'EmpNo', 'access_level']);

            if (! $matchingUser) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'EmpNo' => 'Invalid EmpNo: no user found with this employee number.',
                    ]);
            }

            if (mb_strtolower(trim((string) $matchingUser->access_level)) !== 'department head') {
                return back()
                    ->withInput()
                    ->withErrors([
                        'EmpNo' => 'Invalid EmpNo: must belong to a valid user with department head role.',
                    ]);
            }
        } else {
            // Null-safe: keep the existing EmpNo when none is provided
            $empNo = $department->EmpNo;
        }

        // Validate ao_emp_no against users table when provided
        $aoEmpNo = $validated['ao_emp_no'] ?? null;
        if ($aoEmpNo !== null && $aoEmpNo !== '') {
            $aoEmpNoUpper = mb_strtoupper(trim($aoEmpNo));
            $aoUser = User::query()->where('EmpNo', $aoEmpNoUpper)->first(['id', 'access_level']);
            if (! $aoUser) {
                return back()->withInput()->withErrors([
                    'ao_emp_no' => 'Invalid Admin Officer EmpNo: no user found with this employee number.',
                ]);
            }
            $aoRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($aoUser->access_level ?? ''))));
            if ($aoRole !== 'administrative officer') {
                return back()->withInput()->withErrors([
                    'ao_emp_no' => 'Invalid Admin Officer EmpNo: must belong to a user with administrative officer role.',
                ]);
            }
            $aoEmpNo = $aoEmpNoUpper;
        } else {
            // Keep existing value; empty string means "clear it"
            $aoEmpNo = ($aoEmpNo === '') ? null : $department->ao_emp_no;
        }

        if ((int) ($validated['parent_dept_id'] ?? 0) === (int) $department->Dept_id) {
            return back()
                ->withInput()
                ->withErrors([
                    'parent_dept_id' => 'A department cannot be set as its own parent.',
                ]);
        }

        $validated = $this->normalizeDepartmentTextInput($validated);

        // If a parent department is set, derive a child code from the parent.
        // Also auto-generate top-level code if missing.
        $parentId = isset($validated['parent_dept_id']) && $validated['parent_dept_id'] !== ''
            ? (int) $validated['parent_dept_id']
            : null;

        if ($parentId !== null) {
            $validated['DeptCode'] = $this->generateDepartmentCode($parentId);
        } else {
            if (empty($validated['DeptCode'])) {
                $validated['DeptCode'] = $this->generateDepartmentCode(null);
            }
        }

        $oldEmpNo = $department->EmpNo;

        $department->forceFill([
            'DeptCode' => $validated['DeptCode'],
            'Dept_name' => $validated['Dept_name'],
            'EmpNo' => $empNo ? mb_strtoupper(trim($empNo)) : $oldEmpNo,
            'ao_emp_no' => $aoEmpNo,
            'Designation' => $validated['Designation'],
            'parent_dept_id' => $validated['parent_dept_id'] ?? null,
        ])->save();

        HRAuditTrail::query()->create([
            'actor_user_id' => $request->user()->id,
            'module' => 'Department Management',
            'action' => 'update',
            'target_type' => 'Department',
            'target_id' => $department->Dept_id,
            'details' => [
                'department_id' => $department->Dept_id,
                'old_emp_no' => $oldEmpNo,
                'new_emp_no' => $department->EmpNo,
                'updated_at' => now()->toDateTimeString(),
            ],
        ]);

        return back()->with('status', 'Department updated successfully.');
    }

    public function recordsManagerAccess(Request $request): View
    {
        $this->ensureRecordsManager($request);

        [$employees] = $this->recordsManagerCollections();
        $accessSummary = $employees
            ->groupBy(fn (User $employee) => $this->normalizeRole((string) $employee->access_level))
            ->map(fn ($group) => $group->count())
            ->sortDesc();
        $employeeTypeSummary = $employees
            ->filter(fn (User $employee) => $this->normalizeRole((string) $employee->access_level) === 'employee')
            ->groupBy(fn (User $employee) => (string) ($employee->employee_type ?: 'Unset'))
            ->map(fn ($group) => $group->count())
            ->sortDesc();

        return view('dashboards.records-manager-access', [
            'user' => $request->user(),
            'employees' => $employees,
            'accessSummary' => $accessSummary,
            'employeeTypeSummary' => $employeeTypeSummary,
        ]);
    }

    /**
     * @return array{0: Collection<int, User>, 1: Collection<int, Department>, 2: array{total:int, active:int, inactive:int}}
     */
    private function recordsManagerCollections(): array
    {
        $employees = User::query()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('middle_name')
            ->get([
                'id',
                'name',
                'last_name',
                'first_name',
                'middle_name',
                'email',
                'EmpNo',
                'designation',
                'Dept_id',
                'Status',
                'employee_type',
                'access_level',
            ]);

        $employees->each(function (User $employee): void {
            if ($employee->last_name && $employee->first_name) {
                return;
            }

            $nameParts = $this->splitEmployeeName((string) $employee->name);
            $employee->setAttribute('last_name', $employee->last_name ?: $nameParts['last_name']);
            $employee->setAttribute('first_name', $employee->first_name ?: $nameParts['first_name']);
            $employee->setAttribute('middle_name', $employee->middle_name ?: $nameParts['middle_name']);
        });

        $departments = Department::query()
            ->orderBy('Dept_name')
            ->get(['Dept_id', 'Dept_name']);

        $statusSummary = [
            'total' => $employees->count(),
            'active' => $employees->where('Status', 'Active')->count(),
            'inactive' => $employees->where('Status', '!=', 'Active')->count(),
        ];

        return [$employees, $departments, $statusSummary];
    }

    private function ensureRecordsManager(Request $request): void
    {
        $role = $this->normalizeRole((string) $request->user()->access_level);
        abort_unless($role === 'records manager', 403, 'Only Records Manager can access this section.');
    }

    private function ensureEmployee(Request $request): void
    {
        $role = $this->normalizeRole((string) $request->user()->access_level);
        $allowed = ['employee', 'department head', 'hr manager', 'administrative officer'];
        abort_unless(in_array($role, $allowed, true), 403, 'Only Employee, Department Head, HR Manager, or Administrative Officer users can access this section.');
    }

    /**
     * @return array{last_name:string, first_name:string, middle_name:string}
     */
    private function splitEmployeeName(string $fullName): array
    {
        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);

        if ($fullName === '') {
            return [
                'last_name' => '',
                'first_name' => '',
                'middle_name' => '',
            ];
        }

        if (str_contains($fullName, ',')) {
            [$lastName, $remainingName] = array_pad(array_map('trim', explode(',', $fullName, 2)), 2, '');
            $remainingParts = preg_split('/\s+/', $remainingName, -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return [
                'last_name' => $lastName,
                'first_name' => $remainingParts[0] ?? '',
                'middle_name' => implode(' ', array_slice($remainingParts, 1)),
            ];
        }

        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) === 1) {
            return [
                'last_name' => '',
                'first_name' => $parts[0],
                'middle_name' => '',
            ];
        }

        $firstName = array_shift($parts) ?? '';
        $lastName = array_pop($parts) ?? '';

        return [
            'last_name' => $lastName,
            'first_name' => $firstName,
            'middle_name' => implode(' ', $parts),
        ];
    }

    private function buildEmployeeName(string $lastName, string $firstName, ?string $middleName = null): string
    {
        $lastName = trim($lastName);
        $firstName = trim($firstName);
        $middleName = trim((string) $middleName);

        $givenName = trim($firstName.' '.$middleName);

        return trim($lastName.', '.$givenName, ', ');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeEmployeeTextInput(array $validated): array
    {
        foreach (['last_name', 'first_name', 'middle_name', 'EmpNo', 'designation'] as $field) {
            if (! array_key_exists($field, $validated) || $validated[$field] === null) {
                continue;
            }

            $value = trim((string) $validated[$field]);
            $validated[$field] = $value === '' ? null : Str::upper($value);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeDepartmentTextInput(array $validated): array
    {
        foreach (['DeptCode', 'Dept_name', 'EmpNo', 'Designation'] as $field) {
            if (! array_key_exists($field, $validated) || $validated[$field] === null) {
                continue;
            }

            $value = trim((string) $validated[$field]);
            $validated[$field] = $value === '' ? null : Str::upper($value);
        }

        return $validated;
    }

    private function generateDepartmentCode(?int $parentDeptId): string
    {
        if ($parentDeptId !== null) {
            $parent = Department::query()->find($parentDeptId);
            $base = $parent && $parent->DeptCode ? $parent->DeptCode : 'DEPT';

            $existing = Department::query()
                ->where('parent_dept_id', $parentDeptId)
                ->pluck('DeptCode')
                ->filter()
                ->all();

            $max = 0;
            foreach ($existing as $code) {
                if (preg_match('/-C(\d+)$/', (string) $code, $m)) {
                    $n = (int) $m[1];
                    if ($n > $max) {
                        $max = $n;
                    }
                }
            }

            $next = $max + 1;

            return $base.'-C'.$next;
        }

        // Top-level department code generation: DEPT-###
        $topCodes = Department::query()
            ->whereNull('parent_dept_id')
            ->pluck('DeptCode')
            ->filter()
            ->all();

        $max = 0;
        foreach ($topCodes as $code) {
            if (preg_match('/DEPT-0*(\d+)$/', (string) $code, $m)) {
                $n = (int) $m[1];
                if ($n > $max) {
                    $max = $n;
                }
            }
        }

        $next = $max + 1;
        $num = str_pad((string) $next, 3, '0', STR_PAD_LEFT);

        return 'DEPT-'.$num;
    }

    private function hasDuplicateEmployeeName(string $lastName, string $firstName, ?int $ignoreUserId = null): bool
    {
        $query = User::query()
            ->where('last_name', $lastName)
            ->where('first_name', $firstName);

        if ($ignoreUserId !== null) {
            $query->where('id', '!=', $ignoreUserId);
        }

        return $query->exists();
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }
}
