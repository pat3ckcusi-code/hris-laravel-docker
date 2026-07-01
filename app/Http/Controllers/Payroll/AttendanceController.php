<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Dtr;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        $query = Dtr::with('employee')->latest('date');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }
        if ($request->filled('date')) {
            $query->whereDate('date', $request->date);
        }

        $records = $query->paginate(20);
        $employees = User::orderBy('last_name')->get();

        return view('payroll.attendance', compact('records', 'employees'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.attendance.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'employee_id' => 'required|exists:users,id',
            'date' => 'required|date',
            'time_in_am' => 'nullable|date_format:H:i',
            'time_out_am' => 'nullable|date_format:H:i',
            'time_in_pm' => 'nullable|date_format:H:i',
            'time_out_pm' => 'nullable|date_format:H:i',
            'status' => 'required|string|max:50',
        ]);

        $exists = Dtr::where('employee_id', $request->employee_id)
            ->whereDate('date', $request->date)
            ->exists();

        if ($exists) {
            return back()->withInput()
                ->with('error', 'A DTR record already exists for this employee on this date. Edit the existing record instead.');
        }

        Dtr::create($request->only('employee_id', 'date', 'time_in_am', 'time_out_am', 'time_in_pm', 'time_out_pm', 'status'));

        return redirect()->route('payroll.attendance.index')
            ->with('status', 'DTR record added.');
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.attendance.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.attendance.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'time_in_am' => 'nullable|date_format:H:i',
            'time_out_am' => 'nullable|date_format:H:i',
            'time_in_pm' => 'nullable|date_format:H:i',
            'time_out_pm' => 'nullable|date_format:H:i',
            'status' => 'required|string|max:50',
        ]);

        $record = Dtr::findOrFail($id);
        $record->update($request->only('time_in_am', 'time_out_am', 'time_in_pm', 'time_out_pm', 'status'));

        return redirect()->route('payroll.attendance.index')
            ->with('status', 'DTR record updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        Dtr::findOrFail($id)->delete();

        return redirect()->route('payroll.attendance.index')
            ->with('status', 'DTR record deleted.');
    }
}
