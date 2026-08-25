<?php

namespace App\Http\Controllers;

use App\Console\Commands\ProcessMonthlyLeaveCredits;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\LeaveBalance;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\MonthlyAttendance;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use App\Services\LeaveCardExportService;
use App\Services\LeaveCreditComputationService;
use App\Services\LeaveDateAggregateService;
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
            ->whereHas('user', fn ($q) => $q->active())
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
            ->whereHas('user', fn ($q) => $q->active())
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
        $query = LeaveRequest::with(['user', 'pendingCancellationDates', 'leaveDates'])
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
                'period' => $item->formattedPeriod(),
                'total_days' => $item->total_days ?? '-',
                'date_filed' => $item->date_filed
                    ? Carbon::parse($item->date_filed)->format('M d, Y') : '-',
                // Whole-row status takes priority; a partial (per-date) cancellation has
                // no whole-row status of its own, so fall back to whichever stage its
                // pending dates are at (see LeaveRequest::pendingCancellationDates()).
                'cancellation_status' => $item->cancellation_status ?? $item->pendingCancellationDates->first()?->cancellation_status ?? '',
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
            ->where(function ($q) {
                $q->where('cancellation_status', 'AO Endorsed')
                    ->orWhereHas('leaveDates', function ($dq) {
                        $dq->where('cancellation_status', 'AO Endorsed');
                    });
            });

        $emp = $request->query('emp');
        if ($emp) {
            $query->whereHas('user', function ($q) use ($emp) {
                $q->where('EmpNo', $emp);
            });
        }

        $departments = Department::query()->pluck('Dept_name', 'Dept_id')->toArray();

        if ($request->ajax()) {
            $items = $query->orderBy('cancellation_requested_at', 'desc')->get();
            $rows = $items->map(function ($item) use ($departments) {
                $pendingDates = $item->cancellation_status === 'AO Endorsed'
                    ? collect()
                    : $item->leaveDates->where('cancellation_status', 'AO Endorsed');

                return [
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
                    'cancellation_reason' => $item->cancellation_reason ?? ($pendingDates->first()->cancellation_reason ?? '-'),
                    'status' => ucfirst($item->status ?? '-'),
                    'requested_at' => $item->cancellation_requested_at
                        ? Carbon::parse($item->cancellation_requested_at)->format('M d, Y H:i')
                        : '-',
                    'requested_human' => $item->cancellation_requested_at
                        ? Carbon::parse($item->cancellation_requested_at)->diffForHumans()
                        : '-',
                    'dh_remarks' => $item->cancellation_dh_remarks ?? ($pendingDates->first()->cancellation_dh_remarks ?? '-'),
                    'ao_remarks' => $item->cancellation_ao_remarks ?? ($pendingDates->first()->cancellation_ao_remarks ?? '-'),
                    'partial' => $pendingDates->isNotEmpty(),
                    'pending_date_ids' => $pendingDates->pluck('id')->values(),
                    'pending_dates' => $pendingDates->pluck('leave_date')->map(fn ($d) => Carbon::parse($d)->format('M d, Y'))->values(),
                ];
            });

            return response()->json(['rows' => $rows]);
        }

        $requests = $query->orderBy('cancellation_requested_at', 'desc')->paginate(25);

        // History: cancellations the Leave Manager approved (status flips to 'Cancelled').
        // cancellation_reviewed_by is written exclusively by this controller's approve/reject
        // methods (whole-row or per-date) - DH/AO rejections only ever touch their own
        // cancellation_dh_*/cancellation_ao_* columns - so this filter can't accidentally
        // surface a DH-level or AO-level rejection here. Rejected requests are excluded too,
        // since this list is Cancelled-decisions only.
        $historyQuery = LeaveRequest::with(['user', 'leaveDates.cancellationReviewedBy', 'cancellationReviewedBy'])
            ->where(function ($q) {
                $q->where(function ($whole) {
                    $whole->whereNotNull('cancellation_reviewed_by')
                        ->where('cancellation_status', 'Cancelled');
                })->orWhereHas('leaveDates', function ($dq) {
                    $dq->whereNotNull('cancellation_reviewed_by')
                        ->where('cancellation_status', 'Cancelled');
                });
            });

        if ($emp) {
            $historyQuery->whereHas('user', function ($q) use ($emp) {
                $q->where('EmpNo', $emp);
            });
        }

        $history = $historyQuery->orderByDesc('cancellation_reviewed_at')
            ->paginate(15, ['*'], 'history_page');

        return view('leave-manager.employee-cancellation-requests', [
            'requests' => $requests,
            'history' => $history,
            'departments' => $departments,
        ]);
    }

    /**
     * API: pending employee cancellation requests badge count.
     */
    public function apiPendingCancellationCount(): JsonResponse
    {
        $count = LeaveRequest::where('status', 'approved')
            ->where(function ($q) {
                $q->where('cancellation_status', 'AO Endorsed')
                    ->orWhereHas('leaveDates', function ($dq) {
                        $dq->where('cancellation_status', 'AO Endorsed');
                    });
            })
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

            app(LeaveLedgerService::class)->writeLedgerEntry([
                'user_id' => $leave->user_id,
                'transaction_date' => $leave->start_date ?? now()->toDateString(),
                'period_end_date' => $leave->end_date,
                'transaction_type' => 'LEAVE_CANCELLED',
                'leave_type' => ! empty($applied) ? implode('+', array_keys($applied)) : 'VL',
                'credit_vl' => floatval($applied['VL'] ?? 0),
                'credit_sl' => floatval($applied['SL'] ?? 0),
                'credit_wlns' => floatval($applied['WLNS'] ?? 0),
                'credit_spl' => floatval($applied['SPL'] ?? 0),
                'credit_cto' => floatval($applied['CTO'] ?? 0),
                'credit_sp' => floatval($applied['SP'] ?? 0),
                'debit_vl' => 0,
                'debit_sl' => 0,
                'reference_id' => $leave->id,
                'reference_type' => 'leave_request',
                'created_by' => Auth::id(),
                'is_system' => false,
                'remarks' => $leave->cancellation_reason ?? null,
            ]);

            DB::commit();

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
     * Per-date variant of apiApproveCancellation(): final approval + balance refund for
     * a subset of dates on an approved multi-date leave. Leaves the untouched dates
     * approved; the parent row's aggregates are recomputed rather than the row being
     * unconditionally marked cancelled. Refund always uses the is_lwop-gated per-date
     * loop (the whole-row "applied" JSON shortcut can't be attributed to a subset).
     */
    public function apiApproveDateCancellation(Request $request, $leave): JsonResponse
    {
        $request->validate([
            'leave_date_ids' => 'required|array|min:1',
            'leave_date_ids.*' => 'integer',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $leave = LeaveRequest::with(['user', 'leaveDates'])->find($leave);
        if (! $leave) {
            return response()->json(['error' => 'Leave not found'], 404);
        }

        if ($leave->status !== 'approved') {
            return response()->json(['error' => 'Only approved leaves can be cancelled'], 422);
        }

        $dates = $leave->leaveDates()
            ->whereIn('id', $request->input('leave_date_ids'))
            ->where('cancellation_status', 'AO Endorsed')
            ->get();

        if ($dates->count() !== count($request->input('leave_date_ids'))) {
            return response()->json(['error' => 'One or more selected dates must be endorsed by the Administrative Officer first'], 422);
        }

        DB::beginTransaction();
        try {
            $user = $leave->user;
            $lb = $user->leaveBalance ?? null;
            $aggregateService = app(LeaveDateAggregateService::class);

            $restored = $aggregateService->refundDates($dates, $lb);
            if ($lb) {
                $lb->save();
            }

            foreach ($dates as $ld) {
                $ld->is_cancelled = true;
                $ld->cancel_reason = $ld->cancellation_reason ?? $request->input('remarks') ?? 'Cancelled by manager approval';
                $ld->cancelled_by = Auth::id();
                $ld->cancelled_at = now();
                $ld->cancellation_status = 'Cancelled';
                $ld->cancellation_reviewed_by = Auth::id();
                $ld->cancellation_reviewed_at = now();
                $ld->cancellation_remarks = $request->input('remarks');
                $ld->save();
            }

            $aggregateService->recomputeParentAfterDateChange($leave, 'Cancelled');

            HRAuditTrail::create([
                'actor_user_id' => Auth::id(),
                'module' => 'leave',
                'action' => 'partial_cancel_restore_balances',
                'target_type' => 'leave_request',
                'target_id' => $leave->id,
                'details' => [
                    'leave_date_ids' => $dates->pluck('id')->all(),
                    'restored' => $restored,
                    'cancelled_by' => Auth::id(),
                    'cancelled_at' => now()->toDateTimeString(),
                    'remarks' => $request->input('remarks'),
                ],
            ]);

            app(LeaveLedgerService::class)->writeLedgerEntry([
                'user_id' => $leave->user_id,
                'transaction_date' => $dates->min('leave_date') ?? now()->toDateString(),
                'period_end_date' => $dates->max('leave_date'),
                'transaction_type' => 'LEAVE_CANCELLED',
                'leave_type' => ! empty($restored) ? implode('+', array_keys($restored)) : 'VL',
                'credit_vl' => floatval($restored['VL'] ?? 0),
                'credit_sl' => floatval($restored['SL'] ?? 0),
                'credit_wlns' => floatval($restored['WLNS'] ?? 0),
                'credit_spl' => floatval($restored['SPL'] ?? 0),
                'credit_cto' => floatval($restored['CTO'] ?? 0),
                'credit_sp' => floatval($restored['SP'] ?? 0),
                'debit_vl' => 0,
                'debit_sl' => 0,
                'reference_id' => $leave->id,
                'reference_type' => 'leave_request',
                'created_by' => Auth::id(),
                'is_system' => false,
                'remarks' => 'Partial date cancellation',
            ]);

            DB::commit();

            Log::info('Partial cancellation approved', [
                'leave_id' => $leave->id,
                'leave_date_ids' => $dates->pluck('id')->all(),
                'employee_id' => $user->id,
                'manager_id' => Auth::id(),
            ]);

            return response()->json(['success' => true]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to approve cancellation: '.$e->getMessage()], 500);
        }
    }

    /**
     * Per-date variant of apiRejectCancellation(): rejects the pending cancellation for a
     * subset of dates. No balance change — nothing has been cancelled yet at this stage.
     */
    public function apiRejectDateCancellation(Request $request, $leave): JsonResponse
    {
        $request->validate([
            'leave_date_ids' => 'required|array|min:1',
            'leave_date_ids.*' => 'integer',
            'remarks' => 'required|string|max:2000',
        ]);

        $leave = LeaveRequest::with(['user', 'leaveDates'])->find($leave);
        if (! $leave) {
            return response()->json(['error' => 'Leave not found'], 404);
        }

        $dates = $leave->leaveDates()
            ->whereIn('id', $request->input('leave_date_ids'))
            ->where('cancellation_status', 'AO Endorsed')
            ->get();

        if ($dates->count() !== count($request->input('leave_date_ids'))) {
            return response()->json(['error' => 'One or more selected dates must be endorsed by the Administrative Officer first'], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($dates as $ld) {
                $ld->cancellation_status = 'Rejected';
                $ld->cancellation_reviewed_by = Auth::id();
                $ld->cancellation_reviewed_at = now();
                $ld->cancellation_remarks = $request->input('remarks');
                $ld->save();
            }
            DB::commit();

            Log::info('Partial cancellation rejected', [
                'leave_id' => $leave->id,
                'leave_date_ids' => $dates->pluck('id')->all(),
                'manager_id' => Auth::id(),
            ]);

            try {
                if ($leave->user) {
                    $leave->user->notify(new HrisTransactionNotification(
                        requestType: 'Leave Request',
                        status: 'Partial Cancellation Rejected',
                        details: [
                            'Leave Type' => $leave->leave_type ?? 'N/A',
                            'Dates' => $dates->pluck('leave_date')->map(fn ($d) => Carbon::parse($d)->format('M j, Y'))->implode(', '),
                        ],
                        actor: Auth::user()->name,
                        notes: $request->input('remarks'),
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

                app(LeaveLedgerService::class)->writeLedgerEntry([
                    'user_id' => $leave->user_id,
                    'transaction_date' => $leave->start_date ?? now()->toDateString(),
                    'period_end_date' => $leave->end_date,
                    'transaction_type' => 'LEAVE_CANCELLED',
                    'leave_type' => ! empty($applied) ? implode('+', array_keys($applied)) : 'VL',
                    'credit_vl' => floatval($applied['VL'] ?? 0),
                    'credit_sl' => floatval($applied['SL'] ?? 0),
                    'credit_wlns' => floatval($applied['WLNS'] ?? 0),
                    'credit_spl' => floatval($applied['SPL'] ?? 0),
                    'credit_cto' => floatval($applied['CTO'] ?? 0),
                    'credit_sp' => floatval($applied['SP'] ?? 0),
                    'debit_vl' => 0,
                    'debit_sl' => 0,
                    'reference_id' => $leave->id,
                    'reference_type' => 'leave_request',
                    'created_by' => Auth::id(),
                    'is_system' => false,
                    'remarks' => $leave->cancellation_reason ?? null,
                ]);

                DB::commit();

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

        if (! LeaveBalance::where('id', $data['id'])->exists()) {
            return response()->json(['message' => 'Balance not found'], 404);
        }

        $deduction = (float) ($data['deduction_days'] ?? 0);
        $deductFrom = $data['deduct_from'] ?? 'NONE';

        $balance = DB::transaction(function () use ($data, $deduction, $deductFrom, $request) {
            $balance = LeaveBalance::where('id', $data['id'])->lockForUpdate()->firstOrFail();

            if ($deduction > 0 && $deductFrom !== 'NONE') {
                // subtract deduction from chosen field, keeping nulls handled as 0
                $current = $balance->{$deductFrom} ?? 0;
                $new = $current - $deduction;
                // allow negative balances but round to 3 decimals
                $balance->{$deductFrom} = round($new, 3);
                $balance->save();

                app(LeaveLedgerService::class)->writeLedgerEntry([
                    'user_id' => $balance->user_id,
                    'transaction_date' => now()->toDateString(),
                    'transaction_type' => 'ATTENDANCE_DEDUCTION',
                    'leave_type' => $deductFrom,
                    'debit_vl' => $deductFrom === 'VL' ? $deduction : 0,
                    'debit_sl' => $deductFrom === 'SL' ? $deduction : 0,
                    'reference_id' => $balance->id,
                    'reference_type' => 'leave_balance',
                    'created_by' => Auth::id(),
                    'is_system' => false,
                    'remarks' => 'Tardiness/undertime deduction (tardiness: '.($data['tardiness'] ?? 0).'m, undertime: '.($data['undertime'] ?? 0).'m)',
                ]);

                HRAuditTrail::create([
                    'actor_user_id' => Auth::id(),
                    'module' => 'leave',
                    'action' => 'apply_credits',
                    'target_type' => 'leave_balance',
                    'target_id' => $balance->id,
                    'details' => [
                        'user_id' => $balance->user_id,
                        'tardiness_minutes' => $data['tardiness'] ?? 0,
                        'undertime_minutes' => $data['undertime'] ?? 0,
                        'deduction_days' => $deduction,
                        'deduct_from' => $deductFrom,
                        'timestamp' => now()->toDateTimeString(),
                    ],
                ]);
            }

            return $balance;
        });

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

        $balance = DB::transaction(function () use ($balance, $field, $value) {
            $balance = LeaveBalance::where('id', $balance->id)->lockForUpdate()->firstOrFail();

            $old = (float) ($balance->{$field} ?? 0);
            $delta = round($value - $old, 3);

            $balance->{$field} = $value;
            $balance->save();

            if ($delta !== 0.0) {
                $columnsByField = [
                    'VL' => ['credit' => 'credit_vl', 'debit' => 'debit_vl'],
                    'SL' => ['credit' => 'credit_sl', 'debit' => 'debit_sl'],
                    'WLNS' => ['credit' => 'credit_wlns', 'debit' => 'debit_wlns'],
                    'SPL' => ['credit' => 'credit_spl', 'debit' => 'debit_spl'],
                    'CTO' => ['credit' => 'credit_cto', 'debit' => 'debit_cto'],
                    'SP' => ['credit' => 'credit_sp', 'debit' => 'debit_sp'],
                ];
                $columns = $columnsByField[$field];

                app(LeaveLedgerService::class)->writeLedgerEntry([
                    'user_id' => $balance->user_id,
                    'transaction_date' => now()->toDateString(),
                    'transaction_type' => 'MANUAL_ADJUSTMENT',
                    'leave_type' => $field,
                    $columns['credit'] => $delta > 0 ? $delta : 0,
                    $columns['debit'] => $delta < 0 ? abs($delta) : 0,
                    'reference_id' => $balance->id,
                    'reference_type' => 'leave_balance',
                    'created_by' => Auth::id(),
                    'is_system' => false,
                    'remarks' => 'Manual balance correction via Manage Balance',
                ]);

                HRAuditTrail::create([
                    'actor_user_id' => Auth::id(),
                    'module' => 'leave',
                    'action' => 'manual_balance_updated',
                    'target_type' => 'leave_balance',
                    'target_id' => $balance->id,
                    'details' => [
                        'user_id' => $balance->user_id,
                        'field' => $field,
                        'old_value' => $old,
                        'new_value' => $value,
                        'timestamp' => now()->toDateTimeString(),
                    ],
                ]);
            }

            return $balance;
        });

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
     * Preview what runMonthlyCredits() would post, without writing anything to the
     * database — lets the Leave Manager review the full per-employee list before
     * committing via the Apply step, which just calls runMonthlyCredits() unchanged.
     */
    public function runMonthlyCreditsPreview(Request $request): JsonResponse
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

        $preview = app(ProcessMonthlyLeaveCredits::class)->previewBatch($year, $month);

        $userIds = collect($preview['rows'])->pluck('user_id')->unique()->values();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');
        $departments = Department::pluck('Dept_name', 'Dept_id')->toArray();

        $rows = collect($preview['rows'])->map(function ($row) use ($users, $departments) {
            $user = $users->get($row['user_id']);

            $mapped = [
                'emp_no' => $user?->EmpNo ?? '-',
                'name' => $user ? trim(($user->last_name ?? '').', '.($user->first_name ?? '')) : '-',
                'department' => $user ? ($departments[$user->Dept_id] ?? '-') : '-',
                'status' => $row['status'],
            ];

            if ($row['status'] === 'would_process') {
                $mapped['abs_wop_days'] = number_format((float) $row['abs_wop_days'], 3);
                $mapped['computed_vl'] = number_format($row['vl_earned'], 3);
                $mapped['computed_sl'] = number_format($row['sl_earned'], 3);
                $mapped['transaction_type'] = $row['transaction_type'];
            } else {
                $mapped['message'] = $row['message'];
            }

            return $mapped;
        })->values();

        return response()->json(['summary' => $preview['summary'], 'rows' => $rows]);
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
                'message' => 'This month has not been processed yet - use Run Monthly Credits instead.',
            ], 422);
        }

        $user = User::findOrFail($userId);

        return response()->json($this->recomputeAttendanceMonth($user, $attendance));
    }

    /**
     * Force-recompute every already-processed employee-month for a given year/month, even
     * when nothing in their leave data changed since (e.g. after a credit formula/logic
     * change) — the per-row Recompute button above only surfaces when isMonthlyCreditStale()
     * detects a leave-data edit, so it can't reach this case. Reuses the same delta-only
     * correction path, never the full re-credit `--force` flag on credit:process-monthly
     * uses, since that would double-credit an already-processed employee.
     */
    public function forceRecomputeMonth(Request $request): JsonResponse
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
                'message' => 'Cannot recompute a future or the current (incomplete) month.',
            ], 422);
        }

        $attendances = $this->processedAttendancesQuery($year, $month)->get();

        $recomputed = 0;
        $changed = 0;
        $failed = 0;

        foreach ($attendances as $attendance) {
            if (! $attendance->user) {
                $failed++;

                continue;
            }

            try {
                $result = $this->recomputeAttendanceMonth($attendance->user, $attendance);
                $recomputed++;
                if ($result['changed']) {
                    $changed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Force recompute monthly credit failed for user', [
                    'user_id' => $attendance->user_id,
                    'year' => $year,
                    'month' => $month,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        HRAuditTrail::create([
            'actor_user_id' => Auth::id(),
            'module' => 'leave',
            'action' => 'bulk_monthly_credit_recompute',
            'target_type' => 'App\\Models\\MonthlyAttendance',
            'target_id' => null,
            'details' => [
                'year' => $year,
                'month' => $month,
                'recomputed' => $recomputed,
                'changed' => $changed,
                'failed' => $failed,
                'triggered_by' => Auth::id(),
            ],
        ]);

        return response()->json([
            'recomputed' => $recomputed,
            'changed' => $changed,
            'failed' => $failed,
        ]);
    }

    /**
     * Preview what forceRecomputeMonth() would post, without writing anything — every
     * in-scope employee is listed (changed and unchanged both), since the whole point is
     * showing which is which. The Apply step just calls forceRecomputeMonth() unchanged.
     */
    public function forceRecomputeMonthPreview(Request $request): JsonResponse
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
                'message' => 'Cannot recompute a future or the current (incomplete) month.',
            ], 422);
        }

        $attendances = $this->processedAttendancesQuery($year, $month)->get();
        $departments = Department::pluck('Dept_name', 'Dept_id')->toArray();

        $wouldChange = 0;
        $wouldNoop = 0;
        $wouldFail = 0;
        $rows = [];

        foreach ($attendances as $attendance) {
            if (! $attendance->user) {
                $wouldFail++;

                continue;
            }

            try {
                $delta = $this->computeRecomputeDelta($attendance->user, $attendance);
            } catch (\Throwable $e) {
                $wouldFail++;
                Log::error('Force recompute preview failed for user', [
                    'user_id' => $attendance->user_id,
                    'year' => $year,
                    'month' => $month,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $delta['changed'] ? $wouldChange++ : $wouldNoop++;

            $rows[] = [
                'emp_no' => $attendance->user->EmpNo ?? '-',
                'name' => trim(($attendance->user->last_name ?? '').', '.($attendance->user->first_name ?? '')),
                'department' => $departments[$attendance->user->Dept_id] ?? '-',
                'old_vl' => number_format($delta['old_vl'], 3),
                'old_sl' => number_format($delta['old_sl'], 3),
                'new_vl' => number_format($delta['new_vl'], 3),
                'new_sl' => number_format($delta['new_sl'], 3),
                'delta_vl' => number_format($delta['delta_vl'], 3),
                'delta_sl' => number_format($delta['delta_sl'], 3),
                'changed' => $delta['changed'],
            ];
        }

        return response()->json([
            'summary' => ['would_change' => $wouldChange, 'would_noop' => $wouldNoop, 'would_fail' => $wouldFail],
            'rows' => $rows,
        ]);
    }

    /**
     * Recompute one employee's already-processed month and post only the delta as a
     * CREDIT_CORRECTION ledger entry — shared by the single-employee endpoint above and
     * the bulk force-recompute endpoint below, so both apply identical, double-credit-safe
     * logic regardless of what triggered the recompute. Wrapped in its own transaction so
     * the ledger write, real balance adjustment, attendance save, and audit log are atomic
     * regardless of whether the caller already wraps this in a transaction of its own.
     *
     * @return array{changed: bool, delta_vl: float, delta_sl: float, new_vl: float, new_sl: float}
     */
    private function recomputeAttendanceMonth(User $user, MonthlyAttendance $attendance): array
    {
        return DB::transaction(function () use ($user, $attendance) {
            // Re-fetch with a row lock so two concurrent recompute requests for the same
            // employee-month can't both read the same stale computed_vl/computed_sl and
            // each post their own CREDIT_CORRECTION for the same delta.
            $attendance = MonthlyAttendance::where('id', $attendance->id)->lockForUpdate()->firstOrFail();

            $originalProcessedAt = $attendance->processed_at;
            $delta = $this->computeRecomputeDelta($user, $attendance);

            if ($delta['changed']) {
                app(LeaveLedgerService::class)->writeLedgerEntry([
                    'user_id' => $user->id,
                    'transaction_date' => Carbon::create($attendance->year, $attendance->month, 1)->endOfMonth()->toDateString(),
                    'transaction_type' => 'CREDIT_CORRECTION',
                    'leave_type' => 'VL+SL',
                    'days_present' => $delta['days_present'],
                    'abs_wop_days' => $delta['abs_wop_days'] > 0 ? $delta['abs_wop_days'] : null,
                    'credit_vl' => $delta['delta_vl'] > 0 ? $delta['delta_vl'] : 0,
                    'credit_sl' => $delta['delta_sl'] > 0 ? $delta['delta_sl'] : 0,
                    'debit_vl' => $delta['delta_vl'] < 0 ? abs($delta['delta_vl']) : 0,
                    'debit_sl' => $delta['delta_sl'] < 0 ? abs($delta['delta_sl']) : 0,
                    'is_system' => true,
                    'created_by' => Auth::id(),
                    'remarks' => 'Correction: recomputed on '.now()->format('Y-m-d H:i')." (originally processed {$originalProcessedAt->format('Y-m-d H:i')}).",
                ]);

                // Apply the same delta to the real balance the employee files leave
                // against -- writeLedgerEntry() above only records the transaction.
                // The delta can be negative (a correction that reduces credit must
                // reduce the balance too), same sign convention as credit_vl/debit_vl above.
                $balance = LeaveBalance::where('user_id', $user->id)->lockForUpdate()->first();
                if ($balance) {
                    $balance->VL = round((float) $balance->VL + $delta['delta_vl'], 3);
                    $balance->SL = round((float) $balance->SL + $delta['delta_sl'], 3);
                    $balance->save();
                }
            }

            $attendance->computed_vl = $delta['new_vl'];
            $attendance->computed_sl = $delta['new_sl'];
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
                    'user_id' => $user->id,
                    'year' => $attendance->year,
                    'month' => $attendance->month,
                    'old_vl' => $delta['old_vl'],
                    'old_sl' => $delta['old_sl'],
                    'new_vl' => $delta['new_vl'],
                    'new_sl' => $delta['new_sl'],
                    'delta_vl' => $delta['delta_vl'],
                    'delta_sl' => $delta['delta_sl'],
                    'triggered_by' => Auth::id(),
                ],
            ]);

            return [
                'changed' => $delta['changed'],
                'delta_vl' => $delta['delta_vl'],
                'delta_sl' => $delta['delta_sl'],
                'new_vl' => $delta['new_vl'],
                'new_sl' => $delta['new_sl'],
            ];
        });
    }

    /**
     * Pure computation of an already-processed employee-month's would-be recompute delta --
     * no DB writes. Shared by recomputeAttendanceMonth() (which persists the result) and
     * forceRecomputeMonthPreview() (which just reads it), so preview and apply are
     * guaranteed to agree, provided nothing in the underlying data changes in between.
     *
     * @return array{old_vl: float, old_sl: float, new_vl: float, new_sl: float, delta_vl: float, delta_sl: float, changed: bool, days_present: float, abs_wop_days: float}
     */
    private function computeRecomputeDelta(User $user, MonthlyAttendance $attendance): array
    {
        $oldVl = (float) $attendance->computed_vl;
        $oldSl = (float) $attendance->computed_sl;

        $aggregate = app(LwopAggregationService::class)->computeForMonth($user, $attendance->year, $attendance->month);
        $attendance->days_present = $aggregate['days_present'];
        $attendance->abs_wop_days = $aggregate['abs_wop_days'];

        $result = app(LeaveCreditComputationService::class)->computeMonthlyCredit($user, $attendance);
        $newVl = $result['vl_earned'];
        $newSl = $result['sl_earned'];

        $deltaVl = round($newVl - $oldVl, 3);
        $deltaSl = round($newSl - $oldSl, 3);

        $epsilon = 0.0005;

        return [
            'old_vl' => $oldVl,
            'old_sl' => $oldSl,
            'new_vl' => $newVl,
            'new_sl' => $newSl,
            'delta_vl' => $deltaVl,
            'delta_sl' => $deltaSl,
            'changed' => abs($deltaVl) > $epsilon || abs($deltaSl) > $epsilon,
            'days_present' => $attendance->days_present,
            'abs_wop_days' => $attendance->abs_wop_days,
        ];
    }

    private function processedAttendancesQuery(int $year, int $month)
    {
        return MonthlyAttendance::with('user')
            ->where('year', $year)
            ->where('month', $month)
            ->whereNotNull('processed_at');
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
        if ($request->filled('month')) {
            $filters['month'] = (int) $request->input('month');
        }
        if ($request->filled('transaction_type')) {
            $filters['transaction_type'] = $request->input('transaction_type');
        }

        $entries = app(LeaveLedgerService::class)->getLedgerHistory($userId, $filters);

        // Each leave_ledger row is one immutable audit record for a whole transaction and
        // can carry amounts across several leave types at once (e.g. a 3-date leave with
        // a different type per date). The table displays that as one row per active type
        // instead of one wide row with mostly-zero columns -- a display-only split, the
        // underlying row/write path is untouched. Only VL and SL have a running-balance
        // chain (vl_balance_after/sl_balance_after) -- the other types are plain
        // record-keeping with no chain concept, so their split rows show '-' for balance.
        $typeColumns = [
            'VL' => ['credit_vl', 'debit_vl'],
            'SL' => ['credit_sl', 'debit_sl'],
            'WLNS' => ['credit_wlns', 'debit_wlns'],
            'SPL' => ['credit_spl', 'debit_spl'],
            'CTO' => ['credit_cto', 'debit_cto'],
            'SP' => ['credit_sp', 'debit_sp'],
        ];

        // Preload the leave_dates linked to any leave_request-referencing entry, so a
        // per-type split row can show the SPECIFIC date(s) that type actually covers
        // instead of the whole transaction's aggregate period -- e.g. a 3-date request
        // with a different type per date would otherwise show the same 3-day range on
        // all three split rows, even though each type really only applies to one of them.
        $leaveRequestIds = $entries->where('reference_type', 'leave_request')->pluck('reference_id')->filter()->unique();
        $datesByLeaveRequest = LeaveDate::whereIn('leave_request_id', $leaveRequestIds)
            ->get(['leave_request_id', 'leave_date', 'leave_type', 'is_cancelled'])
            ->groupBy('leave_request_id');
        $dateAggregateService = app(LeaveDateAggregateService::class);

        $data = $entries->flatMap(function ($e) use ($typeColumns, $datesByLeaveRequest, $dateAggregateService) {
            $aggregateDate = $e->transaction_date?->toDateString();
            if ($e->period_end_date && ! $e->period_end_date->equalTo($e->transaction_date)) {
                $aggregateDate .= ' – '.$e->period_end_date->toDateString();
            }

            // Group this transaction's own linked leave_dates by resolved balance column,
            // restricted to the ledger row's own [transaction_date, period_end_date] range
            // and to the cancelled/active side matching this transaction type, so dates
            // from an unrelated later edit of the same leave request aren't pulled in.
            $datesByType = [];
            if ($e->reference_type === 'leave_request' && $e->reference_id) {
                $rangeStart = $e->transaction_date?->toDateString();
                $rangeEnd = $e->period_end_date?->toDateString() ?? $rangeStart;

                foreach ($datesByLeaveRequest->get($e->reference_id, collect()) as $ld) {
                    if ($rangeStart && ($ld->leave_date < $rangeStart || $ld->leave_date > $rangeEnd)) {
                        continue;
                    }
                    $wantsCancelled = $e->transaction_type === 'LEAVE_CANCELLED';
                    if ((bool) $ld->is_cancelled !== $wantsCancelled) {
                        continue;
                    }
                    $column = $dateAggregateService->resolveBalanceColumn((string) $ld->leave_type);
                    if ($column) {
                        $datesByType[$column][] = $ld->leave_date;
                    }
                }
            }

            $base = [
                'type' => $e->transaction_type,
                'days_present' => $e->days_present !== null ? number_format($e->days_present, 3) : '-',
                'abs_wop_days' => $e->abs_wop_days !== null ? number_format($e->abs_wop_days, 3) : '-',
                'remarks' => $e->remarks,
                'posted_by' => $e->is_system ? 'System' : ($e->createdBy ? trim(($e->createdBy->last_name ?? '').', '.($e->createdBy->first_name ?? '')) : '-'),
            ];

            $rows = [];
            foreach ($typeColumns as $type => [$creditCol, $debitCol]) {
                $credit = (float) ($e->{$creditCol} ?? 0);
                $debit = (float) ($e->{$debitCol} ?? 0);
                if ($credit == 0.0 && $debit == 0.0) {
                    continue;
                }

                // Fall back to the transaction's own aggregate range when this type's
                // specific date(s) can't be resolved (older data with no leave_dates rows,
                // an unmapped type like CTO, or a range-filed leave with no per-date rows).
                $typeDate = $aggregateDate;
                if (! empty($datesByType[$type])) {
                    $sortedDates = collect($datesByType[$type])->sort()->values();
                    $typeDate = $sortedDates->first();
                    if ($sortedDates->last() !== $sortedDates->first()) {
                        $typeDate .= ' – '.$sortedDates->last();
                    }
                }

                $rows[] = $base + [
                    'date' => $typeDate,
                    'leave_type' => $type,
                    'credit' => number_format($credit, 3),
                    'debit' => number_format($debit, 3),
                    'balance_after' => $type === 'VL' ? number_format($e->vl_balance_after, 3)
                        : ($type === 'SL' ? number_format($e->sl_balance_after, 3) : '-'),
                ];
            }

            // No type had any nonzero amount (e.g. a non-deductible leave type's
            // record-only entry) -- still show one row so the transaction isn't hidden.
            if (empty($rows)) {
                $rows[] = $base + [
                    'date' => $aggregateDate,
                    'leave_type' => $e->leave_type,
                    'credit' => number_format(0, 3),
                    'debit' => number_format(0, 3),
                    'balance_after' => '-',
                ];
            }

            return $rows;
        })->values();

        // The split-by-type rows above don't reliably surface the true current VL/SL
        // balance in $data[0] (the most recent row could be a WLNS/SPL/CTO/SP-only split
        // with no VL/SL balance_after at all), so resolve it directly for the summary cards.
        [$currentVl, $currentSl] = app(LeaveLedgerService::class)->getCurrentBalance($userId);

        return response()->json([
            'data' => $data,
            'current_vl' => number_format($currentVl, 3),
            'current_sl' => number_format($currentSl, 3),
        ]);
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
                'status' => $lwopService->awolSeverityLabel($streak['streak']),
            ];
        })->filter()->sortByDesc('streak_sort')->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Toggle an employee's RA 8972 Solo Parent designation. "Active" gates eligibility
     * to file Solo Parent Leave (see LeaveRequestController::checkSoloParentDesignation())
     * - independent of and checked in addition to the employee's SP leave balance.
     */
    public function toggleSoloParent(Request $request, User $user): JsonResponse
    {
        $active = ! $user->is_solo_parent;
        $user->update(['is_solo_parent' => $active]);

        try {
            HRAuditTrail::create([
                'actor_user_id' => Auth::id(),
                'module' => 'leave',
                'action' => 'solo_parent_status_toggled',
                'target_type' => 'user',
                'target_id' => $user->id,
                'details' => [
                    'employee_name' => trim(($user->last_name ?? '').', '.($user->first_name ?? '')),
                    'is_solo_parent' => $active,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to write HRAuditTrail on solo parent toggle', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['is_solo_parent' => $active]);
    }
}
