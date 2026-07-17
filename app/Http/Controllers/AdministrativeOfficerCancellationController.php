<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\ApprovalNotificationService;
use App\Services\DepartmentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdministrativeOfficerCancellationController extends Controller
{
    public function __construct(
        private DepartmentService $departmentService,
        private ApprovalNotificationService $approvalNotificationService,
    ) {}

    public function leaveCancellationRequests(Request $request)
    {
        $user = Auth::user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return view('administrative-officer.leave-cancellation-requests', [
                'requests' => collect(),
            ]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);

        $query = LeaveRequest::with(['user', 'leaveDates'])
            ->whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->where('cancellation_status', 'DH Recommended')
                    ->orWhereHas('leaveDates', function ($dq) {
                        $dq->where('cancellation_status', 'DH Recommended');
                    });
            });

        if ($request->ajax()) {
            $items = $query->orderBy('cancellation_requested_at', 'desc')->get();
            $rows = $items->map(function ($item) {
                $pendingDates = $item->cancellation_status === 'DH Recommended'
                    ? collect()
                    : $item->leaveDates->where('cancellation_status', 'DH Recommended');

                return [
                    'id' => $item->id,
                    'employee' => $item->user
                        ? trim(($item->user->last_name ?? '').', '.($item->user->first_name ?? ''))
                        : '-',
                    'leave_type' => strtoupper($item->leave_type ?? ''),
                    'start_date' => $item->start_date ? Carbon::parse($item->start_date)->format('M d, Y') : '-',
                    'end_date' => $item->end_date ? Carbon::parse($item->end_date)->format('M d, Y') : '-',
                    'cancellation_reason' => $item->cancellation_reason ?? ($pendingDates->first()->cancellation_reason ?? '-'),
                    'dh_remarks' => $item->cancellation_dh_remarks ?? ($pendingDates->first()->cancellation_dh_remarks ?? '-'),
                    'requested_at' => $item->cancellation_requested_at
                        ? Carbon::parse($item->cancellation_requested_at)->format('M d, Y H:i')
                        : '-',
                    'partial' => $pendingDates->isNotEmpty(),
                    'pending_date_ids' => $pendingDates->pluck('id')->values(),
                    'pending_dates' => $pendingDates->pluck('leave_date')->map(fn ($d) => Carbon::parse($d)->format('M d, Y'))->values(),
                ];
            });

            return response()->json(['rows' => $rows]);
        }

        $requests = $query->orderBy('cancellation_requested_at', 'desc')->paginate(25);

        return view('administrative-officer.leave-cancellation-requests', [
            'requests' => $requests,
        ]);
    }

    public function endorse(Request $request, $id): JsonResponse
    {
        $request->validate(['remarks' => 'nullable|string|max:2000']);

        $user = Auth::user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['error' => 'Department not found for your account.'], 403);
        }

        $leave = LeaveRequest::with(['user'])->find($id);
        if (! $leave) {
            return response()->json(['error' => 'Leave not found.'], 404);
        }

        $employee = $leave->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            return response()->json(['error' => 'You are not authorized to act on this request.'], 403);
        }

        if ($leave->status !== 'approved') {
            return response()->json(['error' => 'Only approved leaves can be cancelled.'], 422);
        }

        if ($leave->cancellation_status !== 'DH Recommended') {
            return response()->json(['error' => 'This cancellation request has not been recommended by the Department Head yet.'], 422);
        }

        DB::beginTransaction();
        try {
            $leave->cancellation_status = 'AO Endorsed';
            $leave->cancellation_ao_action = 'endorsed';
            $leave->cancellation_ao_at = now();
            $leave->cancellation_ao_by = $user->id;
            $leave->cancellation_ao_remarks = $request->input('remarks');
            $leave->save();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to endorse cancellation: '.$e->getMessage()], 500);
        }

        $this->approvalNotificationService->writeAuditTrail([
            'actor_user_id' => $user->id,
            'module' => 'leave',
            'action' => 'ao_endorse_cancellation',
            'target_type' => 'leave_request',
            'target_id' => $leave->id,
            'details' => ['remarks' => $request->input('remarks')],
        ]);

        $empName = trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: ($employee->name ?? 'Employee');
        $details = [
            'Employee' => $empName,
            'Leave Type' => $leave->leave_type ?? 'N/A',
            'Start Date' => $leave->start_date ? Carbon::parse($leave->start_date)->format('l, F j, Y') : 'N/A',
            'End Date' => $leave->end_date ? Carbon::parse($leave->end_date)->format('l, F j, Y') : 'N/A',
            'Reason' => $leave->cancellation_reason ?? 'N/A',
        ];

        // Notify Leave Manager
        try {
            $lm = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'leave manager'")->first();
            if ($lm) {
                $this->approvalNotificationService->notifyEmployee($lm, 'Leave Cancellation', 'AO Endorsed - Awaiting Final Approval', $details);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify Leave Manager of AO endorsement', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
        }

        // Notify employee
        $this->approvalNotificationService->notifyEmployee($employee, 'Leave Cancellation', 'Endorsed by Administrative Officer -Awaiting Leave Manager', $details);

        return response()->json(['success' => true]);
    }

    public function reject(Request $request, $id): JsonResponse
    {
        $request->validate(['remarks' => 'required|string|max:2000']);

        $user = Auth::user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['error' => 'Department not found for your account.'], 403);
        }

        $leave = LeaveRequest::with(['user'])->find($id);
        if (! $leave) {
            return response()->json(['error' => 'Leave not found.'], 404);
        }

        $employee = $leave->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            return response()->json(['error' => 'You are not authorized to act on this request.'], 403);
        }

        if ($leave->cancellation_status !== 'DH Recommended') {
            return response()->json(['error' => 'This cancellation request is not in a state that can be rejected by the AO.'], 422);
        }

        DB::beginTransaction();
        try {
            $leave->cancellation_status = 'Rejected';
            $leave->cancellation_ao_action = 'rejected';
            $leave->cancellation_ao_at = now();
            $leave->cancellation_ao_by = $user->id;
            $leave->cancellation_ao_remarks = $request->input('remarks');
            $leave->save();
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to reject cancellation: '.$e->getMessage()], 500);
        }

        $this->approvalNotificationService->writeAuditTrail([
            'actor_user_id' => $user->id,
            'module' => 'leave',
            'action' => 'ao_reject_cancellation',
            'target_type' => 'leave_request',
            'target_id' => $leave->id,
            'details' => ['remarks' => $request->input('remarks')],
        ]);

        $this->approvalNotificationService->notifyEmployee($employee, 'Leave Cancellation', 'Rejected by Administrative Officer', [
            'Leave Type' => $leave->leave_type ?? 'N/A',
            'Remarks' => $request->input('remarks'),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Per-date variant of endorse(): endorses cancellation for a subset of an
     * approved multi-date leave's dates, leaving the rest untouched.
     */
    public function endorseDates(Request $request, $id): JsonResponse
    {
        $request->validate([
            'leave_date_ids' => 'required|array|min:1',
            'leave_date_ids.*' => 'integer',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $user = Auth::user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        if ($depts->isEmpty()) {
            return response()->json(['error' => 'Department not found for your account.'], 403);
        }

        $leave = LeaveRequest::with(['user'])->find($id);
        if (! $leave) {
            return response()->json(['error' => 'Leave not found.'], 404);
        }

        $employee = $leave->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            return response()->json(['error' => 'You are not authorized to act on this request.'], 403);
        }

        if ($leave->status !== 'approved') {
            return response()->json(['error' => 'Only approved leaves can be cancelled.'], 422);
        }

        $dates = $leave->leaveDates()
            ->whereIn('id', $request->input('leave_date_ids'))
            ->where('cancellation_status', 'DH Recommended')
            ->get();

        if ($dates->count() !== count($request->input('leave_date_ids'))) {
            return response()->json(['error' => 'One or more selected dates have not been recommended by the Department Head yet.'], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($dates as $ld) {
                $ld->cancellation_status = 'AO Endorsed';
                $ld->cancellation_ao_action = 'endorsed';
                $ld->cancellation_ao_at = now();
                $ld->cancellation_ao_by = $user->id;
                $ld->cancellation_ao_remarks = $request->input('remarks');
                $ld->save();
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to endorse cancellation: '.$e->getMessage()], 500);
        }

        $this->approvalNotificationService->writeAuditTrail([
            'actor_user_id' => $user->id,
            'module' => 'leave',
            'action' => 'ao_endorse_partial_cancellation',
            'target_type' => 'leave_request',
            'target_id' => $leave->id,
            'details' => ['remarks' => $request->input('remarks'), 'leave_date_ids' => $dates->pluck('id')->all()],
        ]);

        $empName = trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: ($employee->name ?? 'Employee');
        $details = [
            'Employee' => $empName,
            'Leave Type' => $leave->leave_type ?? 'N/A',
            'Dates' => $dates->pluck('leave_date')->map(fn ($d) => Carbon::parse($d)->format('M j, Y'))->implode(', '),
            'Reason' => $dates->first()->cancellation_reason ?? 'N/A',
        ];

        try {
            $lm = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'leave manager'")->first();
            if ($lm) {
                $this->approvalNotificationService->notifyEmployee($lm, 'Leave Cancellation', 'AO Endorsed - Awaiting Final Approval', $details);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify Leave Manager of AO partial endorsement', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
        }

        $this->approvalNotificationService->notifyEmployee($employee, 'Leave Cancellation', 'Endorsed by Administrative Officer -Awaiting Leave Manager', $details);

        return response()->json(['success' => true]);
    }

    /**
     * Per-date variant of reject(): rejects cancellation for a subset of dates.
     */
    public function rejectDates(Request $request, $id): JsonResponse
    {
        $request->validate([
            'leave_date_ids' => 'required|array|min:1',
            'leave_date_ids.*' => 'integer',
            'remarks' => 'required|string|max:2000',
        ]);

        $user = Auth::user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);
        if ($depts->isEmpty()) {
            return response()->json(['error' => 'Department not found for your account.'], 403);
        }

        $leave = LeaveRequest::with(['user'])->find($id);
        if (! $leave) {
            return response()->json(['error' => 'Leave not found.'], 404);
        }

        $employee = $leave->user;
        if (! $employee || ! in_array($employee->Dept_id, $depts->pluck('Dept_id')->toArray())) {
            return response()->json(['error' => 'You are not authorized to act on this request.'], 403);
        }

        $dates = $leave->leaveDates()
            ->whereIn('id', $request->input('leave_date_ids'))
            ->where('cancellation_status', 'DH Recommended')
            ->get();

        if ($dates->count() !== count($request->input('leave_date_ids'))) {
            return response()->json(['error' => 'One or more selected dates are not in a state that can be rejected by the AO.'], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($dates as $ld) {
                $ld->cancellation_status = 'Rejected';
                $ld->cancellation_ao_action = 'rejected';
                $ld->cancellation_ao_at = now();
                $ld->cancellation_ao_by = $user->id;
                $ld->cancellation_ao_remarks = $request->input('remarks');
                $ld->save();
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to reject cancellation: '.$e->getMessage()], 500);
        }

        $this->approvalNotificationService->writeAuditTrail([
            'actor_user_id' => $user->id,
            'module' => 'leave',
            'action' => 'ao_reject_partial_cancellation',
            'target_type' => 'leave_request',
            'target_id' => $leave->id,
            'details' => ['remarks' => $request->input('remarks'), 'leave_date_ids' => $dates->pluck('id')->all()],
        ]);

        $this->approvalNotificationService->notifyEmployee($employee, 'Leave Cancellation', 'Rejected by Administrative Officer', [
            'Leave Type' => $leave->leave_type ?? 'N/A',
            'Remarks' => $request->input('remarks'),
        ]);

        return response()->json(['success' => true]);
    }

    public function pendingCancellationCount(): JsonResponse
    {
        $user = Auth::user();
        $depts = $this->departmentService->resolveAllDepartmentsForAdminOfficer($user);

        if ($depts->isEmpty()) {
            return response()->json(['count' => 0]);
        }

        $employeeIds = $this->departmentService->getEmployeeIdsForDepartments($depts);

        $count = LeaveRequest::whereIn('user_id', $employeeIds)
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->where('cancellation_status', 'DH Recommended')
                    ->orWhereHas('leaveDates', function ($dq) {
                        $dq->where('cancellation_status', 'DH Recommended');
                    });
            })
            ->count();

        return response()->json(['count' => $count]);
    }
}
