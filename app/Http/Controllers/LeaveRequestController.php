<?php

namespace App\Http\Controllers;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use setasign\Fpdi\Fpdi;
use App\Models\Department;
use App\Models\User;
use App\Services\LeaveRequestService;
use Illuminate\Support\Facades\Mail;
use App\Mail\LeaveRequestNotification;
use Carbon\Carbon;




class LeaveRequestController extends Controller
{
    private LeaveRequestService $leaveRequestService;

    public function __construct(LeaveRequestService $leaveRequestService)
    {
        $this->leaveRequestService = $leaveRequestService;
    }
    public function index()
    {
        $user = Auth::user();
        $leaveRequests = LeaveRequest::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

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

        if (! $this->leaveRequestService->canPrint($leave, $user)) {
            abort(403);
        }

        if ($leave->status !== 'approved') {
            abort(403);
        }

        return $this->leaveRequestService->generatePdfResponse($leave);
    }

    public function store(Request $request)
    {
     
        if ($request->has('leave_dates')) {
            $request->validate([
                'leave_types' => 'required|array|min:1',
                'leave_dates' => 'required|string',
                'reason' => 'nullable|string',
            ]);

            $dates = array_values(array_filter(explode(',', $request->leave_dates)));
            // Prevent duplicate dates from being submitted (human error or tampering)
            if (count($dates) !== count(array_unique($dates))) {
                return redirect()->back()->withErrors(['leave_dates' => 'Duplicate dates detected in your selection. Please remove duplicates and try again.'])->withInput();
            }
            if (empty($dates)) {
                return redirect()->back()->withErrors(['leave_dates' => 'Please select at least one date.'])->withInput();
            }

            
            $conflicts = [];
            $existing = \App\Models\LeaveDate::whereIn('leave_date', $dates)
                ->where('is_cancelled', false)
                ->whereHas('leaveRequest', function($q) {
                    $q->where('user_id', Auth::id())
                      ->whereIn('status', ['pending','approved']);
                })->get();
            $existingDates = $existing->pluck('leave_date')->map(function($d){ return (string)$d; })->toArray();
            foreach ($dates as $d) {
                if (in_array($d, $existingDates, true)) $conflicts[] = $d;
            }
            if (!empty($conflicts)) {
                return redirect()->back()->withErrors(['leave_dates' => 'You already have leave requests covering these dates: ' . implode(', ', $conflicts)])->withInput();
            }

            sort($dates);
            $startDate = $dates[0];
            $endDate = end($dates);

            $today = (new \DateTime())->setTime(0,0,0);
            $start = (new \DateTime($startDate))->setTime(0,0,0);
            $end = (new \DateTime($endDate))->setTime(0,0,0);

            // Enforce Vacation Leave lead time per-date based on allocation type
            $selectedTypes = $request->input('leave_types', []);
            $allocations = $request->input('allocation', []);
            $minStart = (clone $today)->add(new \DateInterval('P5D'));
            $vacationViolations = [];
            foreach ($dates as $d) {
                $typeForDate = $allocations[$d]['type'] ?? ($selectedTypes[0] ?? null);
                if ($typeForDate === 'Vacation Leave') {
                    $dt = (new \DateTime($d))->setTime(0,0,0);
                    if ($dt < $minStart) $vacationViolations[] = $d;
                }
            }
            if (!empty($vacationViolations)) {
                return redirect()->back()->withErrors(['leave_dates' => 'Vacation Leave must be filed at least 5 calendar days before these dates: ' . implode(', ', $vacationViolations)])->withInput();
            }

            // Map selected types into a single string to store (preserve existing DB shape)
            $leaveTypeValue = implode(', ', $selectedTypes);

            // Determine which leave types require extra details or reason
            $needsVacationSpecial = false;
            $needsStudy = false;
            $needsSick = false;
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
            }

            // Server-side: require Reason/Purpose when applicable
            if (!empty($requiredForReason) && empty(trim((string)$request->reason))) {
                return redirect()->back()->withErrors(['reason' => 'Reason / Purpose is required for: ' . implode(', ', array_unique($requiredForReason))])->withInput();
            }

            // Server-side: require 6.B details for Vacation/Special (within/abroad) when needed
            if ($needsVacationSpecial) {
                $loc = $request->input('details_location');
                $locSpecify = $request->input('details_location_specify');
                if (empty($loc) && empty(trim((string)$locSpecify))) {
                    return redirect()->back()->withErrors(['details_location' => '6.B Details of Leave (Within/Abroad) required for Vacation/Special Leave.'])->withInput();
                }
            }

            // Server-side: require study details (checkboxes or specify) and reason already enforced above
            if ($needsStudy) {
                $study = $request->input('details_study_purpose');
                $studyOther = $request->input('details_study_other');
                if (empty($study) && empty(trim((string)$studyOther))) {
                    return redirect()->back()->withErrors(['details_study_purpose' => 'Please specify study purpose (e.g. completion of master\'s, BAR review) for Study Leave.'])->withInput();
                }
            }

            // Server-side: require sick details (in hospital / outpatient or specify illness)
            if ($needsSick) {
                $sick = $request->input('details_sick_treatment');
                $sickIllness = $request->input('details_sick_illness');
                if (empty($sick) && empty(trim((string)$sickIllness))) {
                    return redirect()->back()->withErrors(['details_sick_treatment' => '6.B Details of Leave (In Hospital / Out Patient) required for Sick Leave.'])->withInput();
                }
            }

            // Process allocations to compute totals and perform deductions
            $allocations = $request->input('allocation', []);
            $totalDays = 0.0;
            $paidDays = 0.0;
            $lwopDays = 0.0;

            $user = Auth::user();
            $lb = $user->leaveBalance;

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
            foreach ($dates as $d) {
                $totalDaysForDate = 0.0;
                if (isset($allocations[$d]['days'])) {
                    $totalDaysForDate = floatval($allocations[$d]['days']);
                }
                $totalDays += $totalDaysForDate;

                $typeForDate = $allocations[$d]['type'] ?? ($selectedTypes[0] ?? null);
                if (!$typeForDate) {
                    // treat as unpaid if no type indicated
                    $lwopDays += $totalDaysForDate;
                    continue;
                }

                switch ($typeForDate) {
                    case 'Vacation Leave':
                        $ded = min($vl, $totalDaysForDate);
                        $vl -= $ded;
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
                        $paidDays += $dedSl;
                        $rem = $totalDaysForDate - $dedSl;
                        if ($rem > 0) {
                            $dedVl = min($vl, $rem);
                            $vl -= $dedVl;
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
                        $paidDays += $ded;
                        $rem = $totalDaysForDate - $ded;
                        if ($rem > 0) $lwopDays += $rem;
                        break;
                    case 'Solo Parent Leave':
                        $ded = min($sp, $totalDaysForDate);
                        $sp -= $ded;
                        $paidDays += $ded;
                        $rem = $totalDaysForDate - $ded;
                        if ($rem > 0) $lwopDays += $rem;
                        break;
                    case 'Special Leave Privilege':
                        $ded = min($spl, $totalDaysForDate);
                        $spl -= $ded;
                        $paidDays += $ded;
                        $rem = $totalDaysForDate - $ded;
                        if ($rem > 0) $lwopDays += $rem;
                        break;
                    default:
                        // other leave types do not deduct from balances
                        $paidDays += $totalDaysForDate;
                        break;
                }
            }

            // ensure no negatives
            $vl = max(0, $vl);
            $sl = max(0, $sl);
            $wlns = max(0, $wlns);
            $sp = max(0, $sp);
            $spl = max(0, $spl);

            // update user's leave balance atomically and create leave request
            $leave = DB::transaction(function () use ($user, $snap_vl, $snap_sl, $snap_wlns, $snap_sp, $snap_spl, $leaveTypeValue, $startDate, $endDate, $request, $totalDays, $paidDays, $lwopDays) {
                // create leave request with snapshot balances and computed fields
                return LeaveRequest::create([
                    'user_id' => Auth::id(),
                    'leave_type' => $leaveTypeValue,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'reason' => $request->reason,
                    'details_location' => $request->input('details_location'),
                    'details_location_specify' => $request->input('details_location_specify'),
                    'details_sick_illness' => $request->input('details_sick_illness'),
                    'details_sick_treatment' => $request->input('details_sick_treatment'),
                    'status' => 'pending',
                    'total_days' => $totalDays,
                    'paid_days' => $paidDays,
                    'lwop_days' => $lwopDays,
                    'balance_vacation_leave' => $snap_vl,
                    'balance_sick_leave' => $snap_sl,
                    'balance_wellness_leave' => $snap_wlns,
                    'balance_solo_parent_leave' => $snap_sp,
                    'balance_special_leave_privilege' => $snap_spl,
                ]);
            });

            // create per-day records in leave_dates
            foreach ($dates as $d) {
                \App\Models\LeaveDate::create([
                    'leave_request_id' => $leave->id,
                    'leave_date' => $d,
                    'is_lwop' => false,
                ]);
            }

            // Send notification to approver (cc employee)
            // Role-based routing: Department Head / HR Manager → Mayor; Employee → Department Head
            try {
                $employee = User::find($leave->user_id);
                $departmentName = null;
                $approver = null;
                if ($employee && !empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    if ($department) {
                        $departmentName = $department->Dept_name ?? null;
                    }
                }

                $filerRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($employee->access_level ?? ''))));
                if (in_array($filerRole, ['department head', 'hr manager'])) {
                    $approver = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'mayor'")->first();
                } else {
                    if (isset($department) && $department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                        $approver = User::where('EmpNo', $department->EmpNo)->first();
                    }
                }

                if ($employee) {
                    $employee->department_name = $departmentName;
                    if ($approver) {
                        $parts = [];
                        if (!empty($approver->first_name)) $parts[] = $approver->first_name;
                        if (!empty($approver->middle_name)) $parts[] = $approver->middle_name;
                        if (!empty($approver->last_name)) $parts[] = $approver->last_name;
                        if (empty($parts) && !empty($approver->name)) $parts[] = $approver->name;
                        $employee->dept_head_name = implode(' ', $parts);
                    }
                }

                $formatted = [
                    'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                    'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                    'end' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                ];

                if ($approver && !empty($approver->email)) {
                    Mail::to($approver->email)->cc($employee->email ?? null)->queue(new LeaveRequestNotification($employee, $leave, $formatted));
                }
            } catch (\Exception $ex) {
                // swallow mail errors to avoid blocking the request flow; consider logging
            }
        } else {
            $request->validate([
                'leave_type' => 'required|string',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'reason' => 'nullable|string',
            ]);

            // Determine which leave types require extra details or reason (legacy single-type form)
            $parts = array_map('trim', explode(',', $request->leave_type));
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
            if (!empty($requiredForReason) && empty(trim((string)$request->reason))) {
                return redirect()->back()->withErrors(['reason' => 'Reason / Purpose is required for: ' . implode(', ', array_unique($requiredForReason))])->withInput();
            }

            // Server-side: require 6.B details when applicable
            if ($needsVacationSpecial) {
                $loc = $request->input('details_location');
                $locSpecify = $request->input('details_location_specify');
                if (empty($loc) && empty(trim((string)$locSpecify))) {
                    return redirect()->back()->withErrors(['details_location' => '6.B Details of Leave (Within/Abroad) required for Vacation/Special Leave.'])->withInput();
                }
            }
            if ($needsStudy) {
                $study = $request->input('details_study_purpose');
                $studyOther = $request->input('details_study_other');
                if (empty($study) && empty(trim((string)$studyOther))) {
                    return redirect()->back()->withErrors(['details_study_purpose' => 'Please specify study purpose (e.g. completion of master\'s, BAR review) for Study Leave.'])->withInput();
                }
            }
            if ($needsSick) {
                $sick = $request->input('details_sick_treatment');
                $sickIllness = $request->input('details_sick_illness');
                if (empty($sick) && empty(trim((string)$sickIllness))) {
                    return redirect()->back()->withErrors(['details_sick_treatment' => '6.B Details of Leave (In Hospital / Out Patient) required for Sick Leave.'])->withInput();
                }
            }

            $leave = LeaveRequest::create([
                'user_id' => Auth::id(),
                'leave_type' => $request->leave_type,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
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
                \App\Models\LeaveDate::create([
                    'leave_request_id' => $leave->id,
                    'leave_date' => $dt->format('Y-m-d'),
                    'is_lwop' => false,
                ]);
            }

            // Send notification to approver (cc employee) for single-range leave
            // Role-based routing: Department Head / HR Manager → Mayor; Employee → Department Head
            try {
                $employee = User::find($leave->user_id);
                $departmentName = null;
                $approver = null;
                if ($employee && !empty($employee->Dept_id)) {
                    $department = Department::find($employee->Dept_id);
                    if ($department) {
                        $departmentName = $department->Dept_name ?? null;
                    }
                }

                $filerRole = strtolower(str_replace(['-', '_'], ' ', trim((string) ($employee->access_level ?? ''))));
                if (in_array($filerRole, ['department head', 'hr manager'])) {
                    $approver = User::whereRaw("LOWER(REPLACE(REPLACE(access_level, '-', ' '), '_', ' ')) = 'mayor'")->first();
                } else {
                    if (isset($department) && $department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                        $approver = User::where('EmpNo', $department->EmpNo)->first();
                    }
                }

                if ($employee) {
                    $employee->department_name = $departmentName;
                    if ($approver) {
                        $parts = [];
                        if (!empty($approver->first_name)) $parts[] = $approver->first_name;
                        if (!empty($approver->middle_name)) $parts[] = $approver->middle_name;
                        if (!empty($approver->last_name)) $parts[] = $approver->last_name;
                        if (empty($parts) && !empty($approver->name)) $parts[] = $approver->name;
                        $employee->dept_head_name = implode(' ', $parts);
                    }
                }

                $formatted = [
                    'filed' => Carbon::parse($leave->created_at)->format('l, F j, Y'),
                    'start' => Carbon::parse($leave->start_date)->format('l, F j, Y'),
                    'end' => Carbon::parse($leave->end_date)->format('l, F j, Y'),
                ];

                if ($approver && !empty($approver->email)) {
                    Mail::to($approver->email)->cc($employee->email ?? null)->queue(new LeaveRequestNotification($employee, $leave, $formatted));
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

    
    public function cancel(Request $request, $id)
    {
        $leave = LeaveRequest::findOrFail($id);
        $user = Auth::user();
        if ($leave->user_id !== $user->id) {
            abort(403);
        }

        if ($leave->status !== 'pending') {
            return redirect()->back()->with('error', 'Only pending leave requests can be cancelled.');
        }

        $leave->status = 'cancelled';
        $note = $request->input('remarks') ?? 'Cancelled by applicant';
        // Safely write to an available remarks column if present
        if (Schema::hasColumn('leave_requests', 'remarks')) {
            $leave->remarks = $note;
        } elseif (Schema::hasColumn('leave_requests', 'action_remarks')) {
            $leave->action_remarks = $note;
        }
        $leave->save();

        return redirect()->back()->with('success', 'Leave request cancelled.');
    }
}