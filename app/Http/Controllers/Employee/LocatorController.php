<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Locator;
use App\Models\Department;
use App\Models\User;
use App\Services\LocatorExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use App\Mail\FileLocatorOfficialNotification;
use App\Mail\FileLocatorPersonalNotification;

class LocatorController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $locators = Locator::where('user_id', $user->id)->orderBy('travel_date', 'desc')->paginate(10);
        return view('employee.locator', compact('locators'));
    }

    public function edit(Locator $locator)
    {
        $user = Auth::user();
        if ($locator->user_id !== $user->id) abort(403);

        $locators = Locator::where('user_id', $user->id)->orderBy('travel_date', 'desc')->paginate(10);
        $editLocator = $locator;
        return view('employee.locator', compact('locators', 'editLocator'));
    }


    public function store(Request $request)
    {
        // Normalize times to HH:MM (pad with zero if needed)
        $request->merge([
            'intended_departure_time' => $this->normalizeTime($request->input('intended_departure_time')),
            'intended_arrival_time' => $this->normalizeTime($request->input('intended_arrival_time')),
        ]);

        $rules = [
            'application_type' => 'required|in:Official,Personal',
            'location' => 'required|string|max:255',
            'travel_date' => 'required|date|after_or_equal:today',
            'intended_departure_time' => 'required|date_format:H:i',
            'intended_arrival_time' => 'required|date_format:H:i|after_or_equal:intended_departure_time',
            'detail' => 'required|string',
            'actual_arrival_time' => 'nullable|date_format:H:i',
        ];

        $validator = Validator::make($request->all(), $rules);

        // After validation: enforce maximum duration depending on application type
        $validator->after(function ($v) use ($request) {
            $appType = $request->input('application_type');
            $dep = $request->input('intended_departure_time');
            $arr = $request->input('intended_arrival_time');

            if ($appType && $dep && $arr) {
                try {
                    $depTime = Carbon::createFromFormat('H:i', $dep);
                    $arrTime = Carbon::createFromFormat('H:i', $arr);
                } catch (\Exception $e) {
                    return;
                }

                // if arrival is on next day, treat as longer than allowed (business rule)
                $minutes = $depTime->diffInMinutes($arrTime, false);
                if ($minutes < 0) {
                    // arrival earlier than departure already prevented by rule, but guard anyway
                    $v->errors()->add('intended_arrival_time', 'Intended arrival cannot be earlier than departure.');
                    return;
                }

                $allowed = $appType === 'Official' ? 180 : 120; // minutes
                if ($minutes > $allowed) {
                    $hours = $allowed / 60;
                    $v->errors()->add('intended_arrival_time', "For {$appType} application, travel duration cannot exceed {$hours} hour(s).");
                }
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $data['user_id'] = Auth::id();
        $locator = Locator::create($data + ['status' => 'pending']);

        // Determine department head and send notification
        $employee = User::find($locator->user_id);
        $departmentName = null;
        $departmentHead = null;
        if ($employee && !empty($employee->Dept_id)) {
            $department = Department::find($employee->Dept_id);
            if ($department) {
                $departmentName = $department->Dept_name ?? null;
                if (!empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                    $departmentHead = User::where('EmpNo', $department->EmpNo)->first();
                }
            }
        }

        if ($employee) {
            $employee->department_name = $departmentName;
            if ($departmentHead) {
                $parts = [];
                if (!empty($departmentHead->first_name)) $parts[] = $departmentHead->first_name;
                if (!empty($departmentHead->middle_name)) $parts[] = $departmentHead->middle_name;
                if (!empty($departmentHead->last_name)) $parts[] = $departmentHead->last_name;
                if (empty($parts) && !empty($departmentHead->name)) $parts[] = $departmentHead->name;
                $employee->dept_head_name = implode(' ', $parts);
            }
        }

        if ($departmentHead && !empty($departmentHead->email)) {
            try {
                if (strtolower($locator->application_type) === 'official') {
                    Mail::to($departmentHead->email)
                        ->cc($employee->email ?? null)
                        ->queue(new FileLocatorOfficialNotification($employee, $locator));
                } else {
                    Mail::to($departmentHead->email)
                        ->cc($employee->email ?? null)
                        ->queue(new FileLocatorPersonalNotification($employee, $locator));
                }
            } catch (\Exception $ex) {
                // ignore mail errors for now
            }
        }

        return redirect()->route('dashboard.employee.locator')->with('success', 'Locator filed successfully.');
    }

    public function printSingle(Locator $locator, LocatorExportService $exportService)
    {
        $user = Auth::user();

        // allow owner, department head for the owner, or administrative officer
        $allowed = false;
        if ($locator->user_id === $user->id) {
            $allowed = true;
        } else {
            $owner = $locator->user;
            $deptHeadUser = null;
            if ($owner && !empty($owner->Dept_id)) {
                $department = Department::find($owner->Dept_id);
                if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                    $deptHeadUser = User::where('EmpNo', $department->EmpNo)->first();
                }
            } elseif ($owner && !empty($owner->EmpNo)) {
                $department = Department::where('EmpNo', $owner->EmpNo)->first();
                if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                    $deptHeadUser = User::where('EmpNo', $department->EmpNo)->first();
                }
            }

            if ($deptHeadUser && $deptHeadUser->id === $user->id) {
                $allowed = true;
            }

            $role = strtolower(trim((string)$user->access_level));
            if ($role === 'administrative officer') {
                $allowed = true;
            }
        }

        if (! $allowed) {
            abort(403);
        }

        if ($locator->status !== 'approved') abort(403);

        return $exportService->generateExcelResponse($locator);
    }

    public function data(Request $request)
    {
        $user = Auth::user();
        $locators = Locator::where('user_id', $user->id)->orderBy('travel_date','desc')->get()->map(function($l){
            return [
                'id' => $l->id,
                'application_type' => $l->application_type,
                'location' => $l->location,
                'travel_date' => $l->travel_date,
                'intended_departure_time' => $l->intended_departure_time,
                'intended_arrival_time' => $l->intended_arrival_time,
                'detail' => $l->detail,
                'actual_arrival_time' => $l->actual_arrival_time,
                'status' => $l->status,
                'cancelled_by' => $l->cancelled_by ?? null,
                'cancelled_at' => $l->cancelled_at ? $l->cancelled_at->toDateTimeString() : null,
                'cancellation_remarks' => $l->cancellation_remarks ?? null,
            ];
        });

        return response()->json(['data' => $locators]);
    }

    public function update(Request $request, Locator $locator)
    {
        $user = Auth::user();
        if ($locator->user_id !== $user->id) abort(403);
        if ($locator->status !== 'pending') abort(403);

        $request->merge([
            'intended_departure_time' => $this->normalizeTime($request->input('intended_departure_time')),
            'intended_arrival_time' => $this->normalizeTime($request->input('intended_arrival_time')),
        ]);

        $rules = [
            'application_type' => 'required|in:Official,Personal',
            'location' => 'required|string|max:255',
            'travel_date' => 'required|date|after_or_equal:today',
            'intended_departure_time' => ['required', 'date_format:H:i'],
            'intended_arrival_time' => ['required', 'date_format:H:i', 'after_or_equal:intended_departure_time'],
            'detail' => 'required|string',
        ];

        $messages = [
            'intended_departure_time.required' => 'The intended departure time field is required.',
            'intended_departure_time.date_format' => 'The intended departure time must be in the format HH:MM (24-hour).',
            'intended_arrival_time.required' => 'The intended arrival time field is required.',
            'intended_arrival_time.date_format' => 'The intended arrival time must be in the format HH:MM (24-hour).',
            'intended_arrival_time.after_or_equal' => 'The intended arrival time must not be earlier than the departure time.',
        ];

        $validator = Validator::make($request->all(), $rules, $messages);

        $validator->after(function ($v) use ($request) {
            $appType = $request->input('application_type');
            $dep = $request->input('intended_departure_time');
            $arr = $request->input('intended_arrival_time');

            if ($appType && $dep && $arr) {
                try {
                    $depTime = Carbon::createFromFormat('H:i', $dep);
                    $arrTime = Carbon::createFromFormat('H:i', $arr);
                } catch (\Exception $e) {
                    return;
                }

                $minutes = $depTime->diffInMinutes($arrTime, false);
                if ($minutes < 0) {
                    $v->errors()->add('intended_arrival_time', 'Intended arrival cannot be earlier than departure.');
                    return;
                }

                $allowed = $appType === 'Official' ? 180 : 120;
                if ($minutes > $allowed) {
                    $hours = $allowed / 60;
                    $v->errors()->add('intended_arrival_time', "For {$appType} application, travel duration cannot exceed {$hours} hour(s).");
                }
            }
        });

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $locator->update($data);

        return redirect()->route('dashboard.employee.locator')->with('success', 'Locator updated successfully.');
    }

    // Cancel locator (by owner) — accepts AJAX or form POST
    public function cancel(Request $request, Locator $locator)
    {
        $user = Auth::user();
        if ($locator->user_id !== $user->id) {
            abort(403);
        }

        if ($locator->status !== 'pending') {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Only pending locators can be cancelled.'], 400);
            }
            return redirect()->back()->with('error', 'Only pending locators can be cancelled.');
        }

        $remarks = trim((string) $request->input('remarks', 'Cancelled by applicant'));

        $locator->status = 'cancelled';
        $locator->cancelled_by = $user->id;
        $locator->cancelled_at = now();
        $locator->cancellation_remarks = $remarks;
        $locator->save();

        // write audit trail where available
        try {
            if (class_exists('\App\\Models\\HRAuditTrail')) {
                \App\Models\HRAuditTrail::create([
                    'actor_user_id' => $user->id,
                    'module' => 'locator',
                    'action' => 'cancel',
                    'target_type' => 'locator',
                    'target_id' => $locator->id,
                    'details' => [
                        'cancellation_remarks' => $remarks,
                        'cancelled_by' => $user->id,
                        'timestamp' => now()->toDateTimeString(),
                    ],
                ]);
            }
        } catch (\Exception $e) {
            // log but do not block user
            \Log::error('Failed to write HRAuditTrail for Locator cancellation', ['locator_id' => $locator->id, 'error' => $e->getMessage()]);
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'Locator cancelled.']);
        }

        return redirect()->route('dashboard.employee.locator')->with('success', 'Locator cancelled.');
    }
   
    private function normalizeTime($time)
    {
        if (!$time) return $time;
        $parts = explode(':', $time);
        if (count($parts) < 2) return $time;
        $h = str_pad((int)$parts[0], 2, '0', STR_PAD_LEFT);
        $m = str_pad((int)$parts[1], 2, '0', STR_PAD_LEFT);
        return "$h:$m";
    }
}
