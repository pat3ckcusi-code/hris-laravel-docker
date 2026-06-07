<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LeaveBalance;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveDate;
use App\Models\Holiday;
use App\Models\User;
use App\Models\HRAuditTrail;
use App\Events\HolidayCreated;
use App\Services\HolidayLeaveCancellationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;
use App\Notifications\HrisTransactionNotification;
use Illuminate\Support\Facades\Log;

class LeaveManagerController extends Controller
{
    /**
     * Allowed access levels for leave balance management.
     */
    private const BALANCE_ACCESS_LEVELS = ['department head', 'hr manager', 'employee'];

    /**
     * Show the manage leave balance page.
     */
    public function manageBalance(Request $request)
    {
        $allowed = self::BALANCE_ACCESS_LEVELS;

        $balances = LeaveBalance::with('user')
            ->whereHas('user', function ($query) use ($allowed) {
                $query->whereRaw(
                    "LOWER(REPLACE(REPLACE(access_level, '_', ' '), '-', ' ')) IN (" .
                    implode(',', array_fill(0, count($allowed), '?')) . ")",
                    $allowed
                );
            })
            ->join('users', 'leave_balances.user_id', '=', 'users.id')
            ->orderBy('users.EmpNo')
            ->select('leave_balances.*')
            ->get();

        $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();

        return view('leave-manager.manage-balance', [
            'balances' => $balances,
            'departments' => $departments,
        ]);
    }

    /**
     * Show the manage leave credits page.
     */
    public function manageCredits(Request $request)
    {
        $balances = LeaveBalance::with('user')
            ->join('users', 'leave_balances.user_id', '=', 'users.id')
            ->orderBy('users.EmpNo')
            ->select('leave_balances.*')
            ->get();
        $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();

        return view('leave-manager.manage-credits', [
            'balances' => $balances,
            'departments' => $departments,
        ]);
    }

    /**
     * Show the cancel leaves page.
     */
    public function cancelLeaves(Request $request)
    {
        // show approved and cancelled leave requests that are part of the auto-cancel workflow only.
        $query = LeaveRequest::with(['user', 'leaveDates'])
            ->where(function($q) {
                $q->whereIn('status', ['approved', 'cancelled'])
                  ->whereNull('cancellation_status');
            });

        // Filter by month (format: YYYY-MM)
        $month = $request->query('month');
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            $query->where(function ($q) use ($start, $end) {
                $q->whereRaw('DATE(date_filed) BETWEEN ? AND ?', [$start, $end])
                  ->orWhere(function ($q2) use ($start, $end) {
                      $q2->whereBetween('start_date', [$start, $end]);
                  });
            });
        }

        // Filter by employee EmpNo
        $emp = $request->query('emp');
        if ($emp) {
            $query->whereHas('user', function($q) use ($emp) {
                $q->where('EmpNo', $emp);
            });
        }

        // Filter by leave request status if provided
        $status = strtolower((string) $request->query('status', ''));
        if ($status !== '') {
            $allowedStatuses = ['approved', 'cancelled', 'pending'];
            if (in_array($status, $allowedStatuses, true)) {
                $query->where('status', $status);
            }
        }

        $requests = $query->orderBy('date_filed', 'desc')->paginate(10);

        $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();

        return view('leave-manager.cancel-leaves', [
            'requests' => $requests,
            'departments' => $departments,
            'selectedMonth' => $month,
        ]);
    }

    /**
     * Show the employee cancellation requests page.
     */
    public function employeeCancellationRequests(Request $request)
    {
        $query = LeaveRequest::with(['user', 'leaveDates'])
            ->where('status', 'approved')
            ->where('cancellation_status', 'Pending Cancellation');

        $month = $request->query('month', date('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $start = $month . '-01';
        $end = date('Y-m-t', strtotime($start));
        $query->whereHas('leaveDates', function($q) use ($start, $end) {
            $q->whereBetween('leave_date', [$start, $end]);
        });

        $emp = $request->query('emp');
        if ($emp) {
            $query->whereHas('user', function($q) use ($emp) {
                $q->where('EmpNo', $emp);
            });
        }

        $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();

        if ($request->ajax()) {
            $items = $query->orderBy('cancellation_requested_at', 'desc')->get();
            $rows = $items->map(fn($item) => [
                'id'                  => $item->id,
                'employee'            => $item->user
                    ? trim(($item->user->last_name ?? '') . ', ' . ($item->user->first_name ?? ''))
                    : '-',
                'department'          => ($item->user && isset($departments[$item->user->Dept_id]))
                    ? $departments[$item->user->Dept_id]
                    : '-',
                'leave_type'          => strtoupper($item->leave_type ?? ''),
                'cancellation_reason' => $item->cancellation_reason ?? '-',
                'status'              => ucfirst($item->status ?? '-'),
                'requested_at'        => $item->cancellation_requested_at
                    ? \Carbon\Carbon::parse($item->cancellation_requested_at)->format('M d, Y H:i')
                    : '-',
            ]);
            return response()->json(['rows' => $rows]);
        }

        $requests = $query->orderBy('cancellation_requested_at', 'desc')->paginate(25);

        return view('leave-manager.employee-cancellation-requests', [
            'requests'     => $requests,
            'departments'  => $departments,
            'currentMonth' => $month,
        ]);
    }

    /**
     * API: pending employee cancellation requests badge count.
     */
    public function apiPendingCancellationCount(): JsonResponse
    {
        $count = LeaveRequest::where('status', 'approved')
            ->where('cancellation_status', 'Pending Cancellation')
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * API: cancel a single leave date and refund credits.
     * Expects: leave_id, date, reason
     */
    public function apiCancelDate(Request $request)
    {
        $request->validate([
            'leave_id' => 'required|integer|min:1',
            'date'     => 'required|date',
            'reason'   => 'required|string|max:2000',
        ]);

        $leaveId = $request->input('leave_id');
        $date = $request->input('date');
        $reason = $request->input('reason');

        $leave = LeaveRequest::with('user')->find($leaveId);
        if (! $leave) {
            return response()->json(['error' => 'Leave not found'], 404);
        }

        if ($leave->status !== 'approved') {
            return response()->json(['error' => 'Only approved leaves can be cancelled'], 422);
        }

        // find matching leave date
        $ld = LeaveDate::where('leave_request_id', $leave->id)
            ->whereDate('leave_date', $date)
            ->first();
        if (! $ld) {
            return response()->json(['error' => 'Leave date not found'], 404);
        }
        if ($ld->is_cancelled) {
            return response()->json(['error' => 'Date already cancelled'], 422);
        }

        DB::beginTransaction();
        try {
            $ld->is_cancelled = true;
            $ld->cancel_reason = $reason;
            $ld->cancelled_by = Auth::id() ?? null;
            $ld->cancelled_at = now();
            $ld->save();

            // refund 1 day to the appropriate balance field based on leave_type
            $user = $leave->user;
            if ($user && $user->leaveBalance) {
                $lb = $user->leaveBalance;
                $type = strtolower((string)$leave->leave_type);

                // mapping of keywords to LeaveBalance fields
                $mapping = [
                    'sick' => 'SL',
                    'sick leave' => 'SL',
                    'vacation' => 'VL',
                    'vacation leave' => 'VL',
                    'vl' => 'VL',
                    'wellness' => 'WLNS',
                    'wellness leave' => 'WLNS',
                    'special privilege' => 'SPL',
                    'special previlage' => 'SPL', // common misspelling
                    'special privilege leave' => 'SPL',
                    'spl' => 'SPL',
                    'sp' => 'SP',
                    'cto' => 'CTO',
                ];

                $field = null;
                foreach ($mapping as $keyword => $f) {
                    if (strpos($type, $keyword) !== false) { $field = $f; break; }
                }

                // Fallback: if leave_type is a direct code like 'VL' or 'SL'
                if (! $field) {
                    $code = strtoupper(trim($type));
                    $allowed = ['VL','SL','WLNS','SPL','SP','CTO'];
                    if (in_array($code, $allowed, true)) $field = $code;
                }

                // Final fallback to VL if unknown
                if (! $field) $field = 'VL';

                $current = (float) ($lb->{$field} ?? 0);
                $lb->{$field} = $current + 1.0;
                $lb->save();
            }

            DB::commit();
            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to cancel date'], 500);
        }
    }

    /**
     * Approve a pending cancellation request for an entire leave.
     * Expects: leave_id, remarks (optional)
     */
    public function apiApproveCancellation(Request $request, $leave)
    {
        $leave = LeaveRequest::with(['user','leaveDates'])->find($leave);
        if (! $leave) return response()->json(['error' => 'Leave not found'], 404);

        if ($leave->cancellation_status !== 'Pending Cancellation') {
            return response()->json(['error' => 'No pending cancellation request for this leave'], 422);
        }

        if ($leave->status !== 'approved') {
            return response()->json(['error' => 'Only approved leaves can be cancelled'], 422);
        }

        DB::beginTransaction();
        try {
            $user = $leave->user;
            $lb = $user->leaveBalance ?? null;

            // Prefer returning applied per-type deductions if present (printing_deduction_details contains applied log)
            $applied = [];
            if (!empty($leave->printing_deduction_details)) {
                try { $applied = json_decode($leave->printing_deduction_details, true) ?: []; } catch (\Exception $e) { $applied = []; }
            }

            if (!empty($applied) && $lb) {
                // restore per-type amounts from applied deduction log
                $candidates = [
                    'VL' => ['balance_vacation_leave','vl','VL'],
                    'SL' => ['balance_sick_leave','sl','SL'],
                    'WLNS' => ['balance_wellness_leave','wlns','WLNS'],
                    'SPL' => ['balance_special_leave_privilege','spl','SPL'],
                    'CTO' => ['balance_cto','cto','CTO'],
                    'SP' => ['balance_solo_parent_leave','sp','SP'],
                ];
                $restored = [];
                foreach ($applied as $type => $amt) {
                    if (!is_numeric($amt) || floatval($amt) <= 0) continue;
                    $key = strtoupper((string)$type);
                    $found = null;
                    foreach ($candidates[$key] as $cand) {
                        if (array_key_exists($cand, $lb->getAttributes()) || isset($lb->{$cand})) { $found = $cand; break; }
                    }
                    if ($found) {
                        $before = floatval($lb->{$found} ?? 0);
                        $lb->{$found} = $before + floatval($amt);
                        $restored[$key] = floatval($amt);
                    }
                }
                $lb->save();

                // mark all non-cancelled leave dates as cancelled and annotate
                $dates = $leave->leaveDates()->where('is_cancelled', false)->get();
                foreach ($dates as $ld) {
                    $ld->is_cancelled = true;
                    $ld->cancel_reason = $leave->cancellation_reason ?? 'Cancelled by manager approval';
                    $ld->cancelled_by = Auth::id();
                    $ld->cancelled_at = now();
                    $ld->save();
                }

                // record audit trail with per-type restored amounts
                try {
                    HRAuditTrail::create([
                        'actor_user_id' => Auth::id(),
                        'module' => 'leave',
                        'action' => 'cancel_restore_balances',
                        'target_type' => 'leave_request',
                        'target_id' => $leave->id,
                        'details' => [
                            'restored' => $restored,
                            'cancelled_by' => Auth::id(),
                            'cancelled_at' => now()->toDateTimeString(),
                            'remarks' => $request->input('remarks') ?? null,
                            'leave_reason' => $leave->reason ?? null,
                            'type_labels' => [
                                'VL' => 'Vacation Leave',
                                'SL' => 'Sick Leave',
                                'WLNS' => 'Wellness Leave',
                                'SPL' => 'Special Privilege Leave',
                                'CTO' => 'CTO',
                                'SP' => 'Solo Parent Leave',
                            ],
                        ],
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to write HRAuditTrail on cancellation restore', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
                }

            } else {
                // Fallback: refund balances based on leaveDates
                $dates = $leave->leaveDates()->where('is_cancelled', false)->get();
                foreach ($dates as $ld) {
                    $days = $ld->days ?? 1.0;
                    $type = strtolower((string)($ld->leave_type ?? $leave->leave_type ?? ''));
                    if ($lb) {
                        if (strpos($type, 'vacation') !== false) { $lb->VL = ($lb->VL ?? 0) + $days; }
                        elseif (strpos($type, 'sick') !== false) { $lb->SL = ($lb->SL ?? 0) + $days; }
                        elseif (strpos($type, 'wellness') !== false) { $lb->WLNS = ($lb->WLNS ?? 0) + $days; }
                        elseif (strpos($type, 'solo') !== false) { $lb->SP = ($lb->SP ?? 0) + $days; }
                        elseif (strpos($type, 'special') !== false || strpos($type, 'spl') !== false) { $lb->SPL = ($lb->SPL ?? 0) + $days; }
                    }
                    $ld->is_cancelled = true;
                    $ld->cancel_reason = $leave->cancellation_reason ?? 'Cancelled by manager approval';
                    $ld->cancelled_by = Auth::id();
                    $ld->cancelled_at = now();
                    $ld->save();
                }

                if ($lb) $lb->save();
            }

            // fallback refund if no leaveDates
            if ($dates->isEmpty() && $leave->paid_days > 0 && $lb) {
                $tn = strtolower((string)$leave->leave_type);
                $days = $leave->paid_days;
                if (strpos($tn, 'vacation') !== false) { $lb->VL = ($lb->VL ?? 0) + $days; }
                elseif (strpos($tn, 'sick') !== false) { $lb->SL = ($lb->SL ?? 0) + $days; }
                elseif (strpos($tn, 'wellness') !== false) { $lb->WLNS = ($lb->WLNS ?? 0) + $days; }
                elseif (strpos($tn, 'solo') !== false) { $lb->SP = ($lb->SP ?? 0) + $days; }
                elseif (strpos($tn, 'special') !== false || strpos($tn, 'spl') !== false) { $lb->SPL = ($lb->SPL ?? 0) + $days; }
                $lb->save();
            }

            $leave->status = 'cancelled';
            $leave->cancellation_status = 'Cancelled by applicant';
            $leave->cancellation_reviewed_by = Auth::id();
            $leave->cancellation_reviewed_at = now();
            $leave->cancellation_remarks = $request->input('remarks') ?? null;
            $leave->save();

            DB::commit();

            // Log audit
            \Illuminate\Support\Facades\Log::info('Cancellation approved', [
                'leave_id' => $leave->id,
                'employee_id' => $user->id,
                'manager_id' => Auth::id(),
                'reason' => substr($leave->cancellation_reason ?? '', 0, 1000),
                'remarks' => substr($leave->cancellation_remarks ?? '', 0, 1000),
                'timestamp' => now()->toDateTimeString(),
            ]);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to approve cancellation: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Reject a pending cancellation request.
     * Expects: leave_id, remarks (required)
     */
    public function apiRejectCancellation(Request $request, $leave)
    {
        $request->validate(['remarks' => 'required|string|max:2000']);
        $leave = LeaveRequest::with(['user', 'leaveDates'])->find($leave);
        if (! $leave) return response()->json(['error' => 'Leave not found'], 404);

        if ($leave->cancellation_status !== 'Pending Cancellation') {
            return response()->json(['error' => 'No pending cancellation request for this leave'], 422);
        }

        DB::beginTransaction();
        try {
            $user = $leave->user;
            $lb = $user?->leaveBalance;

            $cancelledDates = $leave->leaveDates()->where('is_cancelled', true)->get();
            foreach ($cancelledDates as $ld) {
                $days = $ld->days ?? 1.0;
                $type = strtolower((string)($ld->leave_type ?? $leave->leave_type ?? ''));
                if ($lb) {
                    if (strpos($type, 'vacation') !== false) { $lb->VL = ($lb->VL ?? 0) + $days; }
                    elseif (strpos($type, 'sick') !== false) { $lb->SL = ($lb->SL ?? 0) + $days; }
                    elseif (strpos($type, 'wellness') !== false) { $lb->WLNS = ($lb->WLNS ?? 0) + $days; }
                    elseif (strpos($type, 'solo') !== false) { $lb->SP = ($lb->SP ?? 0) + $days; }
                    elseif (strpos($type, 'special') !== false || strpos($type, 'spl') !== false) { $lb->SPL = ($lb->SPL ?? 0) + $days; }
                }
                $ld->is_cancelled = false;
                $ld->cancel_reason = null;
                $ld->cancelled_by = null;
                $ld->cancelled_at = null;
                $ld->save();
            }

            if ($lb) {
                $lb->save();
            }

            $leave->cancellation_status = 'Rejected';
            $leave->cancellation_reviewed_by = Auth::id();
            $leave->cancellation_reviewed_at = now();
            $leave->cancellation_remarks = $request->input('remarks');
            $leave->save();

            DB::commit();

            // Log audit
            \Illuminate\Support\Facades\Log::info('Cancellation rejected', [
                'leave_id' => $leave->id,
                'employee_id' => $leave->user ? $leave->user->id : null,
                'manager_id' => Auth::id(),
                'remarks' => substr($leave->cancellation_remarks ?? '', 0, 1000),
                'timestamp' => now()->toDateTimeString(),
            ]);

            try {
                if ($leave->user) {
                    $leave->user->notify(new HrisTransactionNotification(
                        requestType: 'Leave Request',
                        status: 'Cancellation Rejected',
                        details: [
                            'Leave Type' => $leave->leave_type ?? 'N/A',
                            'Start Date' => \Carbon\Carbon::parse($leave->start_date)->format('l, F j, Y'),
                            'End Date'   => \Carbon\Carbon::parse($leave->end_date)->format('l, F j, Y'),
                        ],
                        actor: Auth::user()->name,
                        notes: $leave->cancellation_remarks ?? null,
                    ));
                }
            } catch (\Exception $ex) {}

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to reject cancellation: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Simple employee search for autocomplete.
     * Query param: q
     */
    public function employeeSearch(Request $request)
    {
        $q = substr(trim((string) $request->query('q', '')), 0, 100);
        if (strlen($q) < 2) return response()->json([]);

        $rows = User::query()
            ->select(['id','EmpNo','first_name','last_name','designation'])
            ->where(function($w) use ($q) {
                $w->where('EmpNo', 'like', "%{$q}%")
                  ->orWhere('first_name', 'like', "%{$q}%")
                  ->orWhere('last_name', 'like', "%{$q}%");
            })
            ->limit(12)
            ->get()
            ->map(function($u){
                return [
                    'EmpNo' => $u->EmpNo,
                    'FullName' => trim((($u->last_name ?? '') . ', ' . ($u->first_name ?? ''))),
                    'Position' => $u->designation ?? '',
                ];
            });

        return response()->json($rows);
    }

    /**
     * Apply credits/deductions for a given leave balance row.
     * Expected input: id, tardiness (min), undertime (min), deduction_days (float), deduct_from (VL|SL|CTO|SPL|WLNS|NONE)
     */
    public function applyCredits(Request $request)
    {
        $data = $request->validate([
            'id'             => 'required|integer|min:1',
            'tardiness'      => 'nullable|integer|min:0|max:14400',
            'undertime'      => 'nullable|integer|min:0|max:14400',
            'deduction_days' => 'nullable|numeric|min:0|max:365',
            'deduct_from'    => 'nullable|string|in:VL,SL,CTO,SPL,WLNS,SP,NONE',
        ]);

        $balance = LeaveBalance::find($data['id']);
        if (!$balance) {
            return response()->json(['message' => 'Balance not found'], 404);
        }

        $deduction = (float) ($data['deduction_days'] ?? 0);
        $deductFrom = $data['deduct_from'] ?? 'NONE';

        if ($deduction > 0 && $deductFrom !== 'NONE') {
            // subtract deduction from chosen field, keeping nulls handled as 0
            $current = $balance->{$deductFrom} ?? 0;
            $new = $current - $deduction;
            // allow negative balances but round to 3 decimals
            $balance->{$deductFrom} = round($new, 3);
            $balance->save();
        }

        return response()->json(['message' => 'Applied', 'balance' => $balance->fresh()]);
    }

    /**
     * Update a single leave balance field via AJAX.
     */
    public function updateBalance(Request $request, LeaveBalance $balance): JsonResponse
    {
        $field = $request->input('field');
        $value = $request->input('value');

        $allowed = ['VL', 'SL', 'WLNS', 'SPL', 'CTO', 'SP'];
        if (!in_array($field, $allowed, true)) {
            return response()->json(['message' => 'Invalid field'], 422);
        }

        // Basic validation: numeric and allow empty
        if ($value !== null && $value !== '') {
            if (!is_numeric($value)) {
                return response()->json(['message' => 'Value must be numeric'], 422);
            }
            $value = (float) $value;
        }

        $balance->{$field} = $value === '' ? null : $value;
        $balance->save();

        return response()->json(['message' => 'Updated', 'balance' => $balance->fresh()]);
    }

    /**
     * API: Bulk cancel all approved leave dates on a declared holiday date.
     */
    public function apiBulkCancelByHoliday(Request $request, HolidayLeaveCancellationService $service): JsonResponse
    {
        $request->validate([
            'date' => 'required|date',
            'holiday_title' => 'required|string|max:255',
        ]);

        $date = $request->input('date');
        $title = $request->input('holiday_title');
        $reason = 'Cancelled by Holiday: ' . $title . ' (' . date('M d, Y', strtotime($date)) . ')';

        try {
            $result = $service->cancelLeavesOnDate($date, $reason, Auth::id());

            return response()->json([
                'success' => true,
                'cancelled_count' => $result['cancelled_count'],
                'affected_employees' => $result['affected_employees'],
                'details' => $result['details'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to cancel leaves: ' . $e->getMessage()], 500);
        }
    }

    /**
     * API: Store a new holiday and automatically cancel overlapping leaves.
     */
    public function storeHoliday(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'holiday_date' => 'required|date',
            'type' => 'required|string|in:regular,special,suspension',
        ]);

        $holiday = Holiday::create([
            'title' => $request->input('title'),
            'holiday_date' => $request->input('holiday_date'),
            'type' => $request->input('type'),
            'created_by' => Auth::id(),
        ]);

        // Dispatch event → listener automatically cancels overlapping leaves
        HolidayCreated::dispatch($holiday);

        return response()->json([
            'success' => true,
            'holiday' => $holiday,
            'message' => 'Holiday created. Overlapping approved leaves have been cancelled and credits refunded.',
        ]);
    }

    /**
     * API: List holidays (for calendar / reference).
     */
    public function listHolidays(Request $request): JsonResponse
    {
        $holidays = Holiday::orderBy('holiday_date', 'desc')->get();

        return response()->json($holidays);
    }
}
