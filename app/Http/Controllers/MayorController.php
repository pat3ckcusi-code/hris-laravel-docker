<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Department;
use App\Models\User;
use App\Models\Pds;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Mail\LeaveRequestStatusNotification;
use App\Services\LeaveRequestService;
use Carbon\Carbon;

class MayorController extends Controller
{
    private LeaveRequestService $leaveRequestService;

    public function __construct(LeaveRequestService $leaveRequestService)
    {
        $this->leaveRequestService = $leaveRequestService;
    }

    public function dashboard(Request $request)
    {
        $departments = Department::orderBy('Dept_name')->get();

        // Pending leave requests from Department Heads and HR Managers for the Mayor
        $pendingLeaveCount = $this->getPendingMayorLeaveQuery()->count();

        return view('mayor.dashboard', [
            'departments' => $departments,
            'summary' => $this->buildSummaryCards(),
            'chartDataUrl' => route('mayor.chart-data'),
            'initialChartData' => $this->buildChartData(null),
            'pendingLeaveCount' => $pendingLeaveCount,
        ]);
    }

    public function getChartData(Request $request)
    {
        $departmentId = $request->integer('department');

        return response()->json($this->buildChartData($departmentId > 0 ? $departmentId : null));
    }

    public function getEmployeesByFilter(Request $request)
    {
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

            if (!empty($row->date_hired)) {
                try {
                    $yearsOfService = Carbon::parse($row->date_hired)->diffInYears(now());
                } catch (\Throwable $e) {
                    $yearsOfService = 0;
                }
            } else {
                $yearsOfService = 0;
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

    public function reports()
    {
        return view('mayor.reports');
    }

    public function approvals(Request $request)
    {
        $statusFilter = $request->query('status', 'pending');

        $query = $this->getMayorLeaveQuery();

        if ($statusFilter && $statusFilter !== 'all') {
            $query->where('leave_requests.status', $statusFilter);
        }

        $leaveRequests = $query->orderBy('leave_requests.created_at', 'desc')->paginate(10);

        return view('mayor.approvals', [
            'leaveRequests' => $leaveRequests,
            'statusFilter' => $statusFilter,
        ]);
    }

    /**
     * Approve a leave request from a Department Head or HR Manager.
     */
    public function approveLeave(Request $request, $id)
    {
        Log::info('Mayor approveLeave called', ['leave_id' => $id, 'ajax' => $request->ajax(), 'wantsJson' => $request->wantsJson()]);

        $leave = LeaveRequest::findOrFail($id);

        // Verify the applicant is a Department Head or HR Manager
        $applicant = $leave->user;
        if (!$applicant) {
            Log::warning('Mayor approveLeave: applicant not found', ['leave_id' => $id]);
            return $this->respondError($request, 'Applicant not found.');
        }

        $applicantRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($applicant->access_level ?? ''))));
        Log::info('Mayor approveLeave: applicant role check', ['leave_id' => $id, 'raw_role' => $applicant->access_level, 'normalized' => $applicantRole]);

        if (!in_array($applicantRole, ['department head', 'hr manager'])) {
            return $this->respondError($request, 'You are not authorized to approve this request. Only Department Head and HR Manager leave requests are routed to the Mayor.', 403);
        }

        try {
            $result = $this->leaveRequestService->approveLeave($request, $id);
            Log::info('Mayor approveLeave: service returned', ['leave_id' => $id, 'response_class' => get_class($result)]);
            return $result;
        } catch (\Exception $e) {
            Log::error('Mayor approveLeave: exception', ['leave_id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Server error: ' . $e->getMessage()], 500);
            }
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Reject a leave request from a Department Head or HR Manager.
     */
    public function rejectLeave(Request $request, $id)
    {
        $request->validate([
            'rejection_notes' => ['required', 'string', 'max:2000'],
        ]);

        $leave = LeaveRequest::findOrFail($id);

        // Verify the applicant is a Department Head or HR Manager
        $applicant = $leave->user;
        if (!$applicant) {
            return $this->respondError($request, 'Applicant not found.');
        }

        $applicantRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($applicant->access_level ?? ''))));
        if (!in_array($applicantRole, ['department head', 'hr manager'])) {
            return $this->respondError($request, 'You are not authorized to reject this request.', 403);
        }

        $leave->status = 'declined';
        $leave->rejection_notes = $request->input('rejection_notes');
        $leave->save();

        // Send rejection notification email
        try {
            $employee = $leave->user;
            if ($employee && !empty($employee->Dept_id)) {
                $empDept = Department::find($employee->Dept_id);
                if ($empDept) $employee->department_name = $empDept->Dept_name ?? null;
            }
            $formatted = [
                'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                'end'   => Carbon::parse($leave->end_date)->format('l, F j, Y'),
            ];
            $lb = $employee ? $employee->leaveBalance : null;
            $balances = [
                'VL'   => $lb->VL   ?? 0,
                'SL'   => $lb->SL   ?? 0,
                'WLNS' => $lb->WLNS ?? 0,
                'SP'   => $lb->SP   ?? 0,
                'SPL'  => $lb->SPL  ?? 0,
                'CTO'  => $lb->CTO  ?? 0,
            ];
            if ($employee) {
                $email = $employee->email ?? null;
                Log::info('Mayor leave rejection email attempt', ['leave_id' => $leave->id, 'user_id' => $employee->id ?? null, 'email' => $email]);
                if (!empty($email)) {
                    Mail::to($email)->queue(new LeaveRequestStatusNotification($employee, $leave, $formatted, 'declined', $leave->rejection_notes, $balances));
                    Log::info('Mayor leave rejection email queued', ['leave_id' => $leave->id, 'email' => $email]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error sending mayor leave rejection email', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'swal' => [
                    'icon' => 'success',
                    'title' => 'Leave request rejected',
                    'text' => 'Leave request has been rejected.',
                ]
            ]);
        }

        return redirect()->back()->with('success', 'Leave request rejected.');
    }

    /**
     * Return leave requests from Department Heads and HR Managers as JSON (API).
     */
    public function leaveRequestsData(Request $request)
    {
        $statusFilter = $request->query('status', 'pending');

        $query = $this->getMayorLeaveQuery();

        if ($statusFilter && $statusFilter !== 'all') {
            $query->where('leave_requests.status', $statusFilter);
        }

        $rows = $query->orderBy('leave_requests.created_at', 'desc')->get();

        return response()->json($rows);
    }

    /**
     * Travel Order Approvals page.
     */
    public function travelOrderApprovals(Request $request)
    {
        $statusFilter = $request->query('status', 'Pending');
        $monthFilter = $request->query('month', now()->format('Y-m'));

        $query = DB::table('travel_orders')
            ->select(
                'travel_orders.id',
                'travel_orders.travel_order_num',
                'travel_orders.start_date',
                'travel_orders.end_date',
                'travel_orders.status',
                'travel_orders.created_at',
                DB::raw('(SELECT COUNT(*) FROM travel_order_employees WHERE travel_order_employees.travel_order_id = travel_orders.id) as employee_count')
            );

        if ($statusFilter && $statusFilter !== 'All') {
            $query->where('travel_orders.status', $statusFilter);
        }

        if ($monthFilter) {
            $query->whereRaw("DATE_FORMAT(travel_orders.start_date, '%Y-%m') = ?", [$monthFilter]);
        }

        $travelOrders = $query->orderByDesc('travel_orders.created_at')->paginate(10);

        return view('mayor.travel-order-approvals', [
            'travelOrders' => $travelOrders,
            'statusFilter' => $statusFilter,
            'monthFilter' => $monthFilter,
        ]);
    }

    /**
     * View a single travel order with employees (JSON).
     */
    public function viewTravelOrder(Request $request, $id)
    {
        $order = DB::table('travel_orders')->where('id', $id)->first();
        if (!$order) {
            return response()->json(['success' => false, 'message' => 'Travel order not found.'], 404);
        }

        $empNos = DB::table('travel_order_employees')
            ->where('travel_order_id', $order->id)
            ->pluck('emp_no')
            ->toArray();

        $employees = User::whereIn('EmpNo', $empNos)->get()->map(function ($u) {
            return [
                'emp_no' => $u->EmpNo,
                'name' => trim(($u->last_name ?? '') . ', ' . ($u->first_name ?? '')),
                'designation' => $u->designation ?? 'N/A',
            ];
        })->values();

        $creator = User::find($order->created_by);
        $recommender = User::find($order->recommender);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $order->id,
                'travel_order_num' => $order->travel_order_num,
                'purpose' => $order->purpose,
                'destination' => $order->destination,
                'start_date' => $order->start_date,
                'end_date' => $order->end_date,
                'remarks' => $order->Remarks ?? '',
                'status' => $order->status,
                'rejection_note' => $order->rejection_note ?? '',
                'created_by' => $creator ? trim(($creator->last_name ?? '') . ', ' . ($creator->first_name ?? '')) : 'N/A',
                'recommender' => $recommender ? trim(($recommender->last_name ?? '') . ', ' . ($recommender->first_name ?? '')) : 'N/A',
                'created_at' => $order->created_at,
                'employees' => $employees,
            ],
        ]);
    }

    /**
     * Approve a travel order.
     */
    public function approveTravelOrder(Request $request, $id)
    {
        $order = DB::table('travel_orders')->where('id', $id)->first();
        if (!$order) {
            return $this->respondError($request, 'Travel order not found.', 404);
        }

        if ($order->status !== 'Pending') {
            return $this->respondError($request, 'Only travel orders with "Pending" status can be approved.');
        }

        DB::table('travel_orders')->where('id', $id)->update([
            'status' => 'Approved',
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'updated_at' => now(),
        ]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Travel Order Approved',
            ]);
        }

        return redirect()->back()->with('success', 'Travel Order Approved.');
    }

    /**
     * Reject a travel order.
     */
    public function rejectTravelOrder(Request $request, $id)
    {
        $request->validate([
            'rejection_note' => ['required', 'string', 'max:50'],
        ]);

        $order = DB::table('travel_orders')->where('id', $id)->first();
        if (!$order) {
            return $this->respondError($request, 'Travel order not found.', 404);
        }

        if ($order->status !== 'Pending') {
            return $this->respondError($request, 'Only travel orders with "Pending" status can be rejected.');
        }

        DB::table('travel_orders')->where('id', $id)->update([
            'status' => 'Rejected',
            'rejection_note' => $request->input('rejection_note'),
            'rejected_by' => Auth::id(),
            'rejected_at' => now(),
            'updated_at' => now(),
        ]);

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Travel Order Rejected',
            ]);
        }

        return redirect()->back()->with('success', 'Travel Order Rejected.');
    }

    /**
     * Build a query for leave requests from Department Heads and HR Managers.
     */
    private function getMayorLeaveQuery()
    {
        $dhHrUserIds = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) IN ('department head', 'hr manager')")
            ->pluck('id');

        return LeaveRequest::with('user')
            ->whereIn('user_id', $dhHrUserIds);
    }

    /**
     * Build a query for pending leave requests from Department Heads and HR Managers.
     */
    private function getPendingMayorLeaveQuery()
    {
        return $this->getMayorLeaveQuery()->where('status', 'pending');
    }

    private function respondError(Request $request, string $message, int $code = 422)
    {
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => false, 'swal' => ['icon' => 'error', 'title' => 'Error', 'text' => $message]], $code);
        }
        return redirect()->back()->with('error', $message);
    }

    public function policies()
    {
        return view('mayor.policies');
    }

    public function employees()
    {
        return view('mayor.employees');
    }

    public function events()
    {
        return view('mayor.events');
    }

    public function settings()
    {
        return view('mayor.settings');
    }

    /**
     * @return array<string, int>
     */
    private function buildSummaryCards(): array
    {
        $totalEmployees = (int) User::query()
            ->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'employee'")
            ->count();

        $totalDepartments = Schema::hasTable('departments') ? (int) Department::count() : 0;

        $activeRequests = 0;
        if (Schema::hasTable('leave_requests')) {
            $activeRequests += (int) DB::table('leave_requests')->count();
        }
        if (Schema::hasTable('travel_orders')) {
            $activeRequests += (int) DB::table('travel_orders')->count();
        }

        // System notifications approximate: pending leaves + pending document requests
        $pending = 0;
        $pendingStatuses = ['pending', 'requested', 'for recommendation', 'pending recommendation', 'pending approval'];
        if (Schema::hasTable('leave_requests')) {
            $pending += (int) DB::table('leave_requests')->whereIn(DB::raw('LOWER(status)'), $pendingStatuses)->count();
        }
        if (Schema::hasTable('document_requests')) {
            $pending += (int) DB::table('document_requests')->whereIn(DB::raw('LOWER(status)'), $pendingStatuses)->count();
        }

        return [
            'total_employees' => $totalEmployees,
            'total_departments' => $totalDepartments,
            'active_requests' => $activeRequests,
            'system_notifications' => $pending,
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function buildChartData(?int $departmentId): array
    {
        $query = User::query()
            ->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'employee'");

        if ($departmentId !== null) {
            $query->where('Dept_id', $departmentId);
        }

        $employees = $query->get(['id', 'Dept_id', 'Status', 'employee_type', 'created_at', 'date_hired']);

        // workforce per department
        $deptQuery = Department::query()
            ->select('departments.Dept_name')
            ->selectRaw('COUNT(users.id) as total')
            ->leftJoin('users', function ($join) {
                $join->on('users.Dept_id', '=', 'departments.Dept_id')
                    ->whereRaw("LOWER(REPLACE(REPLACE(users.access_level, '-', ' '), '_', ' ')) = 'employee'");
            })
            ->groupBy('departments.Dept_id', 'departments.Dept_name')
            ->orderBy('departments.Dept_name');

        if ($departmentId !== null) {
            $deptQuery->where('departments.Dept_id', $departmentId);
        }

        $rows = $deptQuery->get();

        $workforcePerDepartment = [
            'labels' => $rows->pluck('Dept_name')->all(),
            'values' => $rows->pluck('total')->map(fn ($v) => (int) $v)->all(),
        ];

        // employment status counts
        $employmentStatus = [];
        foreach ($employees as $e) {
            $key = trim((string) ($e->employee_type ?? 'Unknown')) ?: 'Unknown';
            $employmentStatus[$key] = ($employmentStatus[$key] ?? 0) + 1;
        }

        // gender and age buckets via PDS
        $pdsByUser = $this->pdsByUserId($employees->pluck('id'));

        $genderCounts = ['Male' => 0, 'Female' => 0, 'Not Specified' => 0];
        $ageGroupCounts = ['18-25' => 0, '26-35' => 0, '36-45' => 0, '46-55' => 0, '56+' => 0, 'Unknown' => 0];
        $serviceCounts = ['< 1 year' => 0, '1-3 years' => 0, '4-7 years' => 0, '8-12 years' => 0, '13+ years' => 0];

        foreach ($employees as $employee) {
            $pds = $pdsByUser->get($employee->id, []);
            $gender = $this->extractGender($pds);
            $genderCounts[$gender] = ($genderCounts[$gender] ?? 0) + 1;

            $ageBucket = $this->extractAgeBucket($pds);
            $ageGroupCounts[$ageBucket] = ($ageGroupCounts[$ageBucket] ?? 0) + 1;

            if (!empty($employee->date_hired)) {
                try {
                    $years = Carbon::parse($employee->date_hired)->diffInYears(now());
                } catch (\Throwable $e) {
                    $years = 0;
                }
            } else {
                $years = 0;
            }

            $serviceBucket = $this->serviceBucket($years);
            $serviceCounts[$serviceBucket] = ($serviceCounts[$serviceBucket] ?? 0) + 1;
        }

        return [
            'workforce_per_department' => $workforcePerDepartment,
            'total_workforce' => $this->barChartFromAssoc($this->countByKey($employees, 'employee_type', 'Unspecified')),
            'gender_distribution' => $this->pieChartFromAssoc($genderCounts),
            'employment_status' => $this->pieChartFromAssoc($employmentStatus),
            'age_group_distribution' => $this->barChartFromAssoc($ageGroupCounts),
            'length_of_service' => $this->barChartFromAssoc($serviceCounts),
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, int> $userIds
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function pdsByUserId($userIds)
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        return Pds::query()
            ->whereIn('user_id', $userIds)
            ->get()
            ->mapWithKeys(function (Pds $pds) {
                return [$pds->user_id => $pds->getAllSectionData()];
            });
    }

    /** @param array<string, mixed> $pds */
    private function extractGender(array $pds): string
    {
        $personal = (array) ($pds['pds-personal-info'] ?? []);
        $sex = strtolower(trim((string) ($personal['personal[sex]'] ?? '')));

        if ($sex === 'male') return 'Male';
        if ($sex === 'female') return 'Female';
        return 'Not Specified';
    }

    /** @param array<string, mixed> $pds */
    private function extractAgeBucket(array $pds): string
    {
        $personal = (array) ($pds['pds-personal-info'] ?? []);
        $birthDate = trim((string) ($personal['personal[birth_date]'] ?? ''));
        if ($birthDate === '') return 'Unknown';

        try {
            $age = Carbon::parse($birthDate)->age;
        } catch (\Throwable $e) {
            return 'Unknown';
        }

        if ($age <= 25) return '18-25';
        if ($age <= 35) return '26-35';
        if ($age <= 45) return '36-45';
        if ($age <= 55) return '46-55';
        return '56+';
    }

    private function serviceBucket(int $yearsOfService): string
    {
        if ($yearsOfService < 1) return '< 1 year';
        if ($yearsOfService <= 3) return '1-3 years';
        if ($yearsOfService <= 7) return '4-7 years';
        if ($yearsOfService <= 12) return '8-12 years';
        return '13+ years';
    }

    /** @param \Illuminate\Support\Collection<int, \stdClass> $employees */
    private function countByKey($employees, string $key, string $fallback): array
    {
        return $employees
            ->map(function ($employee) use ($key, $fallback) {
                $value = trim((string) ($employee->{$key} ?? ''));
                return $value !== '' ? $value : $fallback;
            })
            ->countBy()
            ->sortKeys()
            ->all();
    }

    private function barChartFromAssoc(array $assoc): array
    {
        return ['labels' => array_keys($assoc), 'values' => array_values($assoc)];
    }

    private function pieChartFromAssoc(array $assoc): array
    {
        return ['labels' => array_keys($assoc), 'values' => array_values($assoc)];
    }
}
