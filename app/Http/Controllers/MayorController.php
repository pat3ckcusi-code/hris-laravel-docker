<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use App\Services\HRDashboardService;
use App\Services\LeaveDateAggregateService;
use App\Services\LeaveRequestService;
use App\Support\HrisConstants;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MayorController extends Controller
{
    private LeaveRequestService $leaveRequestService;

    public function __construct(
        LeaveRequestService $leaveRequestService,
        private HRDashboardService $dashboardService,
    ) {
        $this->leaveRequestService = $leaveRequestService;
    }

    public function dashboard(Request $request)
    {
        $departments = Department::orderBy('Dept_name')->get();

        return view('mayor.dashboard', [
            'departments' => $departments,
            'summary' => $this->dashboardService->buildWorkforceCards(),
            'chartDataUrl' => route('mayor.chart-data'),
            'initialChartData' => $this->dashboardService->buildChartData(null),
        ]);
    }

    public function getChartData(Request $request)
    {
        $departmentId = $request->integer('department');

        return response()->json($this->dashboardService->buildChartData($departmentId > 0 ? $departmentId : null));
    }

    public function getAlerts(Request $request)
    {
        $upcomingHolidays = $this->dashboardService->upcomingHolidayAlerts();

        return response()->json([
            'open_payroll' => null,
            'unresolved_exceptions' => 0,
            'upcoming_holidays' => $upcomingHolidays,
            'total_alerts' => count($upcomingHolidays),
        ]);
    }

    public function getWorkforcePlanning(Request $request)
    {
        return response()->json($this->dashboardService->buildWorkforcePlanning());
    }

    public function workforceInsights(Request $request)
    {
        return view('mayor.workforce-insights', [
            'planningDataUrl' => route('mayor.workforce.planning'),
        ]);
    }

    public function serviceMilestones(Request $request)
    {
        return view('mayor.service-milestones', [
            'planningDataUrl' => route('mayor.workforce.planning'),
        ]);
    }

    public function getEmployeesByFilter(Request $request)
    {
        $department = trim((string) $request->query('department', ''));
        $gender = trim((string) $request->query('gender', ''));
        $status = trim((string) $request->query('status', ''));
        $ageGroup = trim((string) $request->query('age_group', ''));
        $lengthOfService = trim((string) $request->query('length_of_service', ''));
        $sixtyPlus = trim((string) $request->query('sixty_plus', ''));

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
            ->whereRaw("LOWER(REPLACE(REPLACE(users.access_level, '-', ' '), '_', ' ')) = 'employee'")
            ->active();

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
        $pdsMap = $this->dashboardService->pdsByUserId($userIds);

        $employees = [];

        foreach ($rows as $row) {
            $pds = $pdsMap->get($row->id, []);
            $genderVal = $this->dashboardService->extractGender($pds);

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

            if (! empty($row->date_hired)) {
                try {
                    $yearsOfService = Carbon::parse($row->date_hired)->diffInYears(now());
                } catch (\Throwable $e) {
                    $yearsOfService = 0;
                }
            } else {
                $yearsOfService = 0;
            }
            $serviceBucket = $this->dashboardService->serviceBucket($yearsOfService);

            $ageBucket = $this->dashboardService->extractAgeBucket($pds);

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

        $filtered = collect($employees)->filter(function ($emp) use ($gender, $ageGroup, $lengthOfService, $sixtyPlus) {
            if ($gender !== '' && strcasecmp($emp['gender'], $gender) !== 0) {
                return false;
            }

            if ($ageGroup !== '' && $emp['age_bucket'] !== $ageGroup) {
                return false;
            }

            if ($lengthOfService !== '' && $emp['length_of_service'] !== $lengthOfService) {
                return false;
            }

            if ($sixtyPlus !== '' && ($emp['age'] === null || $emp['age'] < 60)) {
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
        if (! $applicant) {
            Log::warning('Mayor approveLeave: applicant not found', ['leave_id' => $id]);

            return $this->respondError($request, 'Applicant not found.');
        }

        $applicantRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($applicant->access_level ?? ''))));
        Log::info('Mayor approveLeave: applicant role check', ['leave_id' => $id, 'raw_role' => $applicant->access_level, 'normalized' => $applicantRole]);

        if (! in_array($applicantRole, ['department head', 'hr manager'])) {
            return $this->respondError($request, 'You are not authorized to approve this request. Only Department Head and HR Manager leave requests are routed to the Mayor.', 403);
        }

        try {
            $result = $this->leaveRequestService->approveLeave($request, $id);
            Log::info('Mayor approveLeave: service returned', ['leave_id' => $id, 'response_class' => get_class($result)]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Mayor approveLeave: exception', ['leave_id' => $id, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Server error: '.$e->getMessage()], 500);
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
        if (! $applicant) {
            return $this->respondError($request, 'Applicant not found.');
        }

        $applicantRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($applicant->access_level ?? ''))));
        if (! in_array($applicantRole, ['department head', 'hr manager'])) {
            return $this->respondError($request, 'You are not authorized to reject this request.', 403);
        }

        $leave->status = 'declined';
        $leave->rejection_notes = $request->input('rejection_notes');
        $leave->save();

        // If this is a reschedule request, unfreeze the original leave
        if (! empty($leave->rescheduled_from_id)) {
            app(LeaveDateAggregateService::class)
                ->unfreezeOriginalReschedule($leave->id, $leave->rescheduled_from_id);
        }

        // Send rejection notification email
        try {
            $employee = $leave->user;
            if ($employee && ! empty($employee->Dept_id)) {
                $empDept = Department::find($employee->Dept_id);
                if ($empDept) {
                    $employee->department_name = $empDept->Dept_name ?? null;
                }
            }
            $formatted = [
                'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                'end' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
            ];
            $lb = $employee ? $employee->leaveBalance : null;
            $balances = [
                'VL' => $lb->VL ?? 0,
                'SL' => $lb->SL ?? 0,
                'WLNS' => $lb->WLNS ?? 0,
                'SP' => $lb->SP ?? 0,
                'SPL' => $lb->SPL ?? 0,
                'CTO' => $lb->CTO ?? 0,
            ];
            if ($employee) {
                $email = $employee->email ?? null;
                Log::info('Mayor leave rejection email attempt', ['leave_id' => $leave->id, 'user_id' => $employee->id ?? null, 'email' => $email]);
                if (! empty($email)) {
                    $employee->notify(new HrisTransactionNotification(
                        requestType: 'Leave Request',
                        status: 'Rejected',
                        details: [
                            'Leave Type' => $leave->leave_type ?? 'N/A',
                            'Start Date' => $formatted['start'],
                            'End Date' => $formatted['end'],
                        ],
                        actor: Auth::user()->name,
                        notes: $leave->rejection_notes ?? null,
                    ));
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
                ],
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
        if (! $order) {
            return response()->json(['success' => false, 'message' => 'Travel order not found.'], 404);
        }

        $empNos = DB::table('travel_order_employees')
            ->where('travel_order_id', $order->id)
            ->pluck('emp_no')
            ->toArray();

        $employees = User::whereIn('EmpNo', $empNos)->get()->map(function ($u) {
            return [
                'emp_no' => $u->EmpNo,
                'name' => trim(($u->last_name ?? '').', '.($u->first_name ?? '')),
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
                'created_by' => $creator ? trim(($creator->last_name ?? '').', '.($creator->first_name ?? '')) : 'N/A',
                'recommender' => $recommender ? trim(($recommender->last_name ?? '').', '.($recommender->first_name ?? '')) : 'N/A',
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
        if (! $order) {
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
        if (! $order) {
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

    public function employees(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $employeeTypeFilter = trim((string) $request->query('employee_type', ''));
        $departmentFilter = trim((string) $request->query('department', ''));

        $employeesQuery = $this->realEmployeeQuery()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('middle_name');

        if ($search !== '') {
            $employeesQuery->where(function ($query) use ($search): void {
                $query
                    ->where('last_name', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('middle_name', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('EmpNo', 'like', '%'.$search.'%');
            });
        }

        if (in_array($employeeTypeFilter, HrisConstants::EMPLOYEE_TYPES, true)) {
            $employeesQuery->where('employee_type', $employeeTypeFilter);
        }

        if ($departmentFilter !== '') {
            $employeesQuery->where('Dept_id', $departmentFilter);
        }

        $employees = $employeesQuery->paginate(10)->withQueryString();

        $departments = Department::query()->orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);

        $stats = [
            'total' => $this->realEmployeeQuery()->count(),
            'permanent' => $this->realEmployeeQuery()->where('employee_type', 'Permanent')->count(),
            'co_terminus' => $this->realEmployeeQuery()->where('employee_type', 'Co-Terminus')->count(),
            'job_orders' => $this->realEmployeeQuery()->where('employee_type', 'Job Orders')->count(),
            'contractual' => $this->realEmployeeQuery()->where('employee_type', 'Contractual')->count(),
        ];

        return view('mayor.employees', [
            'user' => $request->user(),
            'employees' => $employees,
            'departments' => $departments,
            'search' => $search,
            'employeeTypeFilter' => $employeeTypeFilter,
            'employeeTypes' => HrisConstants::EMPLOYEE_TYPES,
            'departmentFilter' => $departmentFilter,
            'stats' => $stats,
        ]);
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
     * Seeded demo/role accounts (UsersTableSeeder) use an @example.com email and aren't real
     * employees — exclude them from employee counts and listings. Also exclude
     * Inactive/Separated employees, per User::scopeActive()'s null-Status-is-active convention.
     */
    private function realEmployeeQuery(): Builder
    {
        return User::query()->active()->where('email', 'not like', '%@example.com');
    }
}
