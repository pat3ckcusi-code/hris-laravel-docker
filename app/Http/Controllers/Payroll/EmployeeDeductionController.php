<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Deduction;
use App\Models\EmployeeDeduction;
use App\Models\User;
use App\Support\HrisConstants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeDeductionController extends Controller
{
    public function store(Request $request, int $deductionId): RedirectResponse
    {
        $deduction = Deduction::findOrFail($deductionId);

        if (! $deduction->is_active) {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'This deduction type is inactive. Reactivate it before assigning new employees.');
        }

        if ($deduction->isAutoComputed()) {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'This deduction uses a standing rate now — assign employee types instead of individual employees.');
        }

        $request->validate([
            'employee_ids' => 'required|array|min:1',
            'employee_ids.*' => 'integer|distinct|exists:users,id',
            'amount' => 'required|numeric|min:0',
            'recurring' => 'boolean',
        ]);

        $submittedIds = array_unique(array_map('intval', $request->input('employee_ids', [])));

        $eligibleIds = User::active()->whereIn('id', $submittedIds)->pluck('id')->all();

        $alreadyAssignedIds = EmployeeDeduction::where('deduction_id', $deduction->id)
            ->whereIn('employee_id', $eligibleIds)
            ->pluck('employee_id')
            ->all();

        $toAssign = array_values(array_diff($eligibleIds, $alreadyAssignedIds));

        $amount = $request->amount;
        $recurring = $request->boolean('recurring');

        DB::transaction(function () use ($toAssign, $deduction, $amount, $recurring) {
            foreach ($toAssign as $employeeId) {
                EmployeeDeduction::create([
                    'employee_id' => $employeeId,
                    'deduction_id' => $deduction->id,
                    'amount' => $amount,
                    'recurring' => $recurring,
                ]);
            }
        });

        $assignedCount = count($toAssign);
        $skippedCount = count($alreadyAssignedIds);

        if ($assignedCount === 0) {
            $message = $skippedCount > 0
                ? "No new assignments made — all {$skippedCount} selected employee(s) already had this deduction."
                : 'No eligible employees were selected.';

            return redirect()->route('payroll.contributions.show', $deductionId)->with('error', $message);
        }

        $message = "Assigned to {$assignedCount} employee(s).";
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} already had this deduction and were skipped.";
        }

        return redirect()->route('payroll.contributions.show', $deductionId)->with('status', $message);
    }

    /**
     * Quick-assign shortcut mirroring the Mandatory rows' "Assign Employee
     * Types" modal - instead of picking individuals out of a long employee
     * list, pick one or more employee_type categories and every active
     * employee in them gets the same amount. Already-assigned employees are
     * skipped (their existing amount is left untouched) exactly like store().
     */
    public function bulkAssignByType(Request $request, int $deductionId): RedirectResponse
    {
        $deduction = Deduction::findOrFail($deductionId);

        if ($deduction->deduction_category !== 'other') {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'Assigning by employee type only applies to "Other" recurring deduction types.');
        }

        if (! $deduction->is_active) {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'This deduction type is inactive. Reactivate it before assigning new employees.');
        }

        if ($deduction->isAutoComputed()) {
            return redirect()->route('payroll.contributions.show', $deductionId)
                ->with('error', 'This deduction uses a standing rate now — assign employee types instead of individual employees.');
        }

        $request->validate([
            'employee_types' => 'required|array|min:1',
            'employee_types.*' => [Rule::in(HrisConstants::EMPLOYEE_TYPES)],
            'amount' => 'required|numeric|min:0',
            'recurring' => 'boolean',
        ]);

        $eligibleIds = User::active()->whereIn('employee_type', $request->input('employee_types'))->pluck('id')->all();

        $alreadyAssignedIds = EmployeeDeduction::where('deduction_id', $deduction->id)
            ->whereIn('employee_id', $eligibleIds)
            ->pluck('employee_id')
            ->all();

        $toAssign = array_values(array_diff($eligibleIds, $alreadyAssignedIds));

        $amount = $request->amount;
        $recurring = $request->boolean('recurring');

        DB::transaction(function () use ($toAssign, $deduction, $amount, $recurring) {
            foreach ($toAssign as $employeeId) {
                EmployeeDeduction::create([
                    'employee_id' => $employeeId,
                    'deduction_id' => $deduction->id,
                    'amount' => $amount,
                    'recurring' => $recurring,
                ]);
            }
        });

        $assignedCount = count($toAssign);
        $skippedCount = count($alreadyAssignedIds);

        if ($assignedCount === 0) {
            $message = $skippedCount > 0
                ? "No new assignments made — all {$skippedCount} employee(s) of the selected type(s) already had this deduction."
                : 'No active employees found for the selected type(s).';

            return redirect()->route('payroll.contributions.show', $deductionId)->with('error', $message);
        }

        $message = "Assigned to {$assignedCount} employee(s) across the selected type(s), ₱".number_format($amount, 2).' each.';
        if ($skippedCount > 0) {
            $message .= " {$skippedCount} already had this deduction and were skipped.";
        }

        return redirect()->route('payroll.contributions.show', $deductionId)->with('status', $message);
    }

    public function update(Request $request, int $deductionId, int $id): RedirectResponse
    {
        $employeeDeduction = EmployeeDeduction::where('deduction_id', $deductionId)->findOrFail($id);

        $request->validate([
            'amount' => 'required|numeric|min:0',
            'recurring' => 'boolean',
        ]);

        $employeeDeduction->update([
            'amount' => $request->amount,
            'recurring' => $request->boolean('recurring'),
        ]);

        return redirect()->route('payroll.contributions.show', $deductionId)
            ->with('status', 'Deduction assignment updated.');
    }

    public function destroy(int $deductionId, int $id): RedirectResponse
    {
        EmployeeDeduction::where('deduction_id', $deductionId)->findOrFail($id)->delete();

        return redirect()->route('payroll.contributions.show', $deductionId)
            ->with('status', 'Deduction assignment removed.');
    }
}
