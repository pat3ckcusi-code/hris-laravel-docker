<?php

namespace App\Http\Controllers;

use App\Console\Commands\ProcessMonthlyLeaveCredits;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\MonthlyAttendance;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use App\Services\LeaveCardExportService;
use App\Services\LeaveCreditComputationService;
use App\Services\LeaveLedgerService;
use App\Services\LwopAggregationService;
use App\Support\HrisConstants;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        $balances = LeaveBalance::with('user')
            ->join('users', 'leave_balances.user_id', '=', 'users.id')
            ->orderBy('users.EmpNo')
            ->select('leave_balances.*')
            ->get();

        $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();

        return view('leave-manager.manage-balance', [
            'balances' => $balances,
            'departments' => $departments,
            'employeeTypes' => HrisConstants::EMPLOYEE_TYPES,
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
            'employeeTypes' => HrisConstants::EMPLOYEE_TYPES,
        ]);
    }

    /**
     * Show the approved leaves page.
     */
    public function approvedLeaves(Request $request)
    {
        $query = LeaveRequest::with('user')
            ->where('status', 'approved');

        $month = $request->query('month', date('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = date('Y-m');
        }
        $start = $month.'-01';
        $end = date('Y-m-t', strtotime($start));

        $query->where(function ($q) use ($start, $end) {
            $q->whereBetween('start_date', [$start, $end])
                ->orWhereBetween('end_date', [$start, $end])
                ->orWhere(function ($q2) use ($start, $end) {
                    $q2->where('start_date', '<=', $start)->where('end_date', '>=', $end);
                });
        });

        $emp = $request->query('emp');
        if ($emp) {
            $query->whereHas('user', function ($q) use ($emp) {
                $q->where('EmpNo', $emp);
            });
        }

        $leaveType = $request->query('type', '');
        if ($leaveType !== '') {
            $query->where('leave_type', 'LIKE', '%'.$leaveType.'%');
        }

        $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();

        if ($request->ajax()) {
            $items = $query->orderBy('date_filed', 'desc')->get();
            $rows = $items->map(fn ($item) => [
                'id' => $item->id,
                'employee' => $item->user
                    ? trim(($item->user->last_name ?? '').', '.($item->user->first_name ?? ''))
                    : '-',
                'department' => ($item->user && isset($departments[$item->user->Dept_id]))
                    ? $departments[$item->user->Dept_id]
                    : '-',
                'leave_type' => strtoupper($item->leave_type ?? ''),
                'start_date' => $item->start_date
                    ? Carbon::parse($item->start_date)->format('M d, Y') : '-',
                'end_date' => $item->end_date
                    ? Carbon::parse($item->end_date)->format('M d, Y') : '-',
                'total_days' => $item->total_days ?? '-',
                'date_filed' => $item->date_filed
                    ? Carbon::parse($item->date_filed)->format('M d, Y') : '-',
                'cancellation_status' => $item->cancellation_status ?? '',
            ]);

            return response()->json(['rows' => $rows]);
        }

        $leaves = $query->orderBy('date_filed', 'desc')->paginate(25);

        return view('leave-manager.approved-leaves', [
            'leaves' => $leaves,
            'departments' => $departments,
            'currentMonth' => $month,
        ]);
    }

    /**
     * Show the employee cancellation requests page.
     */
    public function employeeCancellationRequests(Request $request)
    {
        $query = LeaveRequest::with(['user', 'leaveDates'])
            ->where('status', 'approved')
            ->where('cancellation_status', 'AO Endorsed');

        $emp = $request->query('emp');
        if ($emp) {
            $query->whereHas('user', function ($q) use ($emp) {
                $q->where('EmpNo', $emp);
            });
        }

        $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();

        if ($request->ajax()) {
            $items = $query->orderBy('cancellation_requested_at', 'desc')->get();
            $rows = $items->map(fn ($item) => [
                'id' => $item->id,
                'employee' => $item->user
                    ? trim(($item->user->last_name ?? '').', '.($item->user->first_name ?? ''))
                    : '-',
                'department' => ($item->user && isset($departments[$item->user->Dept_id]))
                    ? $departments[$item->user->Dept_id]
                    : '-',
                'leave_type' => strtoupper($item->leave_type ?? ''),
                'start_date' => $item->start_date ? Carbon::parse($item->start_date)->format('M d, Y') : '-',
                'end_date' => $item->end_date ? Carbon::parse($item->end_date)->format('M d, Y') : '-',
                'start_date_raw' => $item->start_date ?? '',
                'end_date_raw' => $item->end_date ?? '',
                'designation' => $item->user?->designation ?? '',
                'cancellation_reason' => $item->cancellation_reason ?? '-',
                'status' => ucfirst($item->status ?? '-'),
                'requested_at' => $item->cancellation_requested_at
                    ? Carbon::parse($item->cancellation_requested_at)->format('M d, Y H:i')
                    : '-',
                'requested_human' => $item->cancellation_requested_at
                    ? Carbon::parse($item->cancellation_requested_at)->diffForHumans()
                    : '-',
                'dh_remarks' => $item->cancellation_dh_remarks ?? '-',
                'ao_remarks' => $item->cancellation_ao_remarks ?? '-',
            ]);

            return response()->json(['rows' => $rows]);
        }

        $requests = $query->orderBy('cancellation_requested_at', 'desc')->paginate(25);

        return view('leave-manager.employee-cancellation-requests', [
            'requests' => $requests,
            'departments' => $departments,
        ]);
    }

    /**
     * API: pending employee cancellation requests badge count.
     */
    public function apiPendingCancellationCount(): JsonResponse
    {
        $count = LeaveRequest::where('status', 'approved')
            ->where('cancellation_status', 'AO Endorsed')
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Approve a pending cancellation request for an entire leave.
     * Expects: leave_id, remarks (optional)
     */
    public function apiApproveCancellation(Request $request, $leave)
    {
        $leave = LeaveRequest::with(['user', 'leaveDates'])->find($leave);
        if (! $leave) {
            return response()->json(['error' => 'Leave not found'], 404);
        }

        if ($leave->cancellation_status !== 'AO Endorsed') {
            return response()->json(['error' => 'Leave cancellation must be endorsed by the Administrative Officer first'], 422);
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
            if (! empty($leave->printing_deduction_details)) {
                try {
                    $applied = json_decode($leave->printing_deduction_details, true) ?: [];
                } catch (\Exception $e) {
                    $applied = [];
                }
            }

            if (! empty($applied) && $lb) {
                // restore per-type amounts from applied deduction log
                $candidates = [
                    'VL' => ['balance_vacation_leave', 'vl', 'VL'],
                    'SL' => ['balance_sick_leave', 'sl', 'SL'],
                    'WLNS' => ['balance_wellness_leave', 'wlns', 'WLNS'],
                    'SPL' => ['balance_special_leave_privilege', 'spl', 'SPL'],
                    'CTO' => ['balance_cto', 'cto', 'CTO'],
                    'SP' => ['balance_solo_parent_leave', 'sp', 'SP'],
                ];
                $restored = [];
                foreach ($applied as $type => $amt) {
                    if (! is_numeric($amt) || floatval($amt) <= 0) {
                        continue;
                    }
                    $key = strtoupper((string) $type);
                    $found = null;
                    foreach ($candidates[$key] as $cand) {
                        if (array_key_exists($cand, $lb->getAttributes()) || isset($lb->{$cand})) {
                            $found = $cand;
                            break;
                        }
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
                    $type = strtolower((string) ($ld->leave_type ?? $leave->leave_type ?? ''));
                    if ($lb) {
                        if (strpos($type, 'vacation') !== false) {
                            $lb->VL = ($lb->VL ?? 0) + $days;
                        } elseif (strpos($type, 'sick') !== false) {
                            $lb->SL = ($lb->SL ?? 0) + $days;
                        } elseif (strpos($type, 'wellness') !== false) {
                            $lb->WLNS = ($lb->WLNS ?? 0) + $days;
                        } elseif (strpos($type, 'solo') !== false) {
                            $lb->SP = ($lb->SP ?? 0) + $days;
                        } elseif (strpos($type, 'special') !== false || strpos($type, 'spl') !== false) {
                            $lb->SPL = ($lb->SPL ?? 0) + $days;
                        }
                    }
                    $ld->is_cancelled = true;
                    $ld->cancel_reason = $leave->cancellation_reason ?? 'Cancelled by manager approval';
                    $ld->cancelled_by = Auth::id();
                    $ld->cancelled_at = now();
                    $ld->save();
                }

                if ($lb) {
                    $lb->save();
                }
            }

            // fallback refund if no leaveDates
            if ($dates->isEmpty() && $leave->paid_days > 0 && $lb) {
                $tn = strtolower((string) $leave->leave_type);
                $days = $leave->paid_days;
                if (strpos($tn, 'vacation') !== false) {
                    $lb->VL = ($lb->VL ?? 0) + $days;
                } elseif (strpos($tn, 'sick') !== false) {
                    $lb->SL = ($lb->SL ?? 0) + $days;
                } elseif (strpos($tn, 'wellness') !== false) {
                    $lb->WLNS = ($lb->WLNS ?? 0) + $days;
                } elseif (strpos($tn, 'solo') !== false) {
                    $lb->SP = ($lb->SP ?? 0) + $days;
                } elseif (strpos($tn, 'special') !== false || strpos($tn, 'spl') !== false) {
                    $lb->SPL = ($lb->SPL ?? 0) + $days;
                }
                $lb->save();
            }

            $leave->status = 'cancelled';
            $leave->detailed_status = 'Cancelled';
            $leave->cancellation_status = 'Cancelled';
            $leave->cancellation_reviewed_by = Auth::id();
            $leave->cancellation_reviewed_at = now();
            $leave->cancellation_remarks = $request->input('remarks') ?? null;
            $leave->save();

            DB::commit();

            try {
                app(LeaveLedgerService::class)->writeLedgerEntry([
                    'user_id' => $leave->user_id,
                    'transaction_date' => now()->toDateString(),
                    'transaction_type' => 'LEAVE_CANCELLED',
                    'leave_type' => ! empty($applied) ? implode('+', array_keys($applied)) : 'VL',
                    'credit_vl' => floatval($applied['VL'] ?? 0),
                    'credit_sl' => floatval($applied['SL'] ?? 0),
                    'debit_vl' => 0,
                    'debit_sl' => 0,
                    'reference_id' => $leave->id,
                    'reference_type' => 'leave_request',
                    'created_by' => Auth::id(),
                    'is_system' => false,
                    'remarks' => $leave->cancellation_reason ?? null,
                ]);
            } catch (\Throwable $ledgerEx) {
                Log::error('LeaveLedger write failed on cancellation', ['leave_id' => $leave->id, 'error' => $ledgerEx->getMessage()]);
            }

            // Log audit
            Log::info('Cancellation approved', [
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

            return response()->json(['error' => 'Failed to approve cancellation: '.$e->getMessage()], 500);
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
        if (! $leave) {
            return response()->json(['error' => 'Leave not found'], 404);
        }

        if ($leave->cancellation_status !== 'AO Endorsed') {
            return response()->json(['error' => 'Leave cancellation must be endorsed by the Administrative Officer first'], 422);
        }

        DB::beginTransaction();
        try {
            $user = $leave->user;
            $lb = $user?->leaveBalance;

            // Un-cancelling previously-cancelled dates restores those leave days,
            // so we deduct credits back (they were refunded when apiCancelDate ran).
            $cancelledDates = $leave->leaveDates()->where('is_cancelled', true)->get();
            foreach ($cancelledDates as $ld) {
                $days = $ld->days ?? 1.0;
                $type = strtolower((string) ($ld->leave_type ?? $leave->leave_type ?? ''));
                if ($lb) {
                    if (strpos($type, 'vacation') !== false) {
                        $lb->VL = max(0.0, ($lb->VL ?? 0) - $days);
                    } elseif (strpos($type, 'sick') !== false) {
                        $lb->SL = max(0.0, ($lb->SL ?? 0) - $days);
                    } elseif (strpos($type, 'wellness') !== false) {
                        $lb->WLNS = max(0.0, ($lb->WLNS ?? 0) - $days);
                    } elseif (strpos($type, 'solo') !== false) {
                        $lb->SP = max(0.0, ($lb->SP ?? 0) - $days);
                    } elseif (strpos($type, 'special') !== false || strpos($type, 'spl') !== false) {
                        $lb->SPL = max(0.0, ($lb->SPL ?? 0) - $days);
                    }
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
            Log::info('Cancellation rejected', [
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
                            'Start Date' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                            'End Date' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                        ],
                        actor: Auth::user()->name,
                        notes: $leave->cancellation_remarks ?? null,
                    ));
                }
            } catch (\Exception $ex) {
            }

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to reject cancellation: '.$e->getMessage()], 500);
        }
    }

    /**
     * Bulk approve multiple pending cancellation requests.
     * Expects: leave_ids[] (array of IDs)
     */
    public function apiBulkApproveCancellation(Request $request): JsonResponse
    {
        $request->validate([
            'leave_ids' => 'required|array|min:1|max:100',
            'leave_ids.*' => 'required|integer|min:1',
        ]);

        $ids = $request->input('leave_ids');
        $processed = 0;
        $errors = [];

        foreach ($ids as $leaveId) {
            $leave = LeaveRequest::with(['user', 'leaveDates'])->find($leaveId);
            if (! $leave) {
                $errors[] = "Leave #{$leaveId}: not found";

                continue;
            }
            if ($leave->cancellation_status !== 'AO Endorsed') {
                $errors[] = "Leave #{$leaveId}: cancellation not yet endorsed by AO";

                continue;
            }
            if ($leave->status !== 'approved') {
                $errors[] = "Leave #{$leaveId}: leave is not approved";

                continue;
            }

            DB::beginTransaction();
            try {
                $user = $leave->user;
                $lb = $user->leaveBalance ?? null;

                $applied = [];
                if (! empty($leave->printing_deduction_details)) {
                    try {
                        $applied = json_decode($leave->printing_deduction_details, true) ?: [];
                    } catch (\Exception $e) {
                        $applied = [];
                    }
                }

                if (! empty($applied) && $lb) {
                    $candidates = [
                        'VL' => ['balance_vacation_leave', 'vl', 'VL'],
                        'SL' => ['balance_sick_leave', 'sl', 'SL'],
                        'WLNS' => ['balance_wellness_leave', 'wlns', 'WLNS'],
                        'SPL' => ['balance_special_leave_privilege', 'spl', 'SPL'],
                        'CTO' => ['balance_cto', 'cto', 'CTO'],
                        'SP' => ['balance_solo_parent_leave', 'sp', 'SP'],
                    ];
                    $restored = [];
                    foreach ($applied as $type => $amt) {
                        if (! is_numeric($amt) || floatval($amt) <= 0) {
                            continue;
                        }
                        $key = strtoupper((string) $type);
                        if (! isset($candidates[$key])) {
                            continue;
                        }
                        $found = null;
                        foreach ($candidates[$key] as $cand) {
                            if (array_key_exists($cand, $lb->getAttributes()) || isset($lb->{$cand})) {
                                $found = $cand;
                                break;
                            }
                        }
                        if ($found) {
                            $lb->{$found} = floatval($lb->{$found} ?? 0) + floatval($amt);
                            $restored[$key] = floatval($amt);
                        }
                    }
                    $lb->save();

                    $dates = $leave->leaveDates()->where('is_cancelled', false)->get();
                    foreach ($dates as $ld) {
                        $ld->is_cancelled = true;
                        $ld->cancel_reason = $leave->cancellation_reason ?? 'Cancelled by manager (bulk)';
                        $ld->cancelled_by = Auth::id();
                        $ld->cancelled_at = now();
                        $ld->save();
                    }

                    try {
                        HRAuditTrail::create([
                            'actor_user_id' => Auth::id(),
                            'module' => 'leave',
                            'action' => 'cancel_restore_balances',
                            'target_type' => 'leave_request',
                            'target_id' => $leave->id,
                            'details' => ['restored' => $restored, 'bulk' => true],
                        ]);
                    } catch (\Exception $e) {
                    }
                } else {
                    $dates = $leave->leaveDates()->where('is_cancelled', false)->get();
                    foreach ($dates as $ld) {
                        $days = $ld->days ?? 1.0;
                        $type = strtolower((string) ($ld->leave_type ?? $leave->leave_type ?? ''));
                        if ($lb) {
                            if (strpos($type, 'vacation') !== false) {
                                $lb->VL = ($lb->VL ?? 0) + $days;
                            } elseif (strpos($type, 'sick') !== false) {
                                $lb->SL = ($lb->SL ?? 0) + $days;
                            } elseif (strpos($type, 'wellness') !== false) {
                                $lb->WLNS = ($lb->WLNS ?? 0) + $days;
                            } elseif (strpos($type, 'solo') !== false) {
                                $lb->SP = ($lb->SP ?? 0) + $days;
                            } elseif (strpos($type, 'special') !== false || strpos($type, 'spl') !== false) {
                                $lb->SPL = ($lb->SPL ?? 0) + $days;
                            }
                        }
                        $ld->is_cancelled = true;
                        $ld->cancel_reason = $leave->cancellation_reason ?? 'Cancelled by manager (bulk)';
                        $ld->cancelled_by = Auth::id();
                        $ld->cancelled_at = now();
                        $ld->save();
                    }
                    if ($lb) {
                        $lb->save();
                    }

                    if ($dates->isEmpty() && ($leave->paid_days ?? 0) > 0 && $lb) {
                        $tn = strtolower((string) $leave->leave_type);
                        $days = $leave->paid_days;
                        if (strpos($tn, 'vacation') !== false) {
                            $lb->VL = ($lb->VL ?? 0) + $days;
                        } elseif (strpos($tn, 'sick') !== false) {
                            $lb->SL = ($lb->SL ?? 0) + $days;
                        } elseif (strpos($tn, 'wellness') !== false) {
                            $lb->WLNS = ($lb->WLNS ?? 0) + $days;
                        } elseif (strpos($tn, 'solo') !== false) {
                            $lb->SP = ($lb->SP ?? 0) + $days;
                        } elseif (strpos($tn, 'special') !== false || strpos($tn, 'spl') !== false) {
                            $lb->SPL = ($lb->SPL ?? 0) + $days;
                        }
                        $lb->save();
                    }
                }

                $leave->status = 'cancelled';
                $leave->detailed_status = 'Cancelled';
                $leave->cancellation_status = 'Cancelled';
                $leave->cancellation_reviewed_by = Auth::id();
                $leave->cancellation_reviewed_at = now();
                $leave->save();

                DB::commit();

                try {
                    app(LeaveLedgerService::class)->writeLedgerEntry([
                        'user_id' => $leave->user_id,
                        'transaction_date' => now()->toDateString(),
                        'transaction_type' => 'LEAVE_CANCELLED',
                        'leave_type' => ! empty($applied) ? implode('+', array_keys($applied)) : 'VL',
                        'credit_vl' => floatval($applied['VL'] ?? 0),
                        'credit_sl' => floatval($applied['SL'] ?? 0),
                        'debit_vl' => 0,
                        'debit_sl' => 0,
                        'reference_id' => $leave->id,
                        'reference_type' => 'leave_request',
                        'created_by' => Auth::id(),
                        'is_system' => false,
                        'remarks' => $leave->cancellation_reason ?? null,
                    ]);
                } catch (\Throwable $ledgerEx) {
                    Log::error('LeaveLedger write failed on bulk cancellation', ['leave_id' => $leave->id, 'error' => $ledgerEx->getMessage()]);
                }

                $processed++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $errors[] = "Leave #{$leaveId}: ".$e->getMessage();
            }
        }

        return response()->json(['success' => true, 'processed' => $processed, 'errors' => $errors]);
    }

    /**
     * Bulk reject multiple pending cancellation requests.
     * Expects: leave_ids[] (array of IDs), remarks (required)
     */
    public function apiBulkRejectCancellation(Request $request): JsonResponse
    {
        $request->validate([
            'leave_ids' => 'required|array|min:1|max:100',
            'leave_ids.*' => 'required|integer|min:1',
            'remarks' => 'required|string|max:2000',
        ]);

        $ids = $request->input('leave_ids');
        $remarks = $request->input('remarks');
        $processed = 0;
        $errors = [];

        foreach ($ids as $leaveId) {
            $leave = LeaveRequest::with(['user', 'leaveDates'])->find($leaveId);
            if (! $leave) {
                $errors[] = "Leave #{$leaveId}: not found";

                continue;
            }
            if ($leave->cancellation_status !== 'AO Endorsed') {
                $errors[] = "Leave #{$leaveId}: cancellation not yet endorsed by AO";

                continue;
            }

            DB::beginTransaction();
            try {
                $lb = $leave->user?->leaveBalance;

                $cancelledDates = $leave->leaveDates()->where('is_cancelled', true)->get();
                foreach ($cancelledDates as $ld) {
                    $days = $ld->days ?? 1.0;
                    $type = strtolower((string) ($ld->leave_type ?? $leave->leave_type ?? ''));
                    if ($lb) {
                        if (strpos($type, 'vacation') !== false) {
                            $lb->VL = max(0.0, ($lb->VL ?? 0) - $days);
                        } elseif (strpos($type, 'sick') !== false) {
                            $lb->SL = max(0.0, ($lb->SL ?? 0) - $days);
                        } elseif (strpos($type, 'wellness') !== false) {
                            $lb->WLNS = max(0.0, ($lb->WLNS ?? 0) - $days);
                        } elseif (strpos($type, 'solo') !== false) {
                            $lb->SP = max(0.0, ($lb->SP ?? 0) - $days);
                        } elseif (strpos($type, 'special') !== false || strpos($type, 'spl') !== false) {
                            $lb->SPL = max(0.0, ($lb->SPL ?? 0) - $days);
                        }
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
                $leave->cancellation_remarks = $remarks;
                $leave->save();

                DB::commit();
                $processed++;

                try {
                    if ($leave->user) {
                        $leave->user->notify(new HrisTransactionNotification(
                            requestType: 'Leave Request',
                            status: 'Cancellation Rejected',
                            details: [
                                'Leave Type' => $leave->leave_type ?? 'N/A',
                                'Start Date' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                                'End Date' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                            ],
                            actor: Auth::user()->name,
                            notes: $remarks,
                        ));
                    }
                } catch (\Exception $ex) {
                }
            } catch (\Throwable $e) {
                DB::rollBack();
                $errors[] = "Leave #{$leaveId}: ".$e->getMessage();
            }
        }

        return response()->json(['success' => true, 'processed' => $processed, 'errors' => $errors]);
    }

    /**
     * Simple employee search for autocomplete.
     * Query param: q
     */
    public function employeeSearch(Request $request)
    {
        $q = substr(trim((string) $request->query('q', '')), 0, 100);
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $rows = User::active()
            ->with(['department:Dept_id,Dept_name'])
            ->select(['id', 'EmpNo', 'first_name', 'last_name', 'designation', 'Dept_id'])
            ->where(function ($w) use ($q) {
                $w->where('EmpNo', 'like', "%{$q}%")
                    ->orWhere('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%");
            })
            ->limit(12)
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'EmpNo' => $u->EmpNo,
                    'first_name' => $u->first_name,
                    'last_name' => $u->last_name,
                    'FullName' => trim((($u->last_name ?? '').', '.($u->first_name ?? ''))),
                    'Position' => $u->designation ?? '',
                    'department' => $u->department ? ['Dept_id' => $u->department->Dept_id, 'Dept_name' => $u->department->Dept_name] : null,
                ];
            });

        return response()->json($rows);
    }

    /**
     * Apply credits/deductions for a given leave balance row.
     * Expected input: id, tardiness (min), undertime (min), deduction_days (float), deduct_from (VL|SL|NONE)
     */
    public function applyCredits(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|min:1',
            'tardiness' => 'nullable|integer|min:0|max:14400',
            'undertime' => 'nullable|integer|min:0|max:14400',
            'deduction_days' => 'nullable|numeric|min:0|max:365',
            'deduct_from' => 'nullable|string|in:VL,SL,NONE',
        ]);

        $balance = LeaveBalance::find($data['id']);
        if (! $balance) {
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
        $validated = $request->validate([
            'field' => ['required', 'string', 'in:VL,SL,WLNS,SPL,CTO,SP'],
            'value' => ['required', 'numeric', 'min:0'],
        ]);

        $field = $validated['field'];
        $value = (float) $validated['value'];

        $balance->{$field} = $value;
        $balance->save();

        return response()->json(['message' => 'Updated', 'balance' => $balance->fresh()]);
    }

    /**
     * Notify the Department Head that an employee has a critically low leave balance.
     */
    public function apiNotifyDeptHead(Request $request): JsonResponse
    {
        $validated = $request->validate(['balance_id' => 'required|integer|exists:leave_balances,id']);

        $balance = LeaveBalance::with('user')->findOrFail($validated['balance_id']);
        $employee = $balance->user;

        if (! $employee) {
            return response()->json(['success' => false, 'message' => 'Employee record not found.'], 404);
        }

        $dept = Department::where('Dept_id', $employee->Dept_id)->first();
        if (! $dept || ! $dept->EmpNo) {
            return response()->json(['success' => false, 'message' => 'No Department Head is assigned to this department.'], 404);
        }

        $deptHead = User::where('EmpNo', $dept->EmpNo)->first();
        if (! $deptHead) {
            return response()->json(['success' => false, 'message' => 'Department Head account not found.'], 404);
        }

        $empName = trim(($employee->last_name ?? '').', '.($employee->first_name ?? ''));
        $deptName = $dept->Dept_name ?? 'their department';

        $deptHead->notify(new HrisTransactionNotification(
            requestType: 'Leave Balance Alert',
            status: 'Critical Balance - Action Required',
            details: [
                'Employee' => $empName,
                'Department' => $deptName,
                'Vacation Leave (VL)' => number_format((float) ($balance->VL ?? 0), 3).' days',
                'Sick Leave (SL)' => number_format((float) ($balance->SL ?? 0), 3).' days',
                'Notice' => 'This employee has a critically low leave balance (≤ 5 days). Please review and coordinate as needed.',
            ],
            actor: Auth::user()?->name ?? 'Leave Manager',
            notes: 'Sent from the Leave Manager Dashboard.',
        ));

        HRAuditTrail::create([
            'actor_user_id' => Auth::id(),
            'module' => 'Leave Manager',
            'action' => 'notify_dept_head',
            'target_type' => 'App\\Models\\LeaveBalance',
            'target_id' => $balance->id,
            'details' => [
                'employee_id' => $employee->id,
                'employee_name' => $empName,
                'dept_head_id' => $deptHead->id,
                'dept_head_empno' => $deptHead->EmpNo,
            ],
        ]);

        return response()->json(['success' => true, 'message' => "Department Head ({$deptHead->name}) has been notified."]);
    }

    public function downloadLeaveCard(Request $request): StreamedResponse
    {
        $userId = (int) $request->input('user_id');
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        if (! $userId || ! $year || $month < 1 || $month > 12) {
            abort(422, 'Invalid parameters: user_id, year, and month (1–12) are required.');
        }

        $user = User::findOrFail($userId);

        return app(LeaveCardExportService::class)->generateExcelResponse($user, $year, $month);
    }

    public function leaveLedger(Request $request)
    {
        $employees = User::active()
            ->whereHas('leaveBalance')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'EmpNo', 'Dept_id']);

        $departments = Department::pluck('Dept_name', 'Dept_id')->toArray();
        $currentYear = now()->year;
        $years = range($currentYear, max(2020, $currentYear - 6));

        $lastMonth = Carbon::now()->subMonthNoOverflow();
        $lastMonthProcessed = MonthlyAttendance::where('year', $lastMonth->year)
            ->where('month', $lastMonth->month)
            ->whereNotNull('processed_at')
            ->exists();

        return view('leave-manager.leave-ledger', [
            'employees' => $employees,
            'departments' => $departments,
            'years' => $years,
            'currentYear' => $currentYear,
            'lastMonthYear' => $lastMonth->year,
            'lastMonthMonth' => $lastMonth->month,
            'lastMonthLabel' => $lastMonth->format('F Y'),
            'lastMonthProcessed' => $lastMonthProcessed,
        ]);
    }

    public function runMonthlyCredits(Request $request): JsonResponse
    {
        $request->validate([
            'year' => ['required', 'integer', 'min:2020'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        $lastCreditableMonth = Carbon::now()->subMonthNoOverflow();
        $requested = Carbon::create($year, $month, 1);

        if ($requested->greaterThan($lastCreditableMonth->copy()->startOfMonth())) {
            return response()->json([
                'message' => 'Cannot process a future or the current (incomplete) month.',
            ], 422);
        }

        $result = app(ProcessMonthlyLeaveCredits::class)->processBatch($year, $month, null, false);

        HRAuditTrail::create([
            'actor_user_id' => Auth::id(),
            'module' => 'leave',
            'action' => 'monthly_credit_run',
            'target_type' => 'App\\Models\\MonthlyAttendance',
            'target_id' => null,
            'details' => [
                'year' => $year,
                'month' => $month,
                'processed' => $result['processed'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'triggered_by' => Auth::id(),
            ],
        ]);

        return response()->json($result);
    }

    /**
     * Correct an already-processed month whose leave data changed afterward. Posts only
     * the delta between the newly-computed and previously-recorded VL/SL (never the full
     * recomputed amount) so the employee's balance isn't double-credited.
     */
    public function recomputeEmployeeMonth(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'min:2020'],
            'month' => ['required', 'integer', 'between:1,12'],
        ]);

        $userId = (int) $request->input('user_id');
        $year = (int) $request->input('year');
        $month = (int) $request->input('month');

        $attendance = MonthlyAttendance::where('user_id', $userId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if (! $attendance || $attendance->processed_at === null) {
            return response()->json([
                'message' => 'This month has not been processed yet — use Run Monthly Credits instead.',
            ], 422);
        }

        $user = User::findOrFail($userId);

        $oldVl = (float) $attendance->computed_vl;
        $oldSl = (float) $attendance->computed_sl;
        $originalProcessedAt = $attendance->processed_at;

        $aggregate = app(LwopAggregationService::class)->computeForMonth($user, $year, $month);
        $attendance->days_present = $aggregate['days_present'];
        $attendance->abs_wop_days = $aggregate['abs_wop_days'];

        $result = app(LeaveCreditComputationService::class)->computeMonthlyCredit($user, $attendance);
        $newVl = $result['vl_earned'];
        $newSl = $result['sl_earned'];

        $deltaVl = round($newVl - $oldVl, 3);
        $deltaSl = round($newSl - $oldSl, 3);

        $epsilon = 0.0005;
        $hasChange = abs($deltaVl) > $epsilon || abs($deltaSl) > $epsilon;

        if ($hasChange) {
            app(LeaveLedgerService::class)->writeLedgerEntry([
                'user_id' => $userId,
                'transaction_date' => Carbon::create($year, $month, 1)->endOfMonth()->toDateString(),
                'transaction_type' => 'CREDIT_CORRECTION',
                'leave_type' => 'VL+SL',
                'days_present' => $attendance->days_present,
                'abs_wop_days' => $attendance->abs_wop_days > 0 ? $attendance->abs_wop_days : null,
                'credit_vl' => $deltaVl > 0 ? $deltaVl : 0,
                'credit_sl' => $deltaSl > 0 ? $deltaSl : 0,
                'debit_vl' => $deltaVl < 0 ? abs($deltaVl) : 0,
                'debit_sl' => $deltaSl < 0 ? abs($deltaSl) : 0,
                'is_system' => true,
                'created_by' => Auth::id(),
                'remarks' => "Correction: leave data changed after original processing on {$originalProcessedAt->format('Y-m-d H:i')}.",
            ]);
        }

        $attendance->computed_vl = $newVl;
        $attendance->computed_sl = $newSl;
        $attendance->processed_at = now();
        $attendance->processed_by = Auth::id();
        $attendance->save();

        HRAuditTrail::create([
            'actor_user_id' => Auth::id(),
            'module' => 'leave',
            'action' => 'monthly_credit_correction',
            'target_type' => 'App\\Models\\MonthlyAttendance',
            'target_id' => $attendance->id,
            'details' => [
                'user_id' => $userId,
                'year' => $year,
                'month' => $month,
                'old_vl' => $oldVl,
                'old_sl' => $oldSl,
                'new_vl' => $newVl,
                'new_sl' => $newSl,
                'delta_vl' => $deltaVl,
                'delta_sl' => $deltaSl,
                'triggered_by' => Auth::id(),
            ],
        ]);

        return response()->json([
            'changed' => $hasChange,
            'delta_vl' => $deltaVl,
            'delta_sl' => $deltaSl,
            'new_vl' => $newVl,
            'new_sl' => $newSl,
        ]);
    }

    public function apiLedgerHistory(Request $request): JsonResponse
    {
        $userId = (int) $request->input('user_id');
        if (! $userId) {
            return response()->json(['data' => []]);
        }

        $filters = [];
        if ($request->filled('year')) {
            $filters['year'] = (int) $request->input('year');
        }
        if ($request->filled('transaction_type')) {
            $filters['transaction_type'] = $request->input('transaction_type');
        }

        $entries = app(LeaveLedgerService::class)->getLedgerHistory($userId, $filters);

        $data = $entries->map(fn ($e) => [
            'date' => $e->transaction_date?->toDateString(),
            'type' => $e->transaction_type,
            'leave_type' => $e->leave_type,
            'days_present' => $e->days_present !== null ? number_format($e->days_present, 3) : '-',
            'abs_wop_days' => $e->abs_wop_days !== null ? number_format($e->abs_wop_days, 3) : '-',
            'credit_vl' => number_format($e->credit_vl ?? 0, 3),
            'credit_sl' => number_format($e->credit_sl ?? 0, 3),
            'debit_vl' => number_format($e->debit_vl ?? 0, 3),
            'debit_sl' => number_format($e->debit_sl ?? 0, 3),
            'vl_balance_after' => number_format($e->vl_balance_after, 3),
            'sl_balance_after' => number_format($e->sl_balance_after, 3),
            'remarks' => $e->remarks,
            'posted_by' => $e->is_system ? 'System' : ($e->createdBy ? trim(($e->createdBy->last_name ?? '').', '.($e->createdBy->first_name ?? '')) : '-'),
        ])->values();

        return response()->json(['data' => $data]);
    }

    public function apiMonthlyCredits(Request $request): JsonResponse
    {
        $year = (int) ($request->input('year', now()->year));
        $month = $request->filled('month') ? (int) $request->input('month') : null;

        $query = MonthlyAttendance::with('user')
            ->where('year', $year)
            ->whereHas('user', fn ($q) => $q->where('Status', 'Active'))
            ->join('users', 'monthly_attendance.user_id', '=', 'users.id')
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->orderBy('monthly_attendance.month')
            ->select('monthly_attendance.*');

        if ($month !== null) {
            $query->where('month', $month);
        }

        $records = $query->get();
        $departments = Department::pluck('Dept_name', 'Dept_id')->toArray();

        $userIds = $records->pluck('user_id')->unique()->values();
        $rangeStart = $month !== null ? Carbon::create($year, $month, 1)->startOfDay() : Carbon::create($year, 1, 1)->startOfDay();
        $rangeEnd = $month !== null
            ? $rangeStart->copy()->endOfMonth()->startOfDay()
            : Carbon::create($year, 12, 31)->startOfDay();

        $leaveRequestsByUser = LeaveRequest::whereIn('user_id', $userIds)
            ->where('start_date', '<=', $rangeEnd->toDateString())
            ->where('end_date', '>=', $rangeStart->toDateString())
            ->get(['user_id', 'start_date', 'end_date', 'updated_at'])
            ->groupBy('user_id');

        $data = $records->map(function ($r) use ($departments, $leaveRequestsByUser) {
            return [
                'emp_no' => $r->user?->EmpNo ?? '-',
                'name' => $r->user ? trim(($r->user->last_name ?? '').', '.($r->user->first_name ?? '')) : '-',
                'department' => $r->user ? ($departments[$r->user->Dept_id] ?? '-') : '-',
                'year' => $r->year,
                'month' => Carbon::create($r->year, $r->month, 1)->format('F'),
                'month_number' => $r->month,
                'user_id' => $r->user_id,
                'days_present' => number_format((float) $r->days_present, 3),
                'abs_wop_days' => number_format((float) $r->abs_wop_days, 3),
                'computed_vl' => $r->computed_vl !== null ? number_format($r->computed_vl, 3) : '-',
                'computed_sl' => $r->computed_sl !== null ? number_format($r->computed_sl, 3) : '-',
                'processed_at' => $r->processed_at ? $r->processed_at->format('Y-m-d H:i') : '-',
                'stale' => $this->isMonthlyCreditStale($r, $leaveRequestsByUser->get($r->user_id, collect())),
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    /**
     * A processed month is stale if any of the employee's leave requests overlapping
     * that month were created or edited after the credit was computed — the recorded
     * days_present/abs_wop_days no longer reflect current leave data.
     */
    private function isMonthlyCreditStale(MonthlyAttendance $attendance, $userLeaveRequests): bool
    {
        if ($attendance->processed_at === null) {
            return false;
        }

        $monthStart = Carbon::create($attendance->year, $attendance->month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        foreach ($userLeaveRequests as $leaveRequest) {
            $requestStart = Carbon::parse($leaveRequest->start_date)->startOfDay();
            $requestEnd = Carbon::parse($leaveRequest->end_date)->startOfDay();

            $overlaps = $requestStart->lessThanOrEqualTo($monthEnd) && $requestEnd->greaterThanOrEqualTo($monthStart);

            if ($overlaps && $leaveRequest->updated_at !== null && $leaveRequest->updated_at->greaterThan($attendance->processed_at)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Employees currently accumulating unauthorized absence (no attendance and nothing
     * on file to cover it), so HR can act before the CSC 30-working-day separation
     * threshold. Filtered to a 5-workday floor to avoid noise from routine isolated
     * absences.
     */
    public function apiAwolMonitor(Request $request): JsonResponse
    {
        $employees = User::active()
            ->whereIn('employee_type', User::LEAVE_ELIGIBLE_TYPES)
            ->whereHas('leaveBalance')
            ->get();

        $departments = Department::pluck('Dept_name', 'Dept_id')->toArray();
        $lwopService = app(LwopAggregationService::class);

        $rows = $employees->map(function ($employee) use ($departments, $lwopService) {
            $streak = $lwopService->computeCurrentAwolStreak($employee);

            if ($streak['streak'] < 5) {
                return null;
            }

            return [
                'emp_no' => $employee->EmpNo ?? '-',
                'name' => trim(($employee->last_name ?? '').', '.($employee->first_name ?? '')),
                'department' => $departments[$employee->Dept_id] ?? '-',
                'streak' => $streak['capped'] ? '60+' : (string) $streak['streak'],
                'streak_sort' => $streak['streak'],
                'streak_started_on' => $streak['streak_started_on'],
                'episodes_this_semester' => $lwopService->countAwolEpisodesThisSemester($employee),
                'status' => $this->awolSeverityLabel($streak['streak']),
            ];
        })->filter()->sortByDesc('streak_sort')->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Severity band for the AWOL Monitor. 30+ workdays is the CSC threshold for
     * separation without prior notice; below that, a Return-to-Work Order is required
     * first — these bands give HR lead time to act before it gets there.
     */
    private function awolSeverityLabel(int $streak): string
    {
        return match (true) {
            $streak >= 30 => 'critical',
            $streak >= 25 => 'urgent',
            $streak >= 15 => 'warning',
            default => 'watch',
        };
    }
}
