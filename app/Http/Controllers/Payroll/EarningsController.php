<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\User;
use App\Support\HrisConstants;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EarningsController extends Controller
{
    public function index(): View
    {
        $earningTypes = Earning::withCount('employeeEarnings')->paginate(20);

        return view('payroll.earnings', compact('earningTypes'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.earnings.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'type' => 'required|string|max:150',
            'allowance_type' => 'nullable|in:pera,hazard_pay,subsistence_allowance,laundry_allowance,other',
            'description' => 'nullable|string|max:500',
            'recurring' => 'boolean',
        ]);

        Earning::create($request->only('type', 'allowance_type', 'description', 'recurring'));

        return redirect()->route('payroll.earnings.index')
            ->with('status', 'Earning type created.');
    }

    public function show(int $id): View
    {
        $earning = Earning::with('employeeEarnings.employee')->findOrFail($id);

        $employees = User::active()->orderBy('name')->get(['id', 'name', 'EmpNo', 'employee_type']);

        $assignedEmployeeIds = $earning->employeeEarnings->pluck('employee_id')->all();

        $employeeTypes = HrisConstants::EMPLOYEE_TYPES;

        return view('payroll.earning-show', compact('earning', 'employees', 'assignedEmployeeIds', 'employeeTypes'));
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.earnings.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'type' => 'required|string|max:150',
            'allowance_type' => 'nullable|in:pera,hazard_pay,subsistence_allowance,laundry_allowance,other',
            'description' => 'nullable|string|max:500',
            'recurring' => 'boolean',
        ]);

        Earning::findOrFail($id)->update($request->only('type', 'allowance_type', 'description', 'recurring'));

        return redirect()->route('payroll.earnings.index')
            ->with('status', 'Earning type updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $earning = Earning::findOrFail($id);

        if ($earning->employeeEarnings()->exists()) {
            return redirect()->route('payroll.earnings.index')
                ->with('error', 'Cannot delete this earning type while employees are still assigned to it. Remove those assignments first.');
        }

        $earning->delete();

        return redirect()->route('payroll.earnings.index')
            ->with('status', 'Earning type deleted.');
    }
}
