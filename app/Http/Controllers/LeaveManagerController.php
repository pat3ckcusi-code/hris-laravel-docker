<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\LeaveBalance;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveDate;
use App\Models\Holiday;
use App\Models\User;
use App\Events\HolidayCreated;
use App\Services\HolidayLeaveCancellationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;

class LeaveManagerController extends Controller
{
    /**
     * Show the manage leave balance page.
     */
    public function manageBalance(Request $request)
    {
        $balances = LeaveBalance::with('user')->orderBy('EmpNo')->get();
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
        $balances = LeaveBalance::with('user')->orderBy('EmpNo')->get();
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
        // show approved leave requests so individual dates can be cancelled
        $query = LeaveRequest::with(['user', 'leaveDates'])
            ->where('status', 'approved');

        // Filter by month (format: YYYY-MM)
        $month = $request->query('month');
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $start = $month . '-01';
            $end = date('Y-m-t', strtotime($start));
            $query->whereHas('leaveDates', function($q) use ($start, $end) {
                $q->whereBetween('leave_date', [$start, $end]);
            });
        }

        // Filter by employee EmpNo
        $emp = $request->query('emp');
        if ($emp) {
            $query->whereHas('user', function($q) use ($emp) {
                $q->where('EmpNo', $emp);
            });
        }

        $requests = $query->orderBy('date_filed', 'desc')->paginate(25);

        $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();

        return view('leave-manager.cancel-leaves', [
            'requests' => $requests,
            'departments' => $departments,
        ]);
    }

    /**
     * API: cancel a single leave date and refund credits.
     * Expects: leave_id, date, reason
     */
    public function apiCancelDate(Request $request)
    {
        $request->validate([
            'leave_id' => 'required|integer',
            'date' => 'required|date',
            'reason' => 'required|string'
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
     * Simple employee search for autocomplete.
     * Query param: q
     */
    public function employeeSearch(Request $request)
    {
        $q = $request->query('q', '');
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
        $data = $request->only(['id', 'tardiness', 'undertime', 'deduction_days', 'deduct_from']);

        $id = $data['id'] ?? null;
        if (!$id) {
            return response()->json(['message' => 'Missing id'], 422);
        }

        $balance = LeaveBalance::find($id);
        if (!$balance) {
            return response()->json(['message' => 'Balance not found'], 404);
        }

        $deduction = isset($data['deduction_days']) ? (float) $data['deduction_days'] : 0.0;
        $deductFrom = $data['deduct_from'] ?? 'NONE';

        $allowed = ['VL', 'SL', 'CTO', 'SPL', 'WLNS', 'SP'];
        if ($deductFrom !== 'NONE' && !in_array($deductFrom, $allowed, true)) {
            return response()->json(['message' => 'Invalid deduct_from field'], 422);
        }

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
