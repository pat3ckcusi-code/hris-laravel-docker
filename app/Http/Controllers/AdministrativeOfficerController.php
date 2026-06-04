<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Department;
use App\Models\User;
use App\Models\LeaveRequest;
use App\Models\Eta;
use App\Models\Locator;
use App\Models\TravelOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\RedirectResponse;
use Carbon\Carbon;
use App\Services\DepartmentService;
use App\Services\DepartmentHeadService;
use App\Services\LeaveRequestService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use App\Models\HRAuditTrail;
use App\Mail\LeaveRequestStatusNotification;
use App\Mail\ApplicationStatusNotification;
use App\Notifications\HrisTransactionNotification;

class AdministrativeOfficerController extends Controller
{
    private DepartmentService $departmentService;
    private DepartmentHeadService $departmentHeadService;
    private LeaveRequestService $leaveRequestService;

    public function __construct(DepartmentService $departmentService, DepartmentHeadService $departmentHeadService, LeaveRequestService $leaveRequestService)
    {
        $this->departmentService = $departmentService;
        $this->departmentHeadService = $departmentHeadService;
        $this->leaveRequestService = $leaveRequestService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);

        $pending = 0;
        $approved = 0;
        $total = 0;
        $pendingCount = 0;

        if ($dept) {
            $employeeIds = $this->departmentService->getEmployeeIdsForDepartment($dept);

            // Exclude leave requests filed by Department Heads (Mayor handles those)
            $excludeDeptHead = fn ($q) => $q->whereHas('user', fn ($u) => $u->whereRaw(
                "LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"
            ));

            $total = LeaveRequest::whereIn('user_id', $employeeIds)->where($excludeDeptHead)->count();
            $pending = LeaveRequest::whereIn('user_id', $employeeIds)->where('status', 'pending')->where($excludeDeptHead)->count();
            $approved = LeaveRequest::whereIn('user_id', $employeeIds)->where('status', 'approved')->where($excludeDeptHead)->count();

            // Combined pending across leave, ETA and Locator for summary badge
            $etaPending = Eta::whereIn('user_id', $employeeIds)->where('status', 'pending')->count();
            $locatorPending = Locator::whereIn('user_id', $employeeIds)->where('status', 'pending')->count();
            $pendingCount = $pending + $etaPending + $locatorPending;
        }

        return view('administrative-officer.index', compact('user', 'dept', 'pending', 'approved', 'total', 'pendingCount'));
    }

    /**
     * Return combined pending requests count for this administrative officer's department.
     */
    public function getPendingCount(Request $request)
    {
        $user = $request->user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        if (!$dept) return response()->json(['success' => true, 'pending' => 0]);

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartment($dept);

        // Exclude leave requests filed by Department Heads (Mayor handles those)
        $leavePending = LeaveRequest::whereIn('user_id', $employeeIds)->where('status', 'pending')
            ->whereHas('user', fn ($u) => $u->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"))
            ->count();
        $etaPending = Eta::whereIn('user_id', $employeeIds)->where('status', 'pending')->count();
        $locatorPending = Locator::whereIn('user_id', $employeeIds)->where('status', 'pending')->count();

        $total = $leavePending + $etaPending + $locatorPending;
        return response()->json(['success' => true, 'pending' => (int) $total]);
    }

    public function pendingRequests(Request $request)
    {
        $user = $request->user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $requests = collect();
        $etaRequests = collect();
        $locatorRequests = collect();

        $month = (int) $request->query('month', (int) date('n'));
        $year  = (int) $request->query('year',  (int) date('Y'));
        if ($month < 1 || $month > 12) $month = (int) date('n');
        if ($year < 2000 || $year > 2100) $year = (int) date('Y');

        if ($dept) {
            $employeeIds = $this->departmentService->getEmployeeIdsForDepartment($dept);
            $requests = LeaveRequest::with('user')
                ->whereIn('user_id', $employeeIds)
                ->where('status', 'pending')
                ->whereHas('user', fn ($u) => $u->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"))
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->orderBy('created_at', 'desc')
                ->paginate(10);
            $etaRequests = Eta::with('user')
                ->whereIn('user_id', $employeeIds)
                ->where('status', 'pending')
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->orderBy('created_at', 'desc')
                ->paginate(10, ['*'], 'eta_page');
            $locatorRequests = Locator::with('user')
                ->whereIn('user_id', $employeeIds)
                ->where('status', 'pending')
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->orderBy('created_at', 'desc')
                ->paginate(10, ['*'], 'locator_page');
        }
        return view('department-head.pending-requests', compact('dept', 'requests', 'etaRequests', 'locatorRequests', 'month', 'year'));
    }

    /**
     * Allow printing for a pending leave request (pre-approval) - AO variant.
     */
    public function allowPrinting(Request $request, $id)
    {
        $user = Auth::user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $leave = LeaveRequest::findOrFail($id);

        if (!$dept) {
            return response()->json(['error' => 'Department not found for your account.'], 403);
        }

        $employee = $leave->user;
        if (!$employee || $employee->Dept_id != $dept->Dept_id) {
            return response()->json(['error' => 'You are not authorized to perform this action.'], 403);
        }

        if ($leave->printing_allowed) {
            return response()->json(['success' => true, 'message' => 'Printing already allowed.']);
        }

        $leave->printing_allowed = true;
        $leave->printing_allowed_by = $user->id;
        $leave->printing_allowed_at = now();
        $leave->save();

        HRAuditTrail::create([
            'actor_user_id' => $user->id,
            'module' => 'leave',
            'action' => 'allow_printing',
            'target_type' => 'leave_request',
            'target_id' => $leave->id,
            'details' => [
                'leave_id' => $leave->id,
                'approver_id' => $user->id,
                'approver_role' => $user->access_level ?? null,
                'status' => $leave->status,
                'timestamp' => now()->toDateTimeString(),
            ],
        ]);

        // Prefer an existing per-type preview (saved at filing). Only keep deductible types (VL, SL).
        $deductionPreview = [];
        if (!empty($leave->printing_deduction_details)) {
            try {
                $existing = json_decode($leave->printing_deduction_details, true) ?: [];
            } catch (\Exception $e) {
                $existing = [];
            }
            foreach (['VL', 'SL', 'WLNS', 'SPL', 'CTO', 'SP'] as $k) {
                if (isset($existing[$k]) && is_numeric($existing[$k]) && floatval($existing[$k]) > 0) {
                    $deductionPreview[$k] = floatval($existing[$k]);
                }
            }
        }

        // Fallback: derive a simple VL/SL preview from the leave_type and paid_days (no other types)
        if (empty($deductionPreview)) {
            $toDeduct = floatval($leave->paid_days ?? 0);
            if ($toDeduct > 0) {
                $label = strtolower($leave->leave_type ?? '');
                if (str_contains($label, 'vacation') || str_contains($label, 'vl')) $deductionPreview = ['VL' => $toDeduct];
                elseif (str_contains($label, 'sick') || str_contains($label, 'sl')) $deductionPreview = ['SL' => $toDeduct];
                elseif (str_contains($label, 'wellness') || str_contains($label, 'wlns')) $deductionPreview = ['WLNS' => $toDeduct];
                elseif (str_contains($label, 'special') || str_contains($label, 'spl') || str_contains($label, 'privilege')) $deductionPreview = ['SPL' => $toDeduct];
                elseif (str_contains($label, 'solo') || str_contains($label, 'solo parent')) $deductionPreview = ['SP' => $toDeduct];
                elseif (str_contains($label, 'cto')) $deductionPreview = ['CTO' => $toDeduct];
            }
        }

        Log::info('Printing allowed for leave (AO)', [
            'leave_id' => $leave->id,
            'employee_id' => $employee->id ?? null,
            'allowed_by' => $user->id ?? null,
            'role' => $user->access_level ?? null,
            'printing_deduction_preview' => $deductionPreview,
            'timestamp' => now()->toDateTimeString(),
        ]);

        if (!empty($deductionPreview) && Schema::hasColumn('leave_requests', 'printing_deduction_details')) {
            try {
                $leave->printing_deduction_details = json_encode($deductionPreview);
                if (Schema::hasColumn('leave_requests', 'printing_deduction_applied')) {
                    $leave->printing_deduction_applied = false;
                }
                $leave->save();
            } catch (\Exception $ex) {
                Log::error('Failed to save printing_deduction_details (AO preview)', ['leave_id' => $leave->id, 'error' => $ex->getMessage()]);
            }
        }

        try {
            if ($employee) {
                $employee->notify(new HrisTransactionNotification(
                    requestType: 'Leave Request',
                    status: 'Printing Allowed',
                    details: [
                        'Leave Type' => $leave->leave_type ?? 'N/A',
                        'Date Filed' => $leave->created_at ? Carbon::parse($leave->created_at)->format('l, F j, Y') : 'N/A',
                    ],
                    actor: Auth::user()->name,
                ));
            }
        } catch (\Exception $ex) {}

        return response()->json(['success' => true]);
    }

    public function approvedRequests(Request $request)
    {
        $user = $request->user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $requests = collect();
        $etaRequests = collect();
        $locatorRequests = collect();

        $month = (int) $request->query('month', (int) date('n'));
        $year  = (int) $request->query('year',  (int) date('Y'));
        if ($month < 1 || $month > 12) $month = (int) date('n');
        if ($year < 2000 || $year > 2100) $year = (int) date('Y');

        if ($dept) {
            $employeeIds = $this->departmentService->getEmployeeIdsForDepartment($dept);
            $requests = LeaveRequest::with('user')
                ->whereIn('user_id', $employeeIds)
                ->where('status', 'approved')
                ->whereHas('user', fn ($u) => $u->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"))
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            $etaRequests = Eta::with('user')
                ->whereIn('user_id', $employeeIds)
                ->where('status', 'approved')
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->orderBy('created_at', 'desc')
                ->paginate(10, ['*'], 'eta_page');

            $locatorRequests = Locator::with('user')
                ->whereIn('user_id', $employeeIds)
                ->where('status', 'approved')
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->orderBy('created_at', 'desc')
                ->paginate(10, ['*'], 'locator_page');
        }

        return view('department-head.approved-requests', compact('dept', 'requests', 'etaRequests', 'locatorRequests', 'month', 'year'));
    }

    public function statistics(Request $request)
    {
        $user = $request->user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $stats = [];
        if ($dept) {
            $employeeIds = $this->departmentService->getEmployeeIdsForDepartment($dept);
            $excludeDeptHead = fn ($q) => $q->whereHas('user', fn ($u) => $u->whereRaw(
                "LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"
            ));
            $stats['total_requests'] = LeaveRequest::whereIn('user_id', $employeeIds)->where($excludeDeptHead)->count();
            $stats['pending'] = LeaveRequest::whereIn('user_id', $employeeIds)->where('status', 'pending')->where($excludeDeptHead)->count();
            $stats['approved'] = LeaveRequest::whereIn('user_id', $employeeIds)->where('status', 'approved')->where($excludeDeptHead)->count();
            $stats['by_type'] = LeaveRequest::whereIn('user_id', $employeeIds)->where($excludeDeptHead)
                ->select('leave_type', DB::raw('count(*) as cnt'))
                ->groupBy('leave_type')
                ->orderByDesc('cnt')
                ->get();
        }

        return view('department-head.statistics', compact('dept', 'stats'));
    }

    public function statisticsData(Request $request)
    {
        $user = $request->user();
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));

        $dept = $this->departmentService->resolveDepartmentForUser($user);

        if (!$dept) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $cacheKey = "ao_stats_{$dept->Dept_id}_{$month}_{$year}";
        $rows = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($dept, $month, $year) {
            $employees = User::where('Dept_id', $dept->Dept_id)->get();
            $employeeIds = $employees->pluck('id')->toArray();

            $etaCounts = Eta::selectRaw('user_id, COUNT(*) as cnt')
                ->whereIn('user_id', $employeeIds)
                ->where('status', 'approved')
                ->whereMonth('departure_date', $month)
                ->whereYear('departure_date', $year)
                ->groupBy('user_id')
                ->pluck('cnt', 'user_id');

            $locatorCounts = Locator::selectRaw('user_id, COUNT(*) as cnt')
                ->whereIn('user_id', $employeeIds)
                ->where('status', 'approved')
                ->whereMonth('travel_date', $month)
                ->whereYear('travel_date', $year)
                ->groupBy('user_id')
                ->pluck('cnt', 'user_id');

            $leaveCounts = LeaveRequest::selectRaw('user_id, COUNT(*) as cnt')
                ->whereIn('user_id', $employeeIds)
                ->where('status', 'approved')
                ->whereMonth('start_date', $month)
                ->whereYear('start_date', $year)
                ->groupBy('user_id')
                ->pluck('cnt', 'user_id');

            $rows = [];
            foreach ($employees as $emp) {
                $etaCount = $etaCounts->get($emp->id, 0);
                $locatorCount = $locatorCounts->get($emp->id, 0);
                $leaveCount = $leaveCounts->get($emp->id, 0);

                $rows[] = [
                    'EmpNo' => $emp->EmpNo ?? '',
                    'Lname' => $emp->last_name ?? '',
                    'Fname' => $emp->first_name ?? '',
                    'Mname' => $emp->middle_name ?? '',
                    'Extension' => property_exists($emp, 'extension') ? ($emp->extension ?? '') : '',
                    'Dept' => $dept->Dept_name ?? '',
                    'eta_count' => $etaCount,
                    'locator_count' => $locatorCount,
                    'leave_count' => $leaveCount,
                    'total_usage' => ($etaCount + $locatorCount + $leaveCount),
                ];
            }

            return $rows;
        });

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function statisticsDetails(Request $request)
    {
        $empNo = $request->query('empNo');
        $type = $request->query('type');
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));

        if (!$empNo || !$type) {
            return response()->json(['success' => false, 'data' => []]);
        }

        $user = User::where('EmpNo', $empNo)->first();
        if (!$user) {
            return response()->json(['success' => true, 'data' => []]);
        }

        if (strtoupper($type) === 'ETA') {
            $records = Eta::where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereMonth('departure_date', $month)
                ->whereYear('departure_date', $year)
                ->get()
                ->map(function ($r) {
                    return [
                        'travel_date' => $r->departure_date,
                        'business_type' => $r->purpose ?? '',
                        'destination' => $r->destination ?? '',
                        'travel_detail' => $r->purpose_details ?? '',
                    ];
                })->values();

            return response()->json(['success' => true, 'data' => $records]);
        }

        if (strtoupper($type) === 'LEAVE') {
            $records = LeaveRequest::where('user_id', $user->id)
                ->where('status', 'approved')
                ->whereMonth('start_date', $month)
                ->whereYear('start_date', $year)
                ->get()
                ->map(function ($r) {
                    return [
                        'start_date' => $r->start_date,
                        'end_date' => $r->end_date ?? '',
                        'leave_type' => $r->leave_type ?? '',
                        'total_days' => $r->total_days ?? '',
                        'reason' => $r->reason ?? '',
                    ];
                })->values();

            return response()->json(['success' => true, 'data' => $records]);
        }

        // Locator
        $records = Locator::where('user_id', $user->id)
            ->where('status', 'approved')
            ->whereMonth('travel_date', $month)
            ->whereYear('travel_date', $year)
            ->get()
            ->map(function ($r) {
                return [
                    'travel_date' => $r->travel_date,
                    'intended_departure' => $r->intended_departure_time ?? '',
                    'intended_arrival' => $r->intended_arrival_time ?? '',
                    'destination' => $r->location ?? '',
                    'business_type' => $r->application_type ?? '',
                    'travel_detail' => $r->detail ?? '',
                    'Arrival_Time' => $r->actual_arrival_time ?? '',
                ];
            })->values();

        return response()->json(['success' => true, 'data' => $records]);
    }

    public function travelOrders(Request $request)
    {
        return view('department-head.travel-orders');
    }

    public function dashboardMetrics(Request $request)
    {
        $user = $request->user();
        $data = $this->departmentHeadService->dashboardMetrics($user);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function employeesOnDuty(Request $request)
    {
        $user = $request->user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);

        if (!$dept) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $employees = User::where('Dept_id', $dept->Dept_id)->get()->map(function ($u) {
            return [
                'EmpNo' => $u->EmpNo ?? ($u->id ?? ''),
                'name' => trim(($u->last_name ?? '') . ', ' . ($u->first_name ?? '')),
                'position' => $u->position ?? '',
                'status' => 'In Office',
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $employees]);
    }

    public function leaveRequestsList(Request $request)
    {
        $user = $request->user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);

        if (!$dept) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartment($dept);

        $rows = LeaveRequest::with('user')
            ->whereIn('user_id', $employeeIds)
            ->whereHas('user', fn ($u) => $u->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"))
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'emp' => $r->user ? ($r->user->last_name . ', ' . $r->user->first_name) : '',
                    'type' => $r->leave_type ?? '',
                    'start' => $r->start_date ?? '',
                    'end' => $r->end_date ?? '',
                    'status' => $r->status ?? '',
                    'created_at' => $r->created_at->toDateTimeString(),
                ];
            })->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function locatorRequestsList(Request $request)
    {
        $user = $request->user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);

        if (!$dept) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartment($dept);

        $rows = Locator::with('user')
            ->whereIn('user_id', $employeeIds)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'emp' => $r->user ? ($r->user->last_name . ', ' . $r->user->first_name) : '',
                    'date' => $r->travel_date ?? '',
                    'location' => $r->location ?? '',
                    'status' => $r->status ?? '',
                    'created_at' => $r->created_at->toDateTimeString(),
                ];
            })->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function etaRequestsList(Request $request)
    {
        $user = $request->user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);

        if (!$dept) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartment($dept);

        $rows = Eta::with('user')
            ->whereIn('user_id', $employeeIds)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'emp' => $r->user ? ($r->user->last_name . ', ' . $r->user->first_name) : '',
                    'departure' => $r->departure_date ?? '',
                    'destination' => $r->destination ?? '',
                    'status' => $r->status ?? '',
                    'created_at' => $r->created_at->toDateTimeString(),
                ];
            })->values();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function officeOrders(Request $request)
    {
        return view('department-head.office-orders');
    }

    public function filedTravelOrders(Request $request)
    {
        return view('department-head.filed-travel-orders');
    }

    public function showTravelOrder(Request $request, $id)
    {
        $user = $request->user();
        $order = TravelOrder::find($id);
        if (!$order) return redirect()->back()->with('error', 'Travel order not found.');

        $empNos = DB::table('travel_order_employees')->where('travel_order_id', $order->id)->pluck('emp_no')->toArray();
        $employees = User::whereIn('EmpNo', $empNos)->get();

        return view('department-head.travel-order-show', compact('order', 'employees'));
    }

    public function filedOfficeOrders(Request $request)
    {
        return view('department-head.filed-office-orders');
    }

    public function approve(Request $request, $id)
    {
        $user = Auth::user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $leave = LeaveRequest::findOrFail($id);

        if (!$dept) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $leave->user;
        if (!$employee || $employee->Dept_id != $dept->Dept_id) {
            return redirect()->back()->with('error', 'You are not authorized to approve this request.');
        }

        return $this->leaveRequestService->approveLeave($request, $id);
    }

    public function approveEta(Request $request, $id)
    {
        $user = Auth::user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $eta = Eta::findOrFail($id);

        if (!$dept) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $eta->user;
        if (!$employee || $employee->Dept_id != $dept->Dept_id) {
            return redirect()->back()->with('error', 'You are not authorized to approve this request.');
        }

        if ($eta->status === 'approved') {
            return redirect()->back()->with('success', 'ETA already approved.');
        }

        // Get normalized role for audit logging
        $normalizedRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($user->access_level ?? ''))));

        $eta->status = 'approved';
        $eta->approved_by = $user->id;
        $eta->approved_role = $normalizedRole;
        $eta->approved_at = now();
        $eta->save();

        Log::info('ETA approved by administrative officer', [
            'eta_id' => $eta->id,
            'approver_id' => $user->id,
            'approver_name' => $user->name,
            'approver_access_level' => $user->access_level,
            'approver_normalized_role' => $normalizedRole,
            'employee_id' => $employee->id,
            'employee_dept_id' => $employee->Dept_id,
        ]);
        try {
            HRAuditTrail::create([
                'actor_user_id' => $user->id,
                'module' => 'eta',
                'action' => 'approve',
                'target_type' => 'eta',
                'target_id' => $eta->id,
                'details' => [
                    'purpose' => $eta->purpose ?? '',
                    'purpose_details' => $eta->purpose_details ?? '',
                    'approver_normalized_role' => $normalizedRole,
                    'approver_id' => $user->id,
                    'employee_id' => $employee->id ?? null,
                    'timestamp' => now()->toDateTimeString(),
                ],
            ]);
        } catch (\Exception $ex) {
            Log::error('Failed to write HRAuditTrail for ETA approval (AO)', ['eta_id' => $eta->id, 'error' => $ex->getMessage()]);
        }

        try {
            $employee = $eta->user;
            if ($employee) {
                $department = null;
                if (!empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    $employee->department_name = $department->Dept_name ?? null;
                }
                $formatted = [
                    'departure' => Carbon::parse($eta->departure_date)->format('l, F j, Y'),
                    'arrival' => Carbon::parse($eta->arrival_date)->format('l, F j, Y'),
                ];
                $email = $employee->email ?? null;
                Log::info('ETA approval email attempt (AO)', ['eta_id' => $eta->id, 'user_id' => $employee->id ?? null, 'email' => $email]);
                if (!empty($email)) {
                    $employee->notify(new HrisTransactionNotification(
                        requestType: 'ETA',
                        status: 'Approved',
                        details: [
                            'Destination'    => $eta->destination ?? 'N/A',
                            'Departure Date' => $formatted['departure'],
                            'Arrival Date'   => $formatted['arrival'],
                            'Purpose'        => $eta->purpose ?? 'N/A',
                        ],
                        actor: Auth::user()->name,
                    ));
                    Log::info('ETA approval email queued (AO)', ['eta_id' => $eta->id, 'email' => $email]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error sending ETA approval email (AO)', ['eta_id' => $eta->id, 'error' => $e->getMessage()]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'ETA approved.']);
        }

        return redirect()->back()->with('success', 'ETA approved.');
    }

    public function rejectEta(Request $request, $id)
    {
        $user = Auth::user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $eta = Eta::findOrFail($id);

        if (!$dept) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $eta->user;
        if (!$employee || $employee->Dept_id != $dept->Dept_id) {
            return redirect()->back()->with('error', 'You are not authorized to reject this request.');
        }

        $eta->status = 'declined';
        $eta->approved_by = $user->id;
        $eta->approved_role = strtolower(str_replace(['-', '_'], ' ', trim((string) ($user->access_level ?? ''))));
        $eta->approved_at = now();
        $eta->save();

        try {
            HRAuditTrail::create([
                'actor_user_id' => $user->id,
                'module' => 'eta',
                'action' => 'reject',
                'target_type' => 'eta',
                'target_id' => $eta->id,
                'details' => [
                    'purpose' => $eta->purpose ?? '',
                    'purpose_details' => $eta->purpose_details ?? '',
                    'approver_normalized_role' => $eta->approved_role ?? null,
                    'approver_id' => $user->id,
                    'employee_id' => $employee->id ?? null,
                    'timestamp' => now()->toDateTimeString(),
                ],
            ]);
        } catch (\Exception $ex) {
            Log::error('Failed to write HRAuditTrail for ETA rejection (AO)', ['eta_id' => $eta->id, 'error' => $ex->getMessage()]);
        }

        try {
            $employee = $eta->user;
            if ($employee) {
                $department = null;
                if (!empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    $employee->department_name = $department->Dept_name ?? null;
                }
                $formatted = [
                    'departure' => Carbon::parse($eta->departure_date)->format('l, F j, Y'),
                    'arrival' => Carbon::parse($eta->arrival_date)->format('l, F j, Y'),
                ];
                $email = $employee->email ?? null;
                if (!empty($email)) {
                    $employee->notify(new HrisTransactionNotification(
                        requestType: 'ETA',
                        status: 'Rejected',
                        details: [
                            'Destination'    => $eta->destination ?? 'N/A',
                            'Departure Date' => $formatted['departure'],
                            'Arrival Date'   => $formatted['arrival'],
                            'Purpose'        => $eta->purpose ?? 'N/A',
                        ],
                        actor: Auth::user()->name,
                    ));
                }
            }
        } catch (\Exception $e) {
            Log::error('Error sending ETA rejection email (AO)', ['eta_id' => $eta->id, 'error' => $e->getMessage()]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'ETA request rejected.']);
        }

        return redirect()->back()->with('success', 'ETA request rejected.');
    }

    public function approveLocator(Request $request, $id)
    {
        $user = Auth::user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $locator = Locator::findOrFail($id);

        if (!$dept) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $locator->user;
        if (!$employee || $employee->Dept_id != $dept->Dept_id) {
            return redirect()->back()->with('error', 'You are not authorized to approve this request.');
        }

        if ($locator->status === 'approved') {
            return redirect()->back()->with('success', 'Locator already approved.');
        }

        $locator->status = 'approved';
        $locator->save();

        try {
            HRAuditTrail::create([
                'actor_user_id' => $user->id,
                'module' => 'locator',
                'action' => 'approve',
                'target_type' => 'locator',
                'target_id' => $locator->id,
                'details' => [
                    'purpose_of_travel' => $locator->detail ?? '',
                    'application_type' => $locator->application_type ?? '',
                    'approver_id' => $user->id,
                    'employee_id' => $employee->id ?? null,
                    'timestamp' => now()->toDateTimeString(),
                ],
            ]);
        } catch (\Exception $ex) {
            Log::error('Failed to write HRAuditTrail for Locator approval (AO)', ['locator_id' => $locator->id, 'error' => $ex->getMessage()]);
        }

        try {
            $employee = $locator->user;
            if ($employee) {
                $department = null;
                if (!empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    $employee->department_name = $department->Dept_name ?? null;
                }
                $appType = 'Locator';
                if (!empty($locator->application_type)) {
                    $appType = 'Locator - ' . ucfirst($locator->application_type);
                }
                $formatted = [
                    'travel' => Carbon::parse($locator->travel_date)->format('l, F j, Y'),
                    'departure_time_24' => Carbon::parse($locator->intended_departure_time)->format('H:i'),
                    'departure_time_ampm' => Carbon::parse($locator->intended_departure_time)->format('h:i A'),
                    'arrival_time_24' => Carbon::parse($locator->intended_arrival_time)->format('H:i'),
                    'arrival_time_ampm' => Carbon::parse($locator->intended_arrival_time)->format('h:i A'),
                ];
                $email = $employee->email ?? null;
                if (!empty($email)) {
                    $employee->notify(new HrisTransactionNotification(
                        requestType: $appType,
                        status: 'Approved',
                        details: [
                            'Location'       => $locator->location ?? 'N/A',
                            'Travel Date'    => $formatted['travel'],
                            'Departure Time' => $formatted['departure_time_ampm'],
                            'Arrival Time'   => $formatted['arrival_time_ampm'],
                            'Detail'         => $locator->detail ?? 'N/A',
                        ],
                        actor: Auth::user()->name,
                    ));
                }
            }
        } catch (\Exception $e) {
            Log::error('Error sending locator approval email (AO)', ['locator_id' => $locator->id, 'error' => $e->getMessage()]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Locator approved.']);
        }

        return redirect()->back()->with('success', 'Locator approved.');
    }

    public function rejectLocator(Request $request, $id)
    {
        $user = Auth::user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $locator = Locator::findOrFail($id);

        if (!$dept) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $locator->user;
        if (!$employee || $employee->Dept_id != $dept->Dept_id) {
            return redirect()->back()->with('error', 'You are not authorized to reject this request.');
        }

        $locator->status = 'declined';
        $locator->save();

        try {
            HRAuditTrail::create([
                'actor_user_id' => $user->id,
                'module' => 'locator',
                'action' => 'reject',
                'target_type' => 'locator',
                'target_id' => $locator->id,
                'details' => [
                    'purpose_of_travel' => $locator->detail ?? '',
                    'application_type' => $locator->application_type ?? '',
                    'approver_id' => $user->id,
                    'employee_id' => $employee->id ?? null,
                    'timestamp' => now()->toDateTimeString(),
                ],
            ]);
        } catch (\Exception $ex) {
            Log::error('Failed to write HRAuditTrail for Locator rejection (AO)', ['locator_id' => $locator->id, 'error' => $ex->getMessage()]);
        }

        try {
            $employee = $locator->user;
            if ($employee) {
                $department = null;
                if (!empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    $employee->department_name = $department->Dept_name ?? null;
                }
                $appType = 'Locator';
                if (!empty($locator->application_type)) {
                    $appType = 'Locator - ' . ucfirst($locator->application_type);
                }
                $formatted = [
                    'travel' => Carbon::parse($locator->travel_date)->format('l, F j, Y'),
                    'departure_time_24' => Carbon::parse($locator->intended_departure_time)->format('H:i'),
                    'departure_time_ampm' => Carbon::parse($locator->intended_departure_time)->format('h:i A'),
                    'arrival_time_24' => Carbon::parse($locator->intended_arrival_time)->format('H:i'),
                    'arrival_time_ampm' => Carbon::parse($locator->intended_arrival_time)->format('h:i A'),
                ];
                $email = $employee->email ?? null;
                if (!empty($email)) {
                    $employee->notify(new HrisTransactionNotification(
                        requestType: $appType,
                        status: 'Rejected',
                        details: [
                            'Location'       => $locator->location ?? 'N/A',
                            'Travel Date'    => $formatted['travel'],
                            'Departure Time' => $formatted['departure_time_ampm'],
                            'Arrival Time'   => $formatted['arrival_time_ampm'],
                            'Detail'         => $locator->detail ?? 'N/A',
                        ],
                        actor: Auth::user()->name,
                    ));
                }
            }
        } catch (\Exception $e) {
            Log::error('Error sending locator rejection email (AO)', ['locator_id' => $locator->id, 'error' => $e->getMessage()]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'message' => 'Locator request rejected.']);
        }

        return redirect()->back()->with('success', 'Locator request rejected.');
    }

    public function reject(Request $request, $id)
    {
        $request->validate([
            'rejection_notes' => ['required', 'string', 'max:2000'],
        ]);

        $user = Auth::user();
        $dept = $this->departmentService->resolveDepartmentForUser($user);
        $leave = LeaveRequest::findOrFail($id);

        if (!$dept) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'swal' => ['icon' => 'error', 'title' => 'Department not found', 'text' => 'Department not found for your account.']], 422);
            }
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $leave->user;
        if (!$employee || $employee->Dept_id != $dept->Dept_id) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'swal' => ['icon' => 'error', 'title' => 'Unauthorized', 'text' => 'You are not authorized to reject this request.']], 403);
            }
            return redirect()->back()->with('error', 'You are not authorized to reject this request.');
        }

        // If printing deduction applied earlier, restore credits
        if (!empty($leave->printing_deduction_applied) && !empty($leave->printing_deduction_details)) {
            try {
                $details = json_decode($leave->printing_deduction_details, true) ?: [];
                DB::transaction(function () use ($details, $leave) {
                    $employee = $leave->user;
                    if (!$employee) return;
                    $leaveBalance = $employee->leaveBalance;
                    if (!$leaveBalance) return;
                    foreach ($details as $col => $amt) {
                        if (!is_numeric($amt) || $amt <= 0) continue;
                        $key = strtoupper((string)$col);
                        $candidates = [
                            'VL' => ['balance_vacation_leave','vl','VL'],
                            'SL' => ['balance_sick_leave','sl','SL'],
                            'WLNS' => ['balance_wellness_leave','wlns','WLNS'],
                            'SPL' => ['balance_special_leave_privilege','spl','SPL'],
                            'CTO' => ['balance_cto','cto','CTO'],
                            'SP' => ['balance_solo_parent_leave','sp','SP'],
                        ];
                        $found = null;
                        foreach ($candidates[$key] ?? [strtolower($key), strtoupper($key)] as $cand) {
                            if (array_key_exists($cand, $leaveBalance->getAttributes()) || isset($leaveBalance->{$cand})) {
                                $found = $cand; break;
                            }
                        }
                        if ($found) {
                            $leaveBalance->{$found} = floatval($leaveBalance->{$found} ?? 0) + floatval($amt);
                        }
                    }
                    $leaveBalance->save();
                    // clear printing flags
                    $leave->printing_allowed = false;
                    if (Schema::hasColumn('leave_requests', 'printing_deduction_applied')) {
                        $leave->printing_deduction_applied = false;
                    }
                    if (Schema::hasColumn('leave_requests', 'printing_deduction_details')) {
                        $leave->printing_deduction_details = null;
                    }
                    // do NOT write leave balance columns into leave_requests here; keep balances in leave_balances table only
                    $leave->save();
                });
                Log::info('Printing deduction restored due to AO rejection', [
                    'leave_id' => $leave->id,
                    'restored_by' => auth()->id(),
                    'details' => $leave->printing_deduction_details,
                    'timestamp' => now()->toDateTimeString(),
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to restore printing deduction on AO rejection', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
            }
        }

        $leave->status = 'declined';
        $leave->rejection_notes = $request->input('rejection_notes');
        $leave->save();

        try {
            $employee = $leave->user;
            if ($employee && !empty($employee->Dept_id)) {
                $empDept = Department::find($employee->Dept_id);
                if ($empDept) $employee->department_name = $empDept->Dept_name ?? null;
            }
            if ($employee) {
                $employee->notify(new HrisTransactionNotification(
                    requestType: 'Leave Request',
                    status: 'Rejected',
                    details: [
                        'Leave Type' => $leave->leave_type ?? 'N/A',
                        'Start Date' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                        'End Date'   => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                    ],
                    actor: Auth::user()->name,
                    notes: $leave->rejection_notes ?? null,
                ));
            }
        } catch (\Exception $e) {
            Log::error('Error sending leave rejection email (AO)', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
        }

        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['success' => true, 'swal' => ['icon' => 'success', 'title' => 'Rejected', 'text' => 'Leave request has been rejected.']]);
        }

        return redirect()->back()->with('success', 'Leave request rejected.');
    }
}
