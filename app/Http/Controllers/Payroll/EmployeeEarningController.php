<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\EmployeeEarning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeEarningController extends Controller
{
    public function store(Request $request, int $earningId): RedirectResponse
    {
        $earning = Earning::findOrFail($earningId);

        $request->validate([
            'employee_id' => 'required|exists:users,id',
            'amount_type' => 'required|in:fixed,percentage',
            'amount'      => 'required_if:amount_type,fixed|nullable|numeric|min:0',
            'percentage'  => 'required_if:amount_type,percentage|nullable|numeric|between:0,100',
            'recurring'   => 'boolean',
        ]);

        $exists = EmployeeEarning::where('employee_id', $request->employee_id)
            ->where('earnings_id', $earning->id)
            ->exists();

        if ($exists) {
            return redirect()->route('payroll.earnings.show', $earningId)
                ->with('error', 'This employee already has this earning type assigned.');
        }

        EmployeeEarning::create([
            'employee_id' => $request->employee_id,
            'earnings_id' => $earning->id,
            'amount_type' => $request->amount_type,
            'amount'      => $request->amount_type === 'fixed' ? $request->amount : 0,
            'percentage'  => $request->amount_type === 'percentage' ? $request->percentage : null,
            'recurring'   => $request->boolean('recurring'),
        ]);

        return redirect()->route('payroll.earnings.show', $earningId)
            ->with('status', 'Employee assigned to earning type.');
    }

    public function update(Request $request, int $earningId, int $id): RedirectResponse
    {
        $assignment = EmployeeEarning::where('earnings_id', $earningId)->findOrFail($id);

        $request->validate([
            'amount_type' => 'required|in:fixed,percentage',
            'amount'      => 'required_if:amount_type,fixed|nullable|numeric|min:0',
            'percentage'  => 'required_if:amount_type,percentage|nullable|numeric|between:0,100',
            'recurring'   => 'boolean',
        ]);

        $assignment->update([
            'amount_type' => $request->amount_type,
            'amount'      => $request->amount_type === 'fixed' ? $request->amount : 0,
            'percentage'  => $request->amount_type === 'percentage' ? $request->percentage : null,
            'recurring'   => $request->boolean('recurring'),
        ]);

        return redirect()->route('payroll.earnings.show', $earningId)
            ->with('status', 'Assignment updated.');
    }

    public function destroy(int $earningId, int $id): RedirectResponse
    {
        EmployeeEarning::where('earnings_id', $earningId)->findOrFail($id)->delete();

        return redirect()->route('payroll.earnings.show', $earningId)
            ->with('status', 'Assignment removed.');
    }
}
