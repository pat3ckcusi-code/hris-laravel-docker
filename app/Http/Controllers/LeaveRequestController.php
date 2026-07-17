<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\Department;
use App\Models\HRAuditTrail;
use App\Models\LeaveDate;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\HrisTransactionNotification;
use App\Services\DepartmentService;
use App\Services\LeaveRequestService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class LeaveRequestController extends Controller
{
    private LeaveRequestService $leaveRequestService;

    private DepartmentService $departmentService;

    public function __construct(LeaveRequestService $leaveRequestService, DepartmentService $departmentService)
    {
        $this->leaveRequestService = $leaveRequestService;
        $this->departmentService = $departmentService;
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $query = LeaveRequest::where('user_id', $user->id);

        $month = $request->query('month', '');
        $year = (int) $request->query('year', now()->year);
        if ($year < 2000 || $year > 2100) {
            $year = now()->year;
        }
        if (is_numeric($month) && (int) $month >= 1 && (int) $month <= 12) {
            $query->whereMonth('start_date', (int) $month)->whereYear('start_date', $year);
        }

        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('leave_type', 'like', '%'.$search.'%')
                    ->orWhere('reason', 'like', '%'.$search.'%');
            });
        }

        $allowedSorts = ['leave_type', 'start_date', 'end_date', 'total_days', 'status', 'created_at'];
        $sort = $request->query('sort');
        $dir = strtolower($request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, $allowedSorts, true)) {
            $query->orderBy($sort, $dir);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $leaveRequests = $query->with(['lastPrintedBy', 'pendingCancellationDates', 'originalDatesReplaced', 'leaveDates' => function ($q) {
            $q->where('is_cancelled', false)->whereNull('cancellation_status')->whereNull('rescheduled_to_leave_request_id')->orderBy('leave_date');
        }])->paginate(10)->withQueryString();

        return view('employee.leave-management', compact('leaveRequests', 'user'));
    }

    public function show($id)
    {
        $leave = LeaveRequest::findOrFail($id);
        $user = Auth::user();

        // Only allow owner to view this page from the employee area
        if ($leave->user_id !== $user->id) {
            abort(403);
        }

        return view('employee.leave-print', ['leaves' => collect([$leave])]);
    }

    public function printSingle(LeaveRequest $leave)
    {
        $user = Auth::user();

        // ensure printing has been allowed and user may print
        Log::info('Print attempt for leave request', [
            'leave_id' => $leave->id,
            'user_id' => $user->id ?? null,
            'user_role' => $user->access_level ?? null,
            'leave_status' => $leave->status ?? null,
            'printing_allowed' => (bool) ($leave->printing_allowed ?? false),
            'timestamp' => now()->toDateTimeString(),
        ]);

        if (! $this->leaveRequestService->canPrint($leave, $user)) {
            abort(403);
        }

        if (! $leave->last_printed_at) {
            $leave->last_printed_at = now();
            $leave->last_printed_by = $user->id;
            $leave->save();
        }

        return $this->leaveRequestService->generateExcelResponse($leave);
    }

    /**
     * API: return minimal leave status for client polling.
     */
    public function apiStatus(LeaveRequest $leave)
    {
        $user = Auth::user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // allow owner to query status; other roles may be restricted
        if ($leave->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $leave->loadMissing('lastPrintedBy');

        return response()->json([
            'id' => $leave->id,
            'status' => $leave->status,
            'printing_allowed' => (bool) ($leave->printing_allowed ?? false),
            'last_printed_at' => $leave->last_printed_at ? $leave->last_printed_at->format('M d, Y') : null,
            'last_printed_by_name' => optional($leave->lastPrintedBy)->name,
        ]);
    }

    public function store(Request $request)
    {

        // Extended leave types use a date-range path instead of individual-date allocation
        if ($request->boolean('extended_leave_mode')) {
            $request->validate([
                'leave_types' => 'required|array|min:1|max:1',
                'leave_types.*' => 'string|max:100',
                'range_start' => 'required|date',
                'range_end' => 'required|date|after_or_equal:range_start',
                'reason' => 'nullable|string|max:2000',
            ]);

            $rangeTypes = [
                'Maternity Leave', 'VAWC Leave', 'Special Leave (Gynecological)',
                'Rehabilitation Privilege', 'Study / Examination Leave',
            ];
            $type = $request->input('leave_types')[0];
            if (! in_array($type, $rangeTypes, true)) {
                return redirect()->back()
                    ->withErrors(['leave_types' => 'This leave type does not support range-based filing.'])
                    ->withInput();
            }

            $start = Carbon::parse($request->range_start)->startOfDay();
            $end = Carbon::parse($request->range_end)->startOfDay();
            if ($end->diffInDays($start) > 184) {
                return redirect()->back()
                    ->withErrors(['range_end' => 'Leave duration cannot exceed 6 months.'])
                    ->withInput();
            }

            // Build weekday list
            $dates = [];
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                if (! $cursor->isWeekend()) {
                    $dates[] = $cursor->toDateString();
                }
                $cursor->addDay();
            }
            $totalDays = count($dates);

            if ($totalDays === 0) {
                return redirect()->back()
                    ->withErrors(['range_start' => 'The selected date range contains no working days.'])
                    ->withInput();
            }

            $employee = Auth::user();

            // Conflict check against existing leave dates
            $conflicts = LeaveDate::whereIn('leave_date', $dates)
                ->where('is_cancelled', false)
                ->whereHas('leaveRequest', function ($q) use ($employee) {
                    $q->where('user_id', $employee->id)
                        ->whereIn('status', ['pending', 'approved']);
                })->pluck('leave_date')->map(fn ($d) => (string) $d)->toArray();

            if (! empty($conflicts)) {
                return redirect()->back()
                    ->withErrors(['range_start' => 'You already have leave requests covering these dates: '.implode(', ', $conflicts)])
                    ->withInput();
            }

            $leave = LeaveRequest::create([
                'user_id' => $employee->id,
                'leave_type' => $type,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'total_days' => $totalDays,
                'paid_days' => $totalDays,
                'lwop_days' => 0,
                'reason' => $request->reason ? strtoupper(trim($request->reason)) : null,
                'balance_vacation_leave' => optional($employee->leaveBalance)->VL ?? 0,
                'balance_sick_leave' => optional($employee->leaveBalance)->SL ?? 0,
                'balance_wellness_leave' => optional($employee->leaveBalance)->WLNS ?? 0,
                'balance_solo_parent_leave' => optional($employee->leaveBalance)->SP ?? 0,
                'balance_special_leave_privilege' => optional($employee->leaveBalance)->SPL ?? 0,
                'printing_deduction_details' => json_encode([]),
                'printing_deduction_applied' => false,
                'status' => 'pending',
                'date_filed' => now()->toDateString(),
            ]);

            LeaveDate::insert(array_map(fn ($d) => [
                'leave_request_id' => $leave->id,
                'leave_date' => $d,
                'leave_type' => $type,
                'days' => 1.0,
                'is_cancelled' => false,
                'is_lwop' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], $dates));

            // Notify approver
            try {
                $department = $employee->Dept_id ? Department::find($employee->Dept_id) : null;
                $departmentName = $department?->Dept_name ?? null;

                $filerRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($employee->access_level ?? ''))));
                $approver = null;
                if (in_array($filerRole, ['department head', 'hr manager'])) {
                    $approver = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'mayor'")->first();
                } elseif ($department) {
                    $approver = $this->departmentService->getDepartmentHeadUser($department);
                }

                $empName = trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: ($employee->name ?? 'Employee');

                if ($approver) {
                    $approver->notify(new HrisTransactionNotification(
                        requestType: 'Leave Request',
                        status: 'Filed',
                        details: [
                            'Employee' => $empName,
                            'Department' => $departmentName ?? 'N/A',
                            'Leave Type' => $type,
                            'Start Date' => $start->format('l, F j, Y'),
                            'End Date' => $end->format('l, F j, Y'),
                            'Date Filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                            'Reason' => $leave->reason ?? 'N/A',
                        ],
                        actor: $empName,
                    ));
                }
            } catch (\Exception $ex) {
                // swallow notification errors
            }

            return redirect()->route('employee.leave.management')
                ->with('success', 'Leave application submitted successfully.');
        }

        if ($request->has('leave_dates')) {
            $request->validate([
                'leave_types' => 'required|array|min:1|max:8',
                'leave_types.*' => 'string|max:100',
                'leave_dates' => 'required|string|max:2000',
                'reason' => 'nullable|string|max:2000',
                'details_location' => 'nullable|string|max:50',
                'details_location_specify' => 'nullable|string|max:255',
                'details_sick_illness' => 'nullable|string|max:255',
                'details_sick_treatment' => 'nullable|string|max:50',
                'details_others_type' => 'nullable|string|max:100',
                'allocation' => 'nullable|array|max:90',
                'allocation.*.type' => 'nullable|string|max:100',
                'allocation.*.days' => 'nullable|numeric|min:0|max:1',
            ]);

            $exclusiveTypes = [
                'Maternity Leave', 'Paternity Leave', 'Adoption Leave',
                'VAWC Leave', 'Special Leave (Gynecological)',
                'Rehabilitation Privilege', 'Study / Examination Leave',
                'Mandatory/Forced Leave',
            ];
            $selectedForValidation = $request->input('leave_types', []);
            $exclusiveSelected = array_values(array_intersect($selectedForValidation, $exclusiveTypes));
            if (count($exclusiveSelected) > 0 && count($selectedForValidation) > 1) {
                return redirect()->back()
                    ->withErrors(['leave_types' => implode(', ', $exclusiveSelected).' must be filed as a separate application.'])
                    ->withInput();
            }
            if (count($selectedForValidation) > 3) {
                return redirect()->back()
                    ->withErrors(['leave_types' => 'Maximum 3 leave types per application.'])
                    ->withInput();
            }

            $dates = array_values(array_filter(explode(',', $request->leave_dates)));
            // Prevent duplicate dates from being submitted (human error or tampering)
            if (count($dates) !== count(array_unique($dates))) {
                return redirect()->back()->withErrors(['leave_dates' => 'Duplicate dates detected in your selection. Please remove duplicates and try again.'])->withInput();
            }
            if (empty($dates)) {
                return redirect()->back()->withErrors(['leave_dates' => 'Please select at least one date.'])->withInput();
            }

            $conflicts = [];
            $existing = LeaveDate::whereIn('leave_date', $dates)
                ->where('is_cancelled', false)
                ->whereHas('leaveRequest', function ($q) {
                    $q->where('user_id', Auth::id())
                        ->whereIn('status', ['pending', 'approved']);
                })->get();
            $existingDates = $existing->pluck('leave_date')->map(function ($d) {
                return (string) $d;
            })->toArray();
            foreach ($dates as $d) {
                if (in_array($d, $existingDates, true)) {
                    $conflicts[] = $d;
                }
            }
            if (! empty($conflicts)) {
                return redirect()->back()->withErrors(['leave_dates' => 'You already have leave requests covering these dates: '.implode(', ', $conflicts)])->withInput();
            }

            sort($dates);
            $startDate = $dates[0];
            $endDate = end($dates);

            $today = (new \DateTime)->setTime(0, 0, 0);
            $start = (new \DateTime($startDate))->setTime(0, 0, 0);
            $end = (new \DateTime($endDate))->setTime(0, 0, 0);

            // Enforce Vacation Leave lead time per-date based on allocation type
            $selectedTypes = $request->input('leave_types', []);
            $allocations = $request->input('allocation', []);
            $minStart = (clone $today)->add(new \DateInterval('P5D'));
            $vacationViolations = [];
            foreach ($dates as $d) {
                $typeForDate = $allocations[$d]['type'] ?? ($selectedTypes[0] ?? null);
                if ($typeForDate === 'Vacation Leave') {
                    $dt = (new \DateTime($d))->setTime(0, 0, 0);
                    if ($dt < $minStart) {
                        $vacationViolations[] = $d;
                    }
                }
            }
            if (! empty($vacationViolations)) {
                return redirect()->back()->withErrors(['leave_dates' => 'Vacation Leave must be filed at least 5 calendar days before these dates: '.implode(', ', $vacationViolations)])->withInput();
            }

            // Enforce Mandatory/Forced Leave: only fileable in November or December
            if (in_array('Mandatory/Forced Leave', $selectedTypes)) {
                $filingMonth = (int) $today->format('n');
                if (! in_array($filingMonth, [11, 12])) {
                    return redirect()->back()->withErrors(['leave_types' => 'Mandatory/Forced Leave can only be filed in November or December.'])->withInput();
                }
            }

            // Enforce Mandatory/Forced Leave: 5-day advance rule and dates must be in November or December
            $mandatoryLeadViolations = [];
            $mandatoryMonthViolations = [];
            foreach ($dates as $d) {
                $typeForDate = $allocations[$d]['type'] ?? ($selectedTypes[0] ?? null);
                if ($typeForDate === 'Mandatory/Forced Leave') {
                    $dt = (new \DateTime($d))->setTime(0, 0, 0);
                    if ($dt < $minStart) {
                        $mandatoryLeadViolations[] = $d;
                    }
                    if (! in_array((int) (new \DateTime($d))->format('n'), [11, 12])) {
                        $mandatoryMonthViolations[] = $d;
                    }
                }
            }
            if (! empty($mandatoryLeadViolations)) {
                return redirect()->back()->withErrors(['leave_dates' => 'Mandatory/Forced Leave must be filed at least 5 calendar days before these dates: '.implode(', ', $mandatoryLeadViolations)])->withInput();
            }
            if (! empty($mandatoryMonthViolations)) {
                return redirect()->back()->withErrors(['leave_dates' => 'Mandatory/Forced Leave dates must fall in November or December: '.implode(', ', $mandatoryMonthViolations)])->withInput();
            }

            // Block filing if a balance-restricted leave type has zero balance
            $user = Auth::user();
            $lb = $user->leaveBalance;
            if ($balanceError = $this->checkZeroBalanceTypes($selectedTypes, $lb)) {
                return redirect()->back()->withErrors(['leave_types' => $balanceError])->withInput();
            }

            // Block filing if requested days exceed available credits for restricted types
            if ($creditError = $this->checkInsufficientCredits($dates, $allocations, $selectedTypes, $lb)) {
                return redirect()->back()->withErrors(['leave_types' => $creditError])->withInput();
            }

            // Map selected types into a single string to store (preserve existing DB shape)
            $leaveTypeValue = implode(', ', $selectedTypes);

            // Determine which leave types require extra details or reason
            $needsVacationSpecial = false;
            $needsStudy = false;
            $needsSick = false;
            $needsOthers = false;
            $requiredForReason = [];
            foreach ($selectedTypes as $t) {
                $tn = strtolower(trim($t));
                if (strpos($tn, 'vacation') !== false || strpos($tn, 'special') !== false || strpos($tn, 'spl') !== false || strpos($tn, 'privilege') !== false) {
                    $needsVacationSpecial = true;
                    $requiredForReason[] = $t;
                }
                if (strpos($tn, 'study') !== false) {
                    $needsStudy = true;
                    $requiredForReason[] = $t;
                }
                if (strpos($tn, 'sick') !== false) {
                    $needsSick = true;
                }
                if ($tn === 'others') {
                    $needsOthers = true;
                    $requiredForReason[] = $t;
                }
            }

            // Server-side: require Reason/Purpose when applicable
            if (! empty($requiredForReason) && empty(trim((string) $request->reason))) {
                return redirect()->back()->withErrors(['reason' => 'Reason / Purpose is required for: '.implode(', ', array_unique($requiredForReason))])->withInput();
            }

            // Server-side: require 6.B details for Vacation/Special (within/abroad) when needed
            if ($needsVacationSpecial) {
                $loc = $request->input('details_location');
                $locSpecify = $request->input('details_location_specify');
                if (empty($loc) && empty(trim((string) $locSpecify))) {
                    return redirect()->back()->withErrors(['details_location' => '6.B Details of Leave (Within/Abroad) required for Vacation/Special Leave.'])->withInput();
                }
            }

            // Server-side: require study details (checkboxes or specify) and reason already enforced above
            if ($needsStudy) {
                $study = $request->input('details_study_purpose');
                $studyOther = $request->input('details_study_other');
                if (empty($study) && empty(trim((string) $studyOther))) {
                    return redirect()->back()->withErrors(['details_study_purpose' => 'Please specify study purpose (e.g. completion of master\'s, BAR review) for Study Leave.'])->withInput();
                }
            }

            // Server-side: require sick details (in hospital / outpatient or specify illness)
            if ($needsSick) {
                $sick = $request->input('details_sick_treatment');
                $sickIllness = $request->input('details_sick_illness');
                if (empty($sick) && empty(trim((string) $sickIllness))) {
                    return redirect()->back()->withErrors(['details_sick_treatment' => '6.B Details of Leave (In Hospital / Out Patient) required for Sick Leave.'])->withInput();
                }
            }

            // Server-side: require a selected type for "Others" leave
            if ($needsOthers) {
                $othersType = $request->input('details_others_type');
                if (empty(trim((string) $othersType))) {
                    return redirect()->back()->withErrors(['details_others_type' => 'Please select a type for "Others" leave.'])->withInput();
                }
            }

            // Process allocations to compute totals and perform deductions
            $allocations = $request->input('allocation', []);
            $totalDays = 0.0;
            $paidDays = 0.0;
            $lwopDays = 0.0;

            // snapshot balances at time of filing (before deduction)
            $snap_vl = $lb->VL ?? 0.0;
            $snap_sl = $lb->SL ?? 0.0;
            $snap_wlns = $lb->WLNS ?? 0.0;
            $snap_sp = $lb->SP ?? 0.0;
            $snap_spl = $lb->SPL ?? 0.0;

            // working balances for deduction
            $vl = $snap_vl;
            $sl = $snap_sl;
            $wlns = $snap_wlns;
            $sp = $snap_sp;
            $spl = $snap_spl;

            // iterate allocations (per date) to compute deductions
            $dedVL = 0.0;
            $dedSL = 0.0;
            $dedWLNS = 0.0;
            $dedSP = 0.0;
            $dedSPL = 0.0;
            $perDateMeta = [];
            foreach ($dates as $d) {
                $totalDaysForDate = 0.0;
                if (isset($allocations[$d]['days'])) {
                    $totalDaysForDate = floatval($allocations[$d]['days']);
                }
                $totalDays += $totalDaysForDate;

                $typeForDate = $allocations[$d]['type'] ?? ($selectedTypes[0] ?? null);
                if (! $typeForDate) {
                    // treat as unpaid if no type indicated
                    $lwopDays += $totalDaysForDate;
                    $perDateMeta[$d] = ['type' => null, 'days' => $totalDaysForDate, 'is_lwop' => true];

                    continue;
                }

                $paidDaysBeforeDate = $paidDays;

                switch ($typeForDate) {
                    case 'Vacation Leave':
                        $ded = min($vl, $totalDaysForDate);
                        $vl -= $ded;
                        $dedVL += $ded;
                        $paidDays += $ded;
                        $rem = $totalDaysForDate - $ded;
                        if ($rem > 0) {
                            // no fallback defined for VL; mark remaining as LWOP
                            $lwopDays += $rem;
                        }
                        break;
                    case 'Sick Leave':
                        // Deduct from SL first, then from VL, else LWOP
                        $dedSl = min($sl, $totalDaysForDate);
                        $sl -= $dedSl;
                        $dedSL += $dedSl;
                        $paidDays += $dedSl;
                        $rem = $totalDaysForDate - $dedSl;
                        if ($rem > 0) {
                            $dedVl = min($vl, $rem);
                            $vl -= $dedVl;
                            $dedVL += $dedVl;
                            $paidDays += $dedVl;
                            $rem -= $dedVl;
                        }
                        if ($rem > 0) {
                            $lwopDays += $rem;
                        }
                        break;
                    case 'Wellness Leave':
                        $ded = min($wlns, $totalDaysForDate);
                        $wlns -= $ded;
                        $dedWLNS += $ded;
                        $paidDays += $ded;
                        $rem = $totalDaysForDate - $ded;
                        if ($rem > 0) {
                            $lwopDays += $rem;
                        }
                        break;
                    case 'Solo Parent Leave':
                        $ded = min($sp, $totalDaysForDate);
                        $sp -= $ded;
                        $dedSP += $ded;
                        $paidDays += $ded;
                        $rem = $totalDaysForDate - $ded;
                        if ($rem > 0) {
                            $lwopDays += $rem;
                        }
                        break;
                    case 'Special Privilege Leave':
                        $ded = min($spl, $totalDaysForDate);
                        $spl -= $ded;
                        $dedSPL += $ded;
                        $paidDays += $ded;
                        $rem = $totalDaysForDate - $ded;
                        if ($rem > 0) {
                            $lwopDays += $rem;
                        }
                        break;
                    case 'Others':
                        // Mourning Leave (currently the only "Others" dropdown value) draws from Vacation Leave credits
                        $ded = min($vl, $totalDaysForDate);
                        $vl -= $ded;
                        $dedVL += $ded;
                        $paidDays += $ded;
                        $rem = $totalDaysForDate - $ded;
                        if ($rem > 0) {
                            $lwopDays += $rem;
                        }
                        break;
                    default:
                        // other leave types do not deduct from balances
                        $paidDays += $totalDaysForDate;
                        break;
                }

                $paidForDate = $paidDays - $paidDaysBeforeDate;
                $perDateMeta[$d] = [
                    'type' => $typeForDate,
                    'days' => $totalDaysForDate,
                    'is_lwop' => $paidForDate <= 0 && $totalDaysForDate > 0,
                ];
            }

            // ensure no negatives
            $vl = max(0, $vl);
            $sl = max(0, $sl);
            $wlns = max(0, $wlns);
            $sp = max(0, $sp);
            $spl = max(0, $spl);

            // prepare printing deduction preview (include all deductible types)
            $printingDeductionPreview = [];
            if ($dedVL > 0) {
                $printingDeductionPreview['VL'] = $dedVL;
            }
            if ($dedSL > 0) {
                $printingDeductionPreview['SL'] = $dedSL;
            }
            if (! empty($dedWLNS) && $dedWLNS > 0) {
                $printingDeductionPreview['WLNS'] = $dedWLNS;
            }
            if (! empty($dedSP) && $dedSP > 0) {
                $printingDeductionPreview['SP'] = $dedSP;
            }
            if (! empty($dedSPL) && $dedSPL > 0) {
                $printingDeductionPreview['SPL'] = $dedSPL;
            }

            // update user's leave balance atomically and create leave request
            $leave = DB::transaction(function () use ($snap_vl, $snap_sl, $snap_wlns, $snap_sp, $snap_spl, $leaveTypeValue, $startDate, $endDate, $request, $totalDays, $paidDays, $lwopDays) {
                // create leave request with snapshot balances and computed fields
                return LeaveRequest::create([
                    'user_id' => Auth::id(),
                    'leave_type' => $leaveTypeValue,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'date_filed' => now()->toDateString(),
                    'reason' => $request->reason,
                    'details_location' => $request->input('details_location'),
                    'details_location_specify' => $request->input('details_location_specify'),
                    'details_sick_illness' => $request->input('details_sick_illness'),
                    'details_sick_treatment' => $request->input('details_sick_treatment'),
                    'details_others_type' => $request->input('details_others_type'),
                    'status' => 'pending',
                    'total_days' => $totalDays,
                    'paid_days' => $paidDays,
                    'lwop_days' => $lwopDays,
                    'balance_vacation_leave' => $snap_vl,
                    'balance_sick_leave' => $snap_sl,
                    'balance_wellness_leave' => $snap_wlns,
                    'balance_solo_parent_leave' => $snap_sp,
                    'balance_special_leave_privilege' => $snap_spl,
                    'printing_deduction_details' => null,
                ]);
            });

            // save printing deduction preview if available (non-destructive)
            if (! empty($printingDeductionPreview) && \Schema::hasColumn('leave_requests', 'printing_deduction_details')) {
                try {
                    $leave->printing_deduction_details = json_encode($printingDeductionPreview);
                    $leave->save();
                } catch (\Exception $ex) {
                    Log::error('Failed to save printing_deduction_details at filing', ['leave_id' => $leave->id, 'error' => $ex->getMessage()]);
                }
            }
            // create per-day records in leave_dates
            foreach ($dates as $d) {
                $meta = $perDateMeta[$d] ?? null;
                LeaveDate::create([
                    'leave_request_id' => $leave->id,
                    'leave_date' => $d,
                    'leave_type' => $meta['type'] ?? $leaveTypeValue,
                    'days' => $meta['days'] ?? 1.0,
                    'is_lwop' => $meta['is_lwop'] ?? false,
                ]);
            }

            // Send notification to approver (cc employee)
            // Role-based routing: Department Head / HR Manager → Mayor; Employee → Department Head
            try {
                $employee = User::find($leave->user_id);
                $departmentName = null;
                $approver = null;
                if ($employee && ! empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    if ($department) {
                        $departmentName = $department->Dept_name ?? null;
                    }
                }

                $filerRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($employee->access_level ?? ''))));
                if (in_array($filerRole, ['department head', 'hr manager'])) {
                    $approver = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'mayor'")->first();
                } elseif (isset($department) && $department) {
                    $approver = $this->departmentService->getDepartmentHeadUser($department);
                }

                if ($employee) {
                    $employee->department_name = $departmentName;
                    if ($approver) {
                        $parts = [];
                        if (! empty($approver->first_name)) {
                            $parts[] = $approver->first_name;
                        }
                        if (! empty($approver->middle_name)) {
                            $parts[] = $approver->middle_name;
                        }
                        if (! empty($approver->last_name)) {
                            $parts[] = $approver->last_name;
                        }
                        if (empty($parts) && ! empty($approver->name)) {
                            $parts[] = $approver->name;
                        }
                        $employee->dept_head_name = implode(' ', $parts);
                    }
                }

                $formatted = [
                    'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                    'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                    'end' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                ];

                if ($approver) {
                    $empName = trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: ($employee->name ?? 'Employee');
                    $approver->notify(new HrisTransactionNotification(
                        requestType: 'Leave Request',
                        status: 'Filed',
                        details: [
                            'Employee' => $empName,
                            'Department' => $employee->department_name ?? 'N/A',
                            'Leave Type' => $leave->leave_type ?? 'N/A',
                            'Start Date' => $formatted['start'],
                            'End Date' => $formatted['end'],
                            'Date Filed' => $formatted['filed'],
                            'Reason' => $leave->reason ?? 'N/A',
                        ],
                        actor: $empName,
                    ));
                }
            } catch (\Exception $ex) {
                // swallow mail errors to avoid blocking the request flow; consider logging
            }
        } else {
            $request->validate([
                'leave_type' => 'required|string|max:100',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'reason' => 'nullable|string|max:2000',
                'details_location' => 'nullable|string|max:50',
                'details_location_specify' => 'nullable|string|max:255',
                'details_sick_illness' => 'nullable|string|max:255',
                'details_sick_treatment' => 'nullable|string|max:50',
            ]);

            // Determine which leave types require extra details or reason (legacy single-type form)
            $parts = array_map('trim', explode(',', $request->leave_type));

            // Block filing if a balance-restricted leave type has zero balance
            $lb = Auth::user()->leaveBalance;
            if ($balanceError = $this->checkZeroBalanceTypes($parts, $lb)) {
                return redirect()->back()->withErrors(['leave_type' => $balanceError])->withInput();
            }

            // Block filing if requested days exceed available credits (legacy path uses calendar-day count)
            $legacyDays = floatval(
                (new \DateTime($request->start_date))->diff(new \DateTime($request->end_date))->days + 1
            );
            $legacyRestricted = [
                'Wellness Leave' => ['column' => 'WLNS', 'label' => 'Wellness Leave'],
                'Compensatory Time Off' => ['column' => 'CTO',  'label' => 'Compensatory Time Off'],
                'Special Privilege Leave' => ['column' => 'SPL',  'label' => 'Special Privilege Leave'],
                'Solo Parent Leave' => ['column' => 'SP',   'label' => 'Solo Parent Leave'],
            ];
            foreach ($parts as $p) {
                if (isset($legacyRestricted[$p])) {
                    $bal = floatval($lb->{$legacyRestricted[$p]['column']} ?? 0);
                    if ($legacyDays > $bal) {
                        return redirect()->back()
                            ->withErrors(['leave_type' => "Insufficient leave credits for {$legacyRestricted[$p]['label']}. You requested {$legacyDays} day(s) but only have {$bal} available."])
                            ->withInput();
                    }
                }
            }

            $needsVacationSpecial = false;
            $needsStudy = false;
            $needsSick = false;
            $requiredForReason = [];
            foreach ($parts as $p) {
                $pl = strtolower($p);
                if (strpos($pl, 'vacation') !== false || strpos($pl, 'special') !== false || strpos($pl, 'spl') !== false || strpos($pl, 'privilege') !== false) {
                    $needsVacationSpecial = true;
                    $requiredForReason[] = $p;
                }
                if (strpos($pl, 'study') !== false) {
                    $needsStudy = true;
                    $requiredForReason[] = $p;
                }
                if (strpos($pl, 'sick') !== false) {
                    $needsSick = true;
                }
            }

            // Server-side: reason when applicable
            if (! empty($requiredForReason) && empty(trim((string) $request->reason))) {
                return redirect()->back()->withErrors(['reason' => 'Reason / Purpose is required for: '.implode(', ', array_unique($requiredForReason))])->withInput();
            }

            // Server-side: require 6.B details when applicable
            if ($needsVacationSpecial) {
                $loc = $request->input('details_location');
                $locSpecify = $request->input('details_location_specify');
                if (empty($loc) && empty(trim((string) $locSpecify))) {
                    return redirect()->back()->withErrors(['details_location' => '6.B Details of Leave (Within/Abroad) required for Vacation/Special Leave.'])->withInput();
                }
            }
            if ($needsStudy) {
                $study = $request->input('details_study_purpose');
                $studyOther = $request->input('details_study_other');
                if (empty($study) && empty(trim((string) $studyOther))) {
                    return redirect()->back()->withErrors(['details_study_purpose' => 'Please specify study purpose (e.g. completion of master\'s, BAR review) for Study Leave.'])->withInput();
                }
            }
            if ($needsSick) {
                $sick = $request->input('details_sick_treatment');
                $sickIllness = $request->input('details_sick_illness');
                if (empty($sick) && empty(trim((string) $sickIllness))) {
                    return redirect()->back()->withErrors(['details_sick_treatment' => '6.B Details of Leave (In Hospital / Out Patient) required for Sick Leave.'])->withInput();
                }
            }

            $leave = LeaveRequest::create([
                'user_id' => Auth::id(),
                'leave_type' => $request->leave_type,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'date_filed' => now()->toDateString(),
                'reason' => $request->reason,
                'details_location' => $request->input('details_location'),
                'details_location_specify' => $request->input('details_location_specify'),
                'details_sick_illness' => $request->input('details_sick_illness'),
                'details_sick_treatment' => $request->input('details_sick_treatment'),
                'status' => 'pending',
            ]);

            // create per-day records in leave_dates for the legacy single-range form
            $periodStart = new \DateTime($request->start_date);
            $periodEnd = new \DateTime($request->end_date);
            $interval = new \DateInterval('P1D');
            $range = new \DatePeriod($periodStart, $interval, (clone $periodEnd)->modify('+1 day'));
            foreach ($range as $dt) {
                LeaveDate::create([
                    'leave_request_id' => $leave->id,
                    'leave_date' => $dt->format('Y-m-d'),
                    'leave_type' => $request->leave_type,
                    'days' => 1.0,
                    'is_lwop' => false,
                ]);
            }

            // Send notification to approver (cc employee) for single-range leave
            // Role-based routing: Department Head / HR Manager → Mayor; Employee → Department Head
            try {
                $employee = User::find($leave->user_id);
                $departmentName = null;
                $approver = null;
                if ($employee && ! empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    if ($department) {
                        $departmentName = $department->Dept_name ?? null;
                    }
                }

                $filerRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($employee->access_level ?? ''))));
                if (in_array($filerRole, ['department head', 'hr manager'])) {
                    $approver = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'mayor'")->first();
                } elseif (isset($department) && $department) {
                    $approver = $this->departmentService->getDepartmentHeadUser($department);
                }

                if ($employee) {
                    $employee->department_name = $departmentName;
                    if ($approver) {
                        $parts = [];
                        if (! empty($approver->first_name)) {
                            $parts[] = $approver->first_name;
                        }
                        if (! empty($approver->middle_name)) {
                            $parts[] = $approver->middle_name;
                        }
                        if (! empty($approver->last_name)) {
                            $parts[] = $approver->last_name;
                        }
                        if (empty($parts) && ! empty($approver->name)) {
                            $parts[] = $approver->name;
                        }
                        $employee->dept_head_name = implode(' ', $parts);
                    }
                }

                $formatted = [
                    'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                    'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                    'end' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                ];

                if ($approver) {
                    $empName = trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: ($employee->name ?? 'Employee');
                    $approver->notify(new HrisTransactionNotification(
                        requestType: 'Leave Request',
                        status: 'Filed',
                        details: [
                            'Employee' => $empName,
                            'Department' => $employee->department_name ?? 'N/A',
                            'Leave Type' => $leave->leave_type ?? 'N/A',
                            'Start Date' => $formatted['start'],
                            'End Date' => $formatted['end'],
                            'Date Filed' => $formatted['filed'],
                            'Reason' => $leave->reason ?? 'N/A',
                        ],
                        actor: $empName,
                    ));
                }
            } catch (\Exception $ex) {
                // swallow mail errors to avoid blocking the request flow; consider logging
            }
        }

        return redirect()->back()->with('success', 'Leave request submitted.');
    }

    public function approve(Request $request, $id)
    {
        return $this->leaveRequestService->approveLeave($request, $id);
    }

    public function edit($id)
    {
        $leave = LeaveRequest::findOrFail($id);
        $user = Auth::user();
        if ($leave->user_id !== $user->id) {
            abort(403);
        }

        return redirect()->back()->with('error', 'Editing leave requests is not supported. Please cancel and refile or contact HR for changes.');
    }

    /**
     * Employee: request cancellation for an approved leave (stores reason, marks pending cancellation)
     */
    public function requestCancellation(Request $request, $id)
    {
        $leave = LeaveRequest::findOrFail($id);
        $user = Auth::user();
        if ($leave->user_id !== $user->id) {
            abort(403);
        }

        if ($leave->status !== 'approved') {
            return redirect()->back()->with('error', 'Only approved leaves can request cancellation.');
        }

        if ($leave->rescheduled_from_id !== null) {
            return redirect()->back()->with('error', 'Reschedule requests cannot be cancelled by the employee.');
        }

        if ($leave->reschedule_status === 'Pending Reschedule') {
            return redirect()->back()->with('error', 'A reschedule request is already pending for this leave. You cannot request cancellation while a reschedule is in progress.');
        }

        if (in_array($leave->cancellation_status, ['Pending Cancellation', 'DH Recommended', 'AO Endorsed'], true)) {
            return redirect()->back()->with('error', 'A cancellation request is already in progress for this leave.');
        }

        if ($leave->leaveDates()->whereIn('cancellation_status', ['Pending Cancellation', 'DH Recommended', 'AO Endorsed'])->exists()) {
            return redirect()->back()->with('error', 'A cancellation request is already in progress for one or more dates on this leave.');
        }

        $request->validate(['reason' => 'required|string|max:2000']);

        if (trim($request->reason) === 'Reported to work') {
            $leaveDates = $leave->leaveDates()->pluck('leave_date')->map(fn ($d) => (string) $d)->toArray();
            $hasAttendance = AttendanceLog::where('user_id', $leave->user_id)
                ->whereIn('logdate', $leaveDates)
                ->exists();
            if (! $hasAttendance) {
                return redirect()->back()->with('error', 'No attendance records found for your leave dates. "Reported to work" requires verified biometric attendance.');
            }
        }

        $leave->cancellation_status = 'Pending Cancellation';
        $leave->cancellation_reason = $request->input('reason');
        $leave->cancellation_requested_at = now();
        $leave->cancellation_requested_by = $user->id;
        $leave->save();

        Log::info('Leave cancellation requested', [
            'leave_id' => $leave->id,
            'employee_id' => $user->id,
            'reason' => substr($leave->cancellation_reason ?? '', 0, 1000),
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Notify approver (best-effort)
        try {
            $employee = $user;
            $approver = null;
            if ($employee && ! empty($employee->Dept_id)) {
                $department = Department::find($employee->Dept_id);
                if ($department) {
                    $approver = $this->departmentService->getDepartmentHeadUser($department);
                }
            }
            if ($approver) {
                $empName = trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: ($employee->name ?? 'Employee');
                $approver->notify(new HrisTransactionNotification(
                    requestType: 'Leave Request',
                    status: 'Cancellation Requested',
                    details: [
                        'Employee' => $empName,
                        'Leave Type' => $leave->leave_type ?? 'N/A',
                        'Start Date' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                        'End Date' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                        'Reason' => $leave->cancellation_reason ?? 'N/A',
                    ],
                    actor: $empName,
                ));
            }
        } catch (\Exception $ex) {
            // swallow
        }

        return redirect()->back()->with('success', 'Cancellation request submitted and pending review.');
    }

    /**
     * Employee: request cancellation of a SUBSET of dates within an approved multi-date
     * leave, leaving the remaining dates approved. Goes through the same DH -> AO ->
     * Leave Manager chain as requestCancellation, but the cancellation_* state lives on
     * the selected leave_dates rows instead of the parent leave_requests row.
     */
    public function requestPartialCancellation(Request $request, $id)
    {
        $leave = LeaveRequest::findOrFail($id);
        $user = Auth::user();
        if ($leave->user_id !== $user->id) {
            abort(403);
        }

        if ($leave->status !== 'approved') {
            return redirect()->back()->with('error', 'Only approved leaves can request cancellation.');
        }

        if ($leave->rescheduled_from_id !== null) {
            return redirect()->back()->with('error', 'Reschedule requests cannot be cancelled by the employee.');
        }

        if ($leave->reschedule_status === 'Pending Reschedule') {
            return redirect()->back()->with('error', 'A reschedule request is already pending for this leave. You cannot request cancellation while a reschedule is in progress.');
        }

        if (in_array($leave->cancellation_status, ['Pending Cancellation', 'DH Recommended', 'AO Endorsed'], true)) {
            return redirect()->back()->with('error', 'A whole-request cancellation is already in progress for this leave.');
        }

        $request->validate([
            'leave_date_ids' => 'required|array|min:1',
            'leave_date_ids.*' => 'integer',
            'reason' => 'required|string|max:2000',
        ]);

        $selectedDates = $leave->leaveDates()
            ->whereIn('id', $request->input('leave_date_ids'))
            ->where('is_cancelled', false)
            ->whereNull('cancellation_status')
            ->whereNull('rescheduled_to_leave_request_id')
            ->get();

        if ($selectedDates->count() !== count($request->input('leave_date_ids'))) {
            return redirect()->back()->with('error', 'One or more selected dates are invalid, already being processed, or no longer available.');
        }

        // If every remaining active date was selected, this is equivalent to a whole-request
        // cancellation — route it through the existing whole-row flow so behavior/state stays identical.
        $remainingActiveCount = $leave->leaveDates()->where('is_cancelled', false)->count();
        if ($selectedDates->count() === $remainingActiveCount) {
            return $this->requestCancellation($request, $id);
        }

        if (trim($request->reason) === 'Reported to work') {
            $selectedLeaveDates = $selectedDates->pluck('leave_date')->map(fn ($d) => (string) $d)->toArray();
            $hasAttendance = AttendanceLog::where('user_id', $leave->user_id)
                ->whereIn('logdate', $selectedLeaveDates)
                ->exists();
            if (! $hasAttendance) {
                return redirect()->back()->with('error', 'No attendance records found for the selected leave dates. "Reported to work" requires verified biometric attendance.');
            }
        }

        foreach ($selectedDates as $ld) {
            $ld->cancellation_status = 'Pending Cancellation';
            $ld->cancellation_reason = $request->input('reason');
            $ld->cancellation_requested_at = now();
            $ld->cancellation_requested_by = $user->id;
            $ld->save();
        }

        Log::info('Partial leave cancellation requested', [
            'leave_id' => $leave->id,
            'leave_date_ids' => $selectedDates->pluck('id')->all(),
            'employee_id' => $user->id,
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Notify approver (best-effort)
        try {
            $employee = $user;
            $approver = null;
            if ($employee && ! empty($employee->Dept_id)) {
                $department = Department::find($employee->Dept_id);
                if ($department) {
                    $approver = $this->departmentService->getDepartmentHeadUser($department);
                }
            }
            if ($approver) {
                $empName = trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: ($employee->name ?? 'Employee');
                $approver->notify(new HrisTransactionNotification(
                    requestType: 'Leave Request',
                    status: 'Partial Cancellation Requested',
                    details: [
                        'Employee' => $empName,
                        'Leave Type' => $leave->leave_type ?? 'N/A',
                        'Dates' => $selectedDates->pluck('leave_date')->map(fn ($d) => Carbon::parse($d)->format('M j, Y'))->implode(', '),
                        'Reason' => $request->input('reason'),
                    ],
                    actor: $empName,
                ));
            }
        } catch (\Exception $ex) {
            // swallow
        }

        return redirect()->back()->with('success', 'Cancellation request submitted for the selected date(s) and is pending review.');
    }

    /**
     * Cancel (immediate) is only allowed for non-approved requests via employee UI.
     * For approved leaves, employees must submit a cancellation request instead.
     */
    public function cancel(Request $request, $id)
    {
        $leave = LeaveRequest::findOrFail($id);
        $user = Auth::user();
        if ($leave->user_id !== $user->id) {
            abort(403);
        }

        if ($leave->status === 'approved') {
            return redirect()->back()->with('error', 'Approved leaves require a cancellation request. Please provide a reason.');
        }

        if ($leave->status !== 'pending') {
            return redirect()->back()->with('error', 'Only pending leave requests can be cancelled directly.');
        }

        if ($leave->rescheduled_from_id !== null) {
            return redirect()->back()->with('error', 'Reschedule requests cannot be cancelled by the employee. Please wait for the approver\'s decision.');
        }

        // Require remarks for cancellation (auditability)
        $request->validate([
            'remarks' => ['required', 'string', 'max:2000'],
        ]);

        $note = trim((string) $request->input('remarks')) ?: 'Cancelled by applicant';

        // mark as cancelled and cancel each leave_date with metadata
        DB::transaction(function () use ($leave, $user, $note) {
            $leave->status = 'cancelled';
            $leave->cancellation_status = 'Cancelled by applicant';
            $leave->cancellation_reason = $note;
            $leave->cancellation_remarks = $note;
            $leave->cancellation_reviewed_by = $user->id;
            $leave->cancellation_reviewed_at = now();
            if (Schema::hasColumn('leave_requests', 'remarks')) {
                $leave->remarks = $note;
            } elseif (Schema::hasColumn('leave_requests', 'action_remarks')) {
                $leave->action_remarks = $note;
            }
            $leave->save();

            $dates = $leave->leaveDates()->where('is_cancelled', false)->get();
            foreach ($dates as $ld) {
                $ld->is_cancelled = true;
                // LeaveDate uses 'cancel_reason' column
                $ld->cancel_reason = $note;
                $ld->cancelled_by = $user->id;
                $ld->cancelled_at = now();
                $ld->save();
            }
        });

        Log::info('Leave request cancelled (direct)', [
            'leave_id' => $leave->id,
            'user_id' => $user->id,
            'remarks' => substr($note, 0, 1000),
            'timestamp' => now()->toDateTimeString(),
        ]);

        // Audit trail
        try {
            HRAuditTrail::create([
                'actor_user_id' => $user->id,
                'module' => 'leave',
                'action' => 'cancel_by_applicant',
                'target_type' => 'leave_request',
                'target_id' => $leave->id,
                'details' => [
                    'remarks' => $note,
                    'cancelled_at' => now()->toDateTimeString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to write HRAuditTrail on cancellation', ['leave_id' => $leave->id, 'error' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Leave request cancelled.');
    }

    /**
     * Employee requests a reschedule for an approved VL/SL/Wellness leave.
     * Creates a new linked leave request that goes through the normal approval pipeline.
     */
    public function requestReschedule(Request $request, $id)
    {
        $original = LeaveRequest::findOrFail($id);
        $user = Auth::user();

        if ($original->user_id !== $user->id) {
            abort(403);
        }

        $reschedulableTypes = ['Vacation Leave', 'Sick Leave', 'Wellness Leave'];
        if (! in_array($original->leave_type, $reschedulableTypes, true)) {
            return redirect()->back()->with('error', 'Only Vacation Leave, Sick Leave, and Wellness Leave can be rescheduled.');
        }

        if ($original->status !== 'approved') {
            return redirect()->back()->with('error', 'Only approved leaves can be rescheduled.');
        }

        if ($original->reschedule_status !== null) {
            return redirect()->back()->with('error', 'A reschedule request is already pending for this leave.');
        }

        if ($original->rescheduled_from_id !== null) {
            return redirect()->back()->with('error', 'This leave was already rescheduled once and cannot be rescheduled again.');
        }

        if (in_array($original->cancellation_status, ['Pending Cancellation', 'DH Recommended', 'AO Endorsed'], true)) {
            return redirect()->back()->with('error', 'A cancellation request is already in progress for this leave. You cannot request a reschedule while a cancellation is pending.');
        }

        if ($original->leaveDates()->whereIn('cancellation_status', ['Pending Cancellation', 'DH Recommended', 'AO Endorsed'])->exists()) {
            return redirect()->back()->with('error', 'A cancellation request is already in progress for one or more dates on this leave. You cannot request a reschedule while a cancellation is pending.');
        }

        $request->validate([
            'leave_types' => 'required|array|min:1|max:1',
            'leave_types.*' => 'string|max:100',
            'leave_dates' => 'required|string|max:2000',
            'reason' => 'nullable|string|max:2000',
            'allocation' => 'nullable|array|max:90',
            'allocation.*.type' => 'nullable|string|max:100',
            'allocation.*.days' => 'nullable|numeric|min:0|max:1',
            'details_location' => 'nullable|string|max:50',
            'details_location_specify' => 'nullable|string|max:255',
            'details_sick_illness' => 'nullable|string|max:255',
            'details_sick_treatment' => 'nullable|string|max:50',
            // Which of the ORIGINAL leave's dates are being replaced. Omitted = every
            // still-active original date (today's whole-request behavior, unchanged).
            'leave_date_ids' => 'nullable|array',
            'leave_date_ids.*' => 'integer',
        ]);

        $type = $request->input('leave_types')[0];
        if (! in_array($type, $reschedulableTypes, true)) {
            return redirect()->back()->withErrors(['leave_types' => 'Invalid leave type for reschedule.'])->withInput();
        }

        $dates = array_values(array_filter(explode(',', $request->leave_dates)));
        if (empty($dates)) {
            return redirect()->back()->withErrors(['leave_dates' => 'Please select at least one date.'])->withInput();
        }
        if (count($dates) !== count(array_unique($dates))) {
            return redirect()->back()->withErrors(['leave_dates' => 'Duplicate dates detected. Please remove duplicates.'])->withInput();
        }

        // Conflict check (exclude the original leave's own dates from conflict detection)
        $originalDateIds = $original->leaveDates()->pluck('leave_date')->map(fn ($d) => (string) $d)->toArray();
        $existing = LeaveDate::whereIn('leave_date', $dates)
            ->where('is_cancelled', false)
            ->whereHas('leaveRequest', function ($q) use ($user, $original) {
                $q->where('user_id', $user->id)
                    ->whereIn('status', ['pending', 'approved'])
                    ->where('id', '!=', $original->id);
            })->pluck('leave_date')->map(fn ($d) => (string) $d)->toArray();
        if (! empty($existing)) {
            return redirect()->back()->withErrors(['leave_dates' => 'You already have leave requests covering: '.implode(', ', $existing)])->withInput();
        }

        // Build per-date allocation
        $allocations = [];
        $rawAllocation = $request->input('allocation', []);
        foreach ($dates as $d) {
            $allocations[$d] = [
                'type' => $rawAllocation[$d]['type'] ?? $type,
                'days' => isset($rawAllocation[$d]['days']) ? floatval($rawAllocation[$d]['days']) : 1.0,
            ];
        }

        $totalDays = array_sum(array_column($allocations, 'days'));

        // Resolve which original dates this reschedule is replacing. An explicit
        // leave_date_ids selects a subset (partial reschedule); omitting it replaces
        // every still-active, unlinked original date (today's whole-request behavior).
        $originalActiveDatesQuery = $original->leaveDates()
            ->where('is_cancelled', false)
            ->whereNull('rescheduled_to_leave_request_id')
            ->whereNull('cancellation_status');

        $selectedOriginalDateIds = $request->input('leave_date_ids');
        if (! empty($selectedOriginalDateIds)) {
            $originalSelectedDates = (clone $originalActiveDatesQuery)->whereIn('id', $selectedOriginalDateIds)->get();
            if ($originalSelectedDates->count() !== count($selectedOriginalDateIds)) {
                return redirect()->back()
                    ->withErrors(['leave_dates' => 'One or more selected original dates are invalid or no longer available for reschedule.'])
                    ->withInput();
            }
        } else {
            $originalSelectedDates = $originalActiveDatesQuery->get();
        }

        $originalSelectedTotal = (float) $originalSelectedDates->sum('days');

        if (abs($totalDays - $originalSelectedTotal) > 0.001) {
            return redirect()->back()
                ->withErrors(['leave_dates' => 'Total rescheduled days ('.$totalDays.') must exactly match the selected original date(s) total ('.$originalSelectedTotal.' days).'])
                ->withInput();
        }

        $paidDays = $totalDays;
        $lwopDays = 0;

        // Balance snapshot at time of reschedule filing
        $lb = $user->leaveBalance;
        $originalSelectedDateIds = $originalSelectedDates->pluck('id')->all();

        $newLeave = DB::transaction(function () use ($original, $user, $type, $dates, $allocations, $totalDays, $paidDays, $lwopDays, $lb, $request, $originalSelectedDateIds) {
            $leave = LeaveRequest::create([
                'user_id' => $user->id,
                'leave_type' => $type,
                'start_date' => $dates[0],
                'end_date' => end($dates),
                'total_days' => $totalDays,
                'paid_days' => $paidDays,
                'lwop_days' => $lwopDays,
                'reason' => $request->reason ? strtoupper(trim($request->reason)) : null,
                'details_location' => $request->details_location,
                'details_location_specify' => $request->details_location_specify,
                'details_sick_illness' => $request->details_sick_illness,
                'details_sick_treatment' => $request->details_sick_treatment,
                'balance_vacation_leave' => optional($lb)->VL ?? 0,
                'balance_sick_leave' => optional($lb)->SL ?? 0,
                'balance_wellness_leave' => optional($lb)->WLNS ?? 0,
                'balance_solo_parent_leave' => optional($lb)->SP ?? 0,
                'balance_special_leave_privilege' => optional($lb)->SPL ?? 0,
                'printing_deduction_details' => json_encode([]),
                'printing_deduction_applied' => false,
                'status' => 'pending',
                'date_filed' => now()->toDateString(),
                'rescheduled_from_id' => $original->id,
            ]);

            LeaveDate::insert(array_map(fn ($d) => [
                'leave_request_id' => $leave->id,
                'leave_date' => $d,
                'leave_type' => $allocations[$d]['type'],
                'days' => $allocations[$d]['days'],
                'is_cancelled' => false,
                'is_lwop' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ], $dates));

            $original->reschedule_status = 'Pending Reschedule';
            $original->save();

            LeaveDate::whereIn('id', $originalSelectedDateIds)
                ->update(['rescheduled_to_leave_request_id' => $leave->id]);

            return $leave;
        });

        // Notify all 4 parties (best-effort)
        try {
            $employee = $user;
            $department = $employee->Dept_id ? Department::find($employee->Dept_id) : null;
            $departmentName = $department?->Dept_name ?? 'N/A';

            $filerRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($employee->access_level ?? ''))));
            $dh = null;
            $ao = null;
            if (in_array($filerRole, ['department head', 'hr manager'])) {
                $dh = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'mayor'")->first();
            } elseif ($department) {
                $dh = $this->departmentService->getDepartmentHeadUser($department);
                $ao = $this->departmentService->getAdminOfficerUser($department);
            }

            $lm = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'leave manager'")->first();

            $empName = trim(collect([$employee->first_name ?? null, $employee->middle_name ?? null, $employee->last_name ?? null])->filter()->implode(' ')) ?: ($employee->name ?? 'Employee');

            $notifDetails = [
                'Employee' => $empName,
                'Department' => $departmentName,
                'Leave Type' => $type,
                'New Start Date' => Carbon::parse($newLeave->start_date)->format('l, F j, Y'),
                'New End Date' => Carbon::parse($newLeave->end_date)->format('l, F j, Y'),
                'Original Leave' => '#'.$original->id.' ('.Carbon::parse($original->start_date)->format('M j').' – '.Carbon::parse($original->end_date)->format('M j, Y').')',
                'Date Filed' => Carbon::parse($newLeave->created_at)->format('l, F j, Y'),
                'Reason' => $newLeave->reason ?? 'N/A',
            ];

            // Employee confirmation
            $employee->notify(new HrisTransactionNotification(
                requestType: 'Leave Reschedule',
                status: 'Filed',
                details: $notifDetails,
                actor: $empName,
            ));

            foreach (array_filter([$dh, $ao, $lm]) as $recipient) {
                $recipient->notify(new HrisTransactionNotification(
                    requestType: 'Leave Reschedule',
                    status: 'Filed',
                    details: $notifDetails,
                    actor: $empName,
                ));
            }
        } catch (\Exception $ex) {
            // swallow notification errors
        }

        return redirect()->route('employee.leave.management')
            ->with('success', 'Reschedule request submitted. It will proceed through the approval process again.');
    }

    /**
     * Returns an error message if the total days requested for any balance-restricted
     * leave type (summed across all per-date allocations) exceeds the employee's balance.
     */
    private function checkInsufficientCredits(array $dates, array $allocations, array $selectedTypes, $leaveBalance): ?string
    {
        $restricted = [
            'Wellness Leave' => ['column' => 'WLNS', 'label' => 'Wellness Leave'],
            'Compensatory Time Off' => ['column' => 'CTO',  'label' => 'Compensatory Time Off'],
            'Special Privilege Leave' => ['column' => 'SPL',  'label' => 'Special Privilege Leave'],
            'Solo Parent Leave' => ['column' => 'SP',   'label' => 'Solo Parent Leave'],
        ];

        $requestedByType = [];
        foreach ($dates as $d) {
            $type = trim($allocations[$d]['type'] ?? ($selectedTypes[0] ?? ''));
            $days = floatval($allocations[$d]['days'] ?? 0);
            if ($type && isset($restricted[$type]) && $days > 0) {
                $requestedByType[$type] = ($requestedByType[$type] ?? 0.0) + $days;
            }
        }

        foreach ($requestedByType as $type => $totalRequested) {
            $col = $restricted[$type]['column'];
            $label = $restricted[$type]['label'];
            $balance = floatval($leaveBalance->{$col} ?? 0);
            if ($totalRequested > $balance) {
                return "Insufficient leave credits for {$label}. You requested {$totalRequested} day(s) but only have {$balance} available.";
            }
        }

        return null;
    }

    /**
     * Returns an error message if any of the supplied leave types are
     * balance-restricted and the employee's current balance is zero.
     */
    private function checkZeroBalanceTypes(array $leaveTypes, $leaveBalance): ?string
    {
        $restricted = [
            'Wellness Leave' => ['column' => 'WLNS', 'label' => 'Wellness Leave'],
            'Compensatory Time Off' => ['column' => 'CTO',  'label' => 'Compensatory Time Off'],
            'Special Privilege Leave' => ['column' => 'SPL',  'label' => 'Special Privilege Leave'],
            'Solo Parent Leave' => ['column' => 'SP',   'label' => 'Solo Parent Leave'],
        ];

        foreach ($leaveTypes as $type) {
            $type = trim($type);
            if (isset($restricted[$type])) {
                $col = $restricted[$type]['column'];
                $label = $restricted[$type]['label'];
                if (floatval($leaveBalance->{$col} ?? 0) <= 0) {
                    return "You cannot file {$label} because your balance is zero.";
                }
            }
        }

        return null;
    }
}
