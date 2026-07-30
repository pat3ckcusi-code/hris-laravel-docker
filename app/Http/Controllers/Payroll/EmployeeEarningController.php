<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Earning;
use App\Models\EmployeeEarning;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeEarningController extends Controller
{
    public function store(Request $request, int $earningId): RedirectResponse
    {
        $earning = Earning::findOrFail($earningId);

        $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|distinct|exists:users,id',
            'amount_type' => 'required|in:fixed,percentage',
            'amount' => 'required_if:amount_type,fixed|nullable|numeric|min:0',
            'percentage' => 'required_if:amount_type,percentage|nullable|numeric|between:0,100',
            'recurring' => 'boolean',
        ]);

        $submittedIds = array_unique(array_map('intval', $request->input('employee_ids', [])));

        $eligibleIds = User::active()->whereIn('id', $submittedIds)->pluck('id')->all();

        $alreadyAssignedIds = EmployeeEarning::where('earnings_id', $earning->id)
            ->whereIn('employee_id', $eligibleIds)
            ->pluck('employee_id')
            ->all();

        $toAssign = array_values(array_diff($eligibleIds, $alreadyAssignedIds));

        $amount = $request->amount_type === 'fixed' ? $request->amount : 0;
        $percentage = $request->amount_type === 'percentage' ? $request->percentage : null;
        $recurring = $request->boolean('recurring');

        DB::transaction(function () use ($toAssign, $earning, $request, $amount, $percentage, $recurring) {
            foreach ($toAssign as $employeeId) {
                EmployeeEarning::create([
                    'employee_id' => $employeeId,
                    'earnings_id' => $earning->id,
                    'amount_type' => $request->amount_type,
                    'amount' => $amount,
                    'percentage' => $percentage,
                    'recurring' => $recurring,
                ]);
            }
        });

        $assignedCount = count($toAssign);
        $skippedCount = count($alreadyAssignedIds);

        if ($assignedCount === 0) {
            $message = $skippedCount > 0
                ? "No new assignments made - all {$skippedCount} selected employee(s) already had this earning."
                : 'No eligible employees were selected.';

            return redirect()->route('payroll.earnings.show', $earningId)->with('error', $message);
        }

        $message = "Assigned to {$assignedCount} employee(s).";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} already had this earning and were skipped.";
        }

        return redirect()->route('payroll.earnings.show', $earningId)->with('status', $message);
    }

    public function update(Request $request, int $earningId, int $id): RedirectResponse
    {
        $assignment = EmployeeEarning::where('earnings_id', $earningId)->findOrFail($id);

        $request->validate([
            'amount_type' => 'required|in:fixed,percentage',
            'amount' => 'required_if:amount_type,fixed|nullable|numeric|min:0',
            'percentage' => 'required_if:amount_type,percentage|nullable|numeric|between:0,100',
            'recurring' => 'boolean',
        ]);

        $assignment->update([
            'amount_type' => $request->amount_type,
            'amount' => $request->amount_type === 'fixed' ? $request->amount : 0,
            'percentage' => $request->amount_type === 'percentage' ? $request->percentage : null,
            'recurring' => $request->boolean('recurring'),
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
