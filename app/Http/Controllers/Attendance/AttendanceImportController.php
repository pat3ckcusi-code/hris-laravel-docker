<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Jobs\ImportAttendanceLogsJob;
use App\Models\Department;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AttendanceImportController extends Controller
{
    public function index(): View
    {
        $departments = Department::orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);
        $setting = Setting::first();

        return view('hr-manager.attendance-import', compact('departments', 'setting'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'from_date' => ['required', 'date', 'before_or_equal:to_date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'dept_id' => ['nullable', 'integer', 'exists:departments,Dept_id'],
        ]);

        $deptId = isset($validated['dept_id']) ? (int) $validated['dept_id'] : null;

        foreach (CarbonPeriod::create($validated['from_date'], $validated['to_date']) as $date) {
            $day = $date->toDateString();
            ImportAttendanceLogsJob::dispatch($day, $day, $request->user()->id, $deptId);
        }

        $deptLabel = $deptId
            ? (Department::find($deptId)?->Dept_name ?? "Department #{$deptId}")
            : 'All Departments';

        $fromFormatted = Carbon::parse($validated['from_date'])->format('M j, Y');
        $toFormatted = Carbon::parse($validated['to_date'])->format('M j, Y');
        $dayCount = Carbon::parse($validated['from_date'])->diffInDays($validated['to_date']) + 1;

        return redirect()
            ->route('hr-manager.attendance.import')
            ->with('success', "Attendance import queued: {$fromFormatted} to {$toFormatted} — {$deptLabel}. {$dayCount} job(s) dispatched. Results will be recorded in the audit log.");
    }
}
