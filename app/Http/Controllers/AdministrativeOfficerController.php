<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Eta;
use App\Models\HRAuditTrail;
use App\Models\LeaveRequest;
use App\Models\Locator;
use App\Models\TravelOrder;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use App\Services\ApprovalNotificationService;
use App\Services\AttendanceMonitoringExportService;
use App\Services\DepartmentHeadService;
use App\Services\DepartmentService;
use App\Services\LeaveRequestService;
use App\Support\LeaveTypeResolver;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AdministrativeOfficerController extends Controller
{
    private DepartmentService $departmentService;

    private DepartmentHeadService $departmentHeadService;

    private LeaveRequestService $leaveRequestService;

    private ApprovalNotificationService $approvalNotificationService;

    private AttendanceMonitoringExportService $monitoringExportService;

    public function __construct(
        DepartmentService $departmentService,
        DepartmentHeadService $departmentHeadService,
        LeaveRequestService $leaveRequestService,
        ApprovalNotificationService $approvalNotificationService,
        AttendanceMonitoringExportService $monitoringExportService,
    ) {
        $this->departmentService = $departmentService;
        $this->departmentHeadService = $departmentHeadService;
        $this->leaveRequestService = $leaveRequestService;
        $this->approvalNotificationService = $approvalNotificationService;
        $this->monitoringExportService = $monitoringExportService;
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $dept = $depts->first();

        $pending = 0;
        $approved = 0;
        $total = 0;
        $pendingCount = 0;

        if ($depts->isNotEmpty()) {
            $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);

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
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        if ($depts->isEmpty()) {
            return response()->json(['success' => true, 'pending' => 0]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);

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
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $dept = $depts->first();

        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $leaveDataUrl = route('admin-officer.pending-requests.leave-data');
        $etaDataUrl = route('admin-officer.pending-requests.eta-data');
        $locatorDataUrl = route('admin-officer.pending-requests.locator-data');
        $approverPrefix = 'admin-officer';

        return view('department-head.pending-requests', compact('dept', 'month', 'year', 'leaveDataUrl', 'etaDataUrl', 'locatorDataUrl', 'approverPrefix'));
    }

    public function pendingRequestsLeaveData(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $query = LeaveRequest::with('user')
            ->whereIn('user_id', $employeeIds)
            ->where('status', 'pending')
            ->whereHas('user', fn ($u) => $u->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"))
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year);

        $recordsTotal = $query->count();

        $search = trim($request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhere('leave_type', 'like', "%{$search}%")
                    ->orWhereRaw("DATE_FORMAT(start_date, '%b %d, %Y') LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("DATE_FORMAT(end_date, '%b %d, %Y') LIKE ?", ["%{$search}%"]);
            });
        }

        $recordsFiltered = $query->count();
        $start = max(0, $request->integer('start', 0));
        $length = min(100, max(1, $request->integer('length', 10)));

        $records = $query->with('lastPrintedBy')->orderBy('created_at', 'desc')->skip($start)->take($length)->get();

        $data = $records->map(function ($r) {
            $leaveTypeLabel = $r->leave_type ?? '';
            $isWellness = stripos((string) $leaveTypeLabel, 'wlns') !== false || stripos((string) $leaveTypeLabel, 'wellness') !== false;
            $reason = $isWellness ? 'Wellness' : ($r->reason ?? '-');

            return [
                'id' => $r->id,
                'employee' => $r->user->name ?? '-',
                'leave_type' => $r->leave_type,
                'reason' => $reason,
                'period' => ($r->start_date ? Carbon::parse($r->start_date)->format('M d, Y') : '-').' to '.($r->end_date ? Carbon::parse($r->end_date)->format('M d, Y') : '-'),
                'total_days' => $r->total_days ?? '-',
                'filed_at' => $r->created_at ? $r->created_at->format('M d, Y') : '-',
                'status'               => $r->status,
                'printing_allowed'     => (bool) $r->printing_allowed,
                'print_count'          => (int) ($r->print_count ?? 0),
                'last_printed_at'      => $r->last_printed_at ? $r->last_printed_at->format('M d, Y') : null,
                'last_printed_by_name' => optional($r->lastPrintedBy)->name,
            ];
        });

        return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
    }

    public function pendingRequestsEtaData(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $query = Eta::with('user')
            ->whereIn('user_id', $employeeIds)
            ->where('status', 'pending')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year);

        $recordsTotal = $query->count();

        $search = trim($request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhere('destination', 'like', "%{$search}%")
                    ->orWhereRaw("DATE_FORMAT(departure_date, '%b %d, %Y') LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("DATE_FORMAT(arrival_date, '%b %d, %Y') LIKE ?", ["%{$search}%"]);
            });
        }

        $recordsFiltered = $query->count();
        $start = max(0, $request->integer('start', 0));
        $length = min(100, max(1, $request->integer('length', 10)));

        $records = $query->orderBy('created_at', 'desc')->skip($start)->take($length)->get();

        $data = $records->map(fn ($e) => [
            'id' => $e->id,
            'employee' => optional($e->user)->name ?? '-',
            'departure' => $e->departure_date ? Carbon::parse($e->departure_date)->format('M d, Y') : '-',
            'arrival' => $e->arrival_date ? Carbon::parse($e->arrival_date)->format('M d, Y') : '-',
            'destination' => $e->destination,
            'purpose' => $e->purpose ?? '-',
            'filed_at' => $e->created_at ? $e->created_at->format('M d, Y') : '-',
        ]);

        return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
    }

    public function pendingRequestsLocatorData(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $query = Locator::with('user')
            ->whereIn('user_id', $employeeIds)
            ->where('status', 'pending')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year);

        $recordsTotal = $query->count();

        $search = trim($request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhere('application_type', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereRaw("DATE_FORMAT(travel_date, '%b %d, %Y') LIKE ?", ["%{$search}%"]);
            });
        }

        $recordsFiltered = $query->count();
        $start = max(0, $request->integer('start', 0));
        $length = min(100, max(1, $request->integer('length', 10)));

        $records = $query->orderBy('created_at', 'desc')->skip($start)->take($length)->get();

        $data = $records->map(fn ($l) => [
            'id' => $l->id,
            'employee' => optional($l->user)->name ?? '-',
            'application_type' => $l->application_type,
            'travel_date' => $l->travel_date ? Carbon::parse($l->travel_date)->format('M d, Y') : '-',
            'location' => $l->location,
            'detail' => $l->detail ?? '-',
            'filed_at' => $l->created_at ? $l->created_at->format('M d, Y') : '-',
        ]);

        return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
    }

    /**
     * Allow printing for a pending leave request (pre-approval) - AO variant.
     */
    public function allowPrinting(Request $request, $id)
    {
        $user = Auth::user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $leave = LeaveRequest::findOrFail($id);

        if ($depts->isEmpty()) {
            return response()->json(['error' => 'Department not found for your account.'], 403);
        }

        $employee = $leave->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
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
        if (! empty($leave->printing_deduction_details)) {
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

        // Fallback: derive deduction preview from the leave_type label.
        if (empty($deductionPreview)) {
            $toDeduct = floatval($leave->paid_days ?? 0);
            if ($toDeduct > 0) {
                $code = LeaveTypeResolver::fromLabel($leave->leave_type ?? '');
                if ($code) {
                    $deductionPreview = [$code => $toDeduct];
                }
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

        if (! empty($deductionPreview) && Schema::hasColumn('leave_requests', 'printing_deduction_details')) {
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
        } catch (\Exception $ex) {
        }

        return response()->json(['success' => true]);
    }

    public function approvedRequests(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $dept = $depts->first();

        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $leaveDataUrl = route('admin-officer.approved-requests.leave-data');
        $etaDataUrl = route('admin-officer.approved-requests.eta-data');
        $locatorDataUrl = route('admin-officer.approved-requests.locator-data');

        return view('department-head.approved-requests', compact('dept', 'month', 'year', 'leaveDataUrl', 'etaDataUrl', 'locatorDataUrl'));
    }

    public function approvedRequestsLeaveData(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $query = LeaveRequest::with(['user', 'user.leaveBalance'])
            ->whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->whereHas('user', fn ($u) => $u->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"))
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year);

        $recordsTotal = $query->count();

        $search = trim($request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhere('leave_type', 'like', "%{$search}%")
                    ->orWhereRaw("DATE_FORMAT(start_date, '%b %d, %Y') LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("DATE_FORMAT(end_date, '%b %d, %Y') LIKE ?", ["%{$search}%"]);
            });
        }

        $recordsFiltered = $query->count();
        $start = max(0, $request->integer('start', 0));
        $length = min(100, max(1, $request->integer('length', 10)));

        $records = $query->with('lastPrintedBy')->orderBy('created_at', 'desc')->skip($start)->take($length)->get();

        $data = $records->map(fn ($r) => [
            'id'                   => $r->id,
            'employee'             => $r->user->name ?? '-',
            'leave_type'           => $r->leave_type,
            'period'               => Carbon::parse($r->start_date)->format('M d, Y').' to '.Carbon::parse($r->end_date)->format('M d, Y'),
            'total_days'           => $r->total_days ?? '-',
            'approved_at'          => $r->updated_at ? $r->updated_at->format('M d, Y') : '-',
            'vl'                   => optional($r->user->leaveBalance)->VL ?? '0',
            'sl'                   => optional($r->user->leaveBalance)->SL ?? '0',
            'last_printed_at'      => $r->last_printed_at ? $r->last_printed_at->format('M d, Y') : null,
            'last_printed_by_name' => optional($r->lastPrintedBy)->name,
        ]);

        return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
    }

    public function approvedRequestsEtaData(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $query = Eta::with('user')
            ->whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year);

        $recordsTotal = $query->count();

        $search = trim($request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhere('destination', 'like', "%{$search}%")
                    ->orWhereRaw("DATE_FORMAT(departure_date, '%b %d, %Y') LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("DATE_FORMAT(arrival_date, '%b %d, %Y') LIKE ?", ["%{$search}%"]);
            });
        }

        $recordsFiltered = $query->count();
        $start = max(0, $request->integer('start', 0));
        $length = min(100, max(1, $request->integer('length', 10)));

        $records = $query->orderBy('created_at', 'desc')->skip($start)->take($length)->get();

        $data = $records->map(fn ($e) => [
            'id' => $e->id,
            'employee' => optional($e->user)->name ?? '-',
            'departure' => Carbon::parse($e->departure_date)->format('M d, Y'),
            'arrival' => Carbon::parse($e->arrival_date)->format('M d, Y'),
            'destination' => $e->destination,
            'purpose' => $e->purpose ?? '',
            'approved_at' => $e->updated_at ? $e->updated_at->format('M d, Y') : '-',
        ]);

        return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
    }

    public function approvedRequestsLocatorData(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $query = Locator::with('user')
            ->whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year);

        $recordsTotal = $query->count();

        $search = trim($request->input('search.value', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhere('application_type', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereRaw("DATE_FORMAT(travel_date, '%b %d, %Y') LIKE ?", ["%{$search}%"]);
            });
        }

        $recordsFiltered = $query->count();
        $start = max(0, $request->integer('start', 0));
        $length = min(100, max(1, $request->integer('length', 10)));

        $records = $query->orderBy('created_at', 'desc')->skip($start)->take($length)->get();

        $data = $records->map(fn ($l) => [
            'id' => $l->id,
            'employee' => optional($l->user)->name ?? '-',
            'application_type' => $l->application_type,
            'travel_date' => Carbon::parse($l->travel_date)->format('M d, Y'),
            'location' => $l->location,
            'purpose' => $l->purpose ?? '',
            'approved_at' => $l->updated_at ? $l->updated_at->format('M d, Y') : '-',
        ]);

        return response()->json(['draw' => $request->integer('draw'), 'recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $data]);
    }

    public function statistics(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $dept = $depts->first();
        $stats = [];
        if ($depts->isNotEmpty()) {
            $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);
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

        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }
        $apiUrl = route('admin-officer.statistics.data');
        $detailsUrl = route('admin-officer.statistics.details');

        return view('department-head.statistics', compact('dept', 'stats', 'month', 'year', 'apiUrl', 'detailsUrl'));
    }

    public function statisticsData(Request $request)
    {
        $user = $request->user();
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));
        $draw = (int) $request->query('draw', 1);
        $start = (int) $request->query('start', 0);
        $length = (int) $request->query('length', 10);
        $search = trim($request->input('search.value', ''));

        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }

        $cacheKey = 'ao_stats_'.implode('_', $depts->sortBy('Dept_id')->pluck('Dept_id')->toArray())."_{$month}_{$year}";
        $allRows = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($depts, $month, $year) {
            $deptIds = $depts->pluck('Dept_id')->toArray();
            $deptNames = $depts->pluck('Dept_name', 'Dept_id');
            $employees = User::whereIn('Dept_id', $deptIds)->get();
            $employeeIds = $employees->pluck('id')->toArray();

            $etaCounts = Eta::selectRaw('user_id, COUNT(*) as cnt')
                ->whereIn('user_id', $employeeIds)->where('status', 'approved')
                ->whereMonth('departure_date', $month)->whereYear('departure_date', $year)
                ->groupBy('user_id')->pluck('cnt', 'user_id');

            $locatorCounts = Locator::selectRaw('user_id, COUNT(*) as cnt')
                ->whereIn('user_id', $employeeIds)->where('status', 'approved')
                ->whereMonth('travel_date', $month)->whereYear('travel_date', $year)
                ->groupBy('user_id')->pluck('cnt', 'user_id');

            $leaveCounts = LeaveRequest::selectRaw('user_id, COUNT(*) as cnt')
                ->whereIn('user_id', $employeeIds)->where('status', 'approved')
                ->whereMonth('start_date', $month)->whereYear('start_date', $year)
                ->groupBy('user_id')->pluck('cnt', 'user_id');

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
                    'Dept' => $deptNames->get($emp->Dept_id) ?? '',
                    'eta_count' => $etaCount,
                    'locator_count' => $locatorCount,
                    'leave_count' => $leaveCount,
                    'total_usage' => ($etaCount + $locatorCount + $leaveCount),
                ];
            }

            return $rows;
        });

        $recordsTotal = count($allRows);

        if ($search !== '') {
            $lc = strtolower($search);
            $allRows = array_values(array_filter($allRows, function ($row) use ($lc) {
                $name = strtolower($row['Lname'].' '.$row['Fname'].' '.$row['Mname']);

                return str_contains($name, $lc)
                    || str_contains(strtolower($row['EmpNo']), $lc)
                    || str_contains(strtolower($row['Dept']), $lc);
            }));
        }

        $recordsFiltered = count($allRows);
        $data = array_slice($allRows, $start, $length > 0 ? $length : $recordsFiltered);

        return response()->json([
            'draw' => $draw,
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => array_values($data),
        ]);
    }

    public function statisticsDetails(Request $request)
    {
        $empNo = $request->query('empNo');
        $type = $request->query('type');
        $month = (int) $request->query('month', (int) date('n'));
        $year = (int) $request->query('year', (int) date('Y'));

        if (! $type) {
            return response()->json(['success' => false, 'message' => 'Missing required parameters.', 'data' => []]);
        }

        if (! $empNo) {
            return response()->json(['success' => true, 'data' => []]);
        }

        try {
            $user = User::where('EmpNo', $empNo)->first();
            if (! $user) {
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
        } catch (\Throwable $e) {
            Log::error('statisticsDetails error', ['empNo' => $empNo, 'type' => $type, 'error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => 'Unable to load details. Please try again.', 'data' => []], 500);
        }
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
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $employees = User::whereIn('Dept_id', $depts->pluck('Dept_id')->toArray())->get()->map(function ($u) {
            return [
                'EmpNo' => $u->EmpNo ?? ($u->id ?? ''),
                'name' => trim(($u->last_name ?? '').', '.($u->first_name ?? '')),
                'position' => $u->position ?? '',
                'status' => 'In Office',
            ];
        })->values();

        return response()->json(['success' => true, 'data' => $employees]);
    }

    public function leaveRequestsList(Request $request)
    {
        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);

        $rows = LeaveRequest::with('user')
            ->whereIn('user_id', $employeeIds)
            ->whereHas('user', fn ($u) => $u->whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) != 'department head'"))
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'emp' => $r->user ? ($r->user->last_name.', '.$r->user->first_name) : '',
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
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);

        $rows = Locator::with('user')
            ->whereIn('user_id', $employeeIds)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'emp' => $r->user ? ($r->user->last_name.', '.$r->user->first_name) : '',
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
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);

        $rows = Eta::with('user')
            ->whereIn('user_id', $employeeIds)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'emp' => $r->user ? ($r->user->last_name.', '.$r->user->first_name) : '',
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
        if (! $order) {
            return redirect()->back()->with('error', 'Travel order not found.');
        }

        $empNos = DB::table('travel_order_employees')->where('travel_order_id', $order->id)->pluck('emp_no')->toArray();
        $employees = User::whereIn('EmpNo', $empNos)->get();

        return view('department-head.travel-order-show', compact('order', 'employees'));
    }

    public function filedOfficeOrders(Request $request)
    {
        return view('department-head.filed-office-orders');
    }

    public function monitoringMatrix(Request $request)
    {
        $user  = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $dept  = $depts->first();

        $month = (int) $request->query('month', (int) date('n'));
        $year  = (int) $request->query('year', (int) date('Y'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }

        $rows = $depts->isNotEmpty()
            ? $this->monitoringExportService->getRows($depts, $month, $year)
            : collect();

        return view('administrative-officer.monitoring-matrix', compact('dept', 'depts', 'month', 'year', 'rows'));
    }

    public function exportMonitoringMatrix(Request $request)
    {
        $request->validate([
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $month = (int) $request->input('month');
        $year = (int) $request->input('year');

        $user = $request->user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            abort(403, 'No departments assigned to your account.');
        }

        return $this->monitoringExportService->generateExcelResponse($depts, $month, $year);
    }

    public function approve(Request $request, $id)
    {
        $user = Auth::user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $leave = LeaveRequest::findOrFail($id);

        if ($depts->isEmpty()) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $leave->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            return redirect()->back()->with('error', 'You are not authorized to approve this request.');
        }

        return $this->leaveRequestService->approveLeave($request, $id);
    }

    public function approveEta(Request $request, $id)
    {
        $user = Auth::user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $eta = Eta::findOrFail($id);

        if ($depts->isEmpty()) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $eta->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            return redirect()->back()->with('error', 'You are not authorized to approve this request.');
        }

        if ($eta->status === 'approved') {
            return redirect()->back()->with('success', 'ETA already approved.');
        }

        // Get normalized role for audit logging
        $normalizedRole = $this->departmentService->getEffectiveRole($user);

        $eta->status = 'approved';
        $eta->approved_by = $user->id;
        $eta->approved_role = $normalizedRole;
        $eta->approved_at = now();
        $eta->save();

        foreach ($depts as $d) {
            Cache::forget("dept_stats_{$d->Dept_id}_{$eta->created_at->month}_{$eta->created_at->year}");
            Cache::forget("dh_metrics_{$d->Dept_id}");
        }

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
                if (! empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    $employee->department_name = $department->Dept_name ?? null;
                }
                $formatted = [
                    'departure' => Carbon::parse($eta->departure_date)->format('l, F j, Y'),
                    'arrival' => Carbon::parse($eta->arrival_date)->format('l, F j, Y'),
                ];
                $email = $employee->email ?? null;
                Log::info('ETA approval email attempt (AO)', ['eta_id' => $eta->id, 'user_id' => $employee->id ?? null, 'email' => $email]);
                if (! empty($email)) {
                    $employee->notify(new HrisTransactionNotification(
                        requestType: 'ETA',
                        status: 'Approved',
                        details: [
                            'Destination' => $eta->destination ?? 'N/A',
                            'Departure Date' => $formatted['departure'],
                            'Arrival Date' => $formatted['arrival'],
                            'Purpose' => $eta->purpose ?? 'N/A',
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
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $eta = Eta::findOrFail($id);

        if ($depts->isEmpty()) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $eta->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            return redirect()->back()->with('error', 'You are not authorized to reject this request.');
        }

        $eta->status = 'declined';
        $eta->approved_by = $user->id;
        $eta->approved_role = $this->departmentService->getEffectiveRole($user);
        $eta->approved_at = now();
        $eta->save();

        foreach ($depts as $d) {
            Cache::forget("dept_stats_{$d->Dept_id}_{$eta->created_at->month}_{$eta->created_at->year}");
            Cache::forget("dh_metrics_{$d->Dept_id}");
        }

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
                if (! empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    $employee->department_name = $department->Dept_name ?? null;
                }
                $formatted = [
                    'departure' => Carbon::parse($eta->departure_date)->format('l, F j, Y'),
                    'arrival' => Carbon::parse($eta->arrival_date)->format('l, F j, Y'),
                ];
                $email = $employee->email ?? null;
                if (! empty($email)) {
                    $employee->notify(new HrisTransactionNotification(
                        requestType: 'ETA',
                        status: 'Rejected',
                        details: [
                            'Destination' => $eta->destination ?? 'N/A',
                            'Departure Date' => $formatted['departure'],
                            'Arrival Date' => $formatted['arrival'],
                            'Purpose' => $eta->purpose ?? 'N/A',
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
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $locator = Locator::findOrFail($id);

        if ($depts->isEmpty()) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $locator->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            return redirect()->back()->with('error', 'You are not authorized to approve this request.');
        }

        if ($locator->status === 'approved') {
            return redirect()->back()->with('success', 'Locator already approved.');
        }

        $locator->status = 'approved';
        $locator->save();

        foreach ($depts as $d) {
            Cache::forget("dept_stats_{$d->Dept_id}_{$locator->created_at->month}_{$locator->created_at->year}");
            Cache::forget("dh_metrics_{$d->Dept_id}");
        }

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
                if (! empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    $employee->department_name = $department->Dept_name ?? null;
                }
                $appType = 'Locator';
                if (! empty($locator->application_type)) {
                    $appType = 'Locator - '.ucfirst($locator->application_type);
                }
                $formatted = [
                    'travel' => Carbon::parse($locator->travel_date)->format('l, F j, Y'),
                    'departure_time_24' => Carbon::parse($locator->intended_departure_time)->format('H:i'),
                    'departure_time_ampm' => Carbon::parse($locator->intended_departure_time)->format('h:i A'),
                    'arrival_time_24' => Carbon::parse($locator->intended_arrival_time)->format('H:i'),
                    'arrival_time_ampm' => Carbon::parse($locator->intended_arrival_time)->format('h:i A'),
                ];
                $email = $employee->email ?? null;
                if (! empty($email)) {
                    $employee->notify(new HrisTransactionNotification(
                        requestType: $appType,
                        status: 'Approved',
                        details: [
                            'Location' => $locator->location ?? 'N/A',
                            'Travel Date' => $formatted['travel'],
                            'Departure Time' => $formatted['departure_time_ampm'],
                            'Arrival Time' => $formatted['arrival_time_ampm'],
                            'Detail' => $locator->detail ?? 'N/A',
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
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $locator = Locator::findOrFail($id);

        if ($depts->isEmpty()) {
            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $locator->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            return redirect()->back()->with('error', 'You are not authorized to reject this request.');
        }

        $locator->status = 'declined';
        $locator->save();

        foreach ($depts as $d) {
            Cache::forget("dept_stats_{$d->Dept_id}_{$locator->created_at->month}_{$locator->created_at->year}");
            Cache::forget("dh_metrics_{$d->Dept_id}");
        }

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
                if (! empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    $employee->department_name = $department->Dept_name ?? null;
                }
                $appType = 'Locator';
                if (! empty($locator->application_type)) {
                    $appType = 'Locator - '.ucfirst($locator->application_type);
                }
                $formatted = [
                    'travel' => Carbon::parse($locator->travel_date)->format('l, F j, Y'),
                    'departure_time_24' => Carbon::parse($locator->intended_departure_time)->format('H:i'),
                    'departure_time_ampm' => Carbon::parse($locator->intended_departure_time)->format('h:i A'),
                    'arrival_time_24' => Carbon::parse($locator->intended_arrival_time)->format('H:i'),
                    'arrival_time_ampm' => Carbon::parse($locator->intended_arrival_time)->format('h:i A'),
                ];
                $email = $employee->email ?? null;
                if (! empty($email)) {
                    $employee->notify(new HrisTransactionNotification(
                        requestType: $appType,
                        status: 'Rejected',
                        details: [
                            'Location' => $locator->location ?? 'N/A',
                            'Travel Date' => $formatted['travel'],
                            'Departure Time' => $formatted['departure_time_ampm'],
                            'Arrival Time' => $formatted['arrival_time_ampm'],
                            'Detail' => $locator->detail ?? 'N/A',
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
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        $leave = LeaveRequest::findOrFail($id);

        if ($depts->isEmpty()) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'swal' => ['icon' => 'error', 'title' => 'Department not found', 'text' => 'Department not found for your account.']], 422);
            }

            return redirect()->back()->with('error', 'Department not found for your account.');
        }

        $employee = $leave->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'swal' => ['icon' => 'error', 'title' => 'Unauthorized', 'text' => 'You are not authorized to reject this request.']], 403);
            }

            return redirect()->back()->with('error', 'You are not authorized to reject this request.');
        }

        // If printing deduction applied earlier, restore credits
        if (! empty($leave->printing_deduction_applied) && ! empty($leave->printing_deduction_details)) {
            try {
                $details = json_decode($leave->printing_deduction_details, true) ?: [];
                DB::transaction(function () use ($details, $leave) {
                    $employee = $leave->user;
                    if (! $employee) {
                        return;
                    }
                    $leaveBalance = $employee->leaveBalance;
                    if (! $leaveBalance) {
                        return;
                    }
                    foreach ($details as $col => $amt) {
                        if (! is_numeric($amt) || $amt <= 0) {
                            continue;
                        }
                        $key = strtoupper((string) $col);
                        $candidates = [
                            'VL' => ['balance_vacation_leave', 'vl', 'VL'],
                            'SL' => ['balance_sick_leave', 'sl', 'SL'],
                            'WLNS' => ['balance_wellness_leave', 'wlns', 'WLNS'],
                            'SPL' => ['balance_special_leave_privilege', 'spl', 'SPL'],
                            'CTO' => ['balance_cto', 'cto', 'CTO'],
                            'SP' => ['balance_solo_parent_leave', 'sp', 'SP'],
                        ];
                        $found = null;
                        foreach ($candidates[$key] ?? [strtolower($key), strtoupper($key)] as $cand) {
                            if (array_key_exists($cand, $leaveBalance->getAttributes()) || isset($leaveBalance->{$cand})) {
                                $found = $cand;
                                break;
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

        // If this is a reschedule request, unfreeze the original leave
        $isReschedule = ! empty($leave->rescheduled_from_id);
        if ($isReschedule) {
            LeaveRequest::where('id', $leave->rescheduled_from_id)
                ->update(['reschedule_status' => null]);
        }

        try {
            $employee = $leave->user;
            if ($employee && ! empty($employee->Dept_id)) {
                $empDept = Department::find($employee->Dept_id);
                if ($empDept) {
                    $employee->department_name = $empDept->Dept_name ?? null;
                }
            }
            $requestType = $isReschedule ? 'Leave Reschedule' : 'Leave Request';
            if ($employee) {
                $employee->notify(new HrisTransactionNotification(
                    requestType: $requestType,
                    status: 'Rejected',
                    details: [
                        'Leave Type' => $leave->leave_type ?? 'N/A',
                        'Start Date' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                        'End Date' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                    ],
                    actor: Auth::user()->name,
                    notes: $leave->rejection_notes ?? null,
                ));
            }

            // For reschedule rejections, also notify DH and Leave Manager
            if ($isReschedule) {
                $empDept = $employee?->Dept_id ? Department::find($employee->Dept_id) : null;
                $dh = ($empDept && ! empty($empDept->EmpNo) && $empDept->EmpNo !== 'UNASSIGNED')
                    ? \App\Models\User::where('EmpNo', $empDept->EmpNo)->first()
                    : null;
                $lm = \App\Models\User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'leave manager'")->first();
                $rejDetails = [
                    'Employee'   => $employee ? (trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: $employee->name) : 'N/A',
                    'Leave Type' => $leave->leave_type ?? 'N/A',
                    'Start Date' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                    'End Date'   => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                ];
                foreach (array_filter([$dh, $lm]) as $recipient) {
                    try {
                        $recipient->notify(new HrisTransactionNotification(
                            requestType: 'Leave Reschedule',
                            status: 'Rejected',
                            details: $rejDetails,
                            actor: Auth::user()->name,
                            notes: $leave->rejection_notes ?? null,
                        ));
                    } catch (\Exception $ex) { /* swallow */ }
                }
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
