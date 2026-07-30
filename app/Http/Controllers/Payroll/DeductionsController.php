<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Deduction;
use App\Models\User;
use App\Models\WithholdingTax;
use App\Support\HrisConstants;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeductionsController extends Controller
{
    public function index(): View
    {
        $catalogQuery = fn () => Deduction::where(function ($q) {
            $q->whereNull('deduction_category')->orWhere('deduction_category', '!=', 'loan');
        });

        $stats = [
            'total' => $catalogQuery()->count(),
            'mandatory' => $catalogQuery()->whereNotNull('mandatory_key')->count(),
            'other' => $catalogQuery()->where('deduction_category', 'other')->count(),
            'active' => $catalogQuery()->where('is_active', true)->count(),
        ];

        $deductionTypes = $catalogQuery()->withCount('employeeDeductions')->paginate(20);

        return view('payroll.deductions', compact('deductionTypes', 'stats'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.contributions.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'type' => 'required|string|max:150',
            'deduction_category' => 'nullable|in:loan,other',
            'deduction_type' => 'nullable|string|max:100',
            'provider' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'formula' => 'nullable|string|max:500',
        ]);

        Deduction::create($request->only('type', 'deduction_category', 'deduction_type', 'provider', 'description', 'formula'));

        return redirect($request->input('_redirect', route('payroll.contributions.index')))
            ->with('status', 'Deduction type created.');
    }

    public function show(Request $request, int $id): View
    {
        $deduction = Deduction::with('employeeDeductions.employee', 'loans.employee', 'loans.billingHistory', 'loans.payrollDeductions.payrollRun')->findOrFail($id);

        $employees = User::active()->orderBy('name')->get(['id', 'name', 'EmpNo', 'employee_type']);

        $assignedEmployeeIds = $deduction->employeeDeductions->pluck('employee_id')->all();
        $loanedEmployeeIds = $deduction->loans->pluck('employee_id')->all();

        $employeeTypes = HrisConstants::EMPLOYEE_TYPES;

        // The Withholding Tax Table lives directly on the BIR row's own show
        // page rather than a separate page - see "Replace computed BIR
        // withholding tax with an Accounting-uploaded monthly table".
        $withholdingYears = collect();
        $withholdingSelectedYear = null;
        $withholdingSearch = '';
        $withholdingType = '';
        $withholdingEmployees = collect();
        $withholdingEntries = collect();

        if ($deduction->isWithholdingTax()) {
            $withholdingYears = WithholdingTax::select('year')->distinct()->orderByDesc('year')->pluck('year');

            $validated = $request->validate([
                'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
                'search' => ['nullable', 'string', 'max:255'],
                'type' => ['nullable', Rule::in($employeeTypes)],
            ]);
            $withholdingSelectedYear = $validated['year'] ?? $withholdingYears->first() ?? (int) now()->year;
            $withholdingSearch = trim((string) ($validated['search'] ?? ''));
            $withholdingType = (string) ($validated['type'] ?? '');

            $withholdingEmployees = User::active()
                ->when($withholdingSearch !== '', function ($q) use ($withholdingSearch) {
                    $q->where(function ($sq) use ($withholdingSearch) {
                        $sq->where('name', 'like', "%{$withholdingSearch}%")->orWhere('EmpNo', 'like', "%{$withholdingSearch}%");
                    });
                })
                ->when($withholdingType !== '', fn ($q) => $q->where('employee_type', $withholdingType))
                ->orderBy('name')
                ->paginate(20, ['id', 'name', 'EmpNo', 'employee_type'], 'wt_page')
                ->withQueryString();

            $withholdingEntries = WithholdingTax::where('year', $withholdingSelectedYear)->get()->groupBy('employee_id');
        }

        return view('payroll.deduction-show', compact(
            'deduction', 'employees', 'assignedEmployeeIds', 'loanedEmployeeIds', 'employeeTypes',
            'withholdingYears', 'withholdingSelectedYear', 'withholdingSearch', 'withholdingType', 'withholdingEmployees', 'withholdingEntries'
        ));
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.contributions.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $deduction = Deduction::findOrFail($id);

        $request->validate([
            'type' => 'required|string|max:150',
            'deduction_category' => 'nullable|in:loan,other',
            'deduction_type' => 'nullable|string|max:100',
            'provider' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'formula' => 'nullable|string|max:500',
        ]);

        $data = $request->only('type', 'deduction_type', 'provider', 'description', 'formula');

        // A system mandatory row's category is structural (tied to
        // mandatory_key) and never re-editable via this form - the edit
        // modal doesn't submit deduction_category for these rows at all.
        if ($deduction->mandatory_key === null) {
            $data['deduction_category'] = $request->input('deduction_category');
        }

        $deduction->update($data);

        return redirect($request->input('_redirect', route('payroll.contributions.index')))
            ->with('status', 'Deduction type updated.');
    }

    /**
     * Update the computation type + rate/amount config for one of the 4
     * system mandatory deduction rows (gsis/philhealth/pagibig/bir), or for
     * an "other" category row switching between Individually Assigned and a
     * Standing Rate - see "Let 'Other' deduction types use a standing
     * per-type rate, like Mandatory". The submitted computation_type decides
     * what shape is validated/saved - independent of which program the row
     * represents, so any of them can switch formula shape entirely (e.g.
     * Pag-IBIG moving from flat to percentage) with no code change. This is
     * the single real source of truth PayrollComputationService reads from -
     * see computation_type/mandatory_config on the Deduction model.
     */
    public function updateMandatoryConfig(Request $request, int $id): RedirectResponse
    {
        $deduction = Deduction::findOrFail($id);

        abort_if($deduction->isWithholdingTax(), 422, 'Withholding tax is no longer configured here - see the Withholding Tax Table.');
        abort_if(! $deduction->supportsRateConfiguration(), 422, 'This deduction type has no rate configuration.');

        // The 4 system mandatory rows must always be auto-computed - only an
        // "other" row can choose "individual" to go back to per-employee
        // EmployeeDeduction assignment.
        $allowedTypes = $deduction->mandatory_key !== null
            ? ['flat', 'percentage', 'bracket']
            : ['flat', 'percentage', 'bracket', 'individual'];

        $request->validate(['computation_type' => ['required', Rule::in($allowedTypes)]]);
        $computationType = $request->input('computation_type');

        if ($computationType === 'individual') {
            $deduction->update(['computation_type' => null, 'mandatory_config' => null]);

            return redirect()->route('payroll.contributions.show', $deduction->id)
                ->with('status', 'Switched back to Individually Assigned - existing per-employee assignments resume taking effect, and any standing rate is cleared.');
        }

        $config = match ($computationType) {
            'flat' => $this->validateFlatConfig($request),
            'percentage' => $this->validatePercentageConfig($request),
            'bracket' => $this->validateBracketConfig($request),
        };

        $deduction->update([
            'computation_type' => $computationType,
            'mandatory_config' => $config,
        ]);

        return redirect()->route('payroll.contributions.show', $deduction->id)
            ->with('status', 'Rate configuration updated.');
    }

    private function validateFlatConfig(Request $request): array
    {
        $validated = $request->validate(['amount' => 'required|numeric|min:0']);

        return ['amount' => (float) $validated['amount']];
    }

    private function validatePercentageConfig(Request $request): array
    {
        $validated = $request->validate([
            'rate_percent' => 'required|numeric|min:0|max:100',
            'floor' => 'nullable|numeric|min:0',
            'ceiling' => 'nullable|numeric|min:0',
        ]);

        return [
            'rate' => $validated['rate_percent'] / 100,
            'floor' => isset($validated['floor']) ? (float) $validated['floor'] : null,
            'ceiling' => isset($validated['ceiling']) ? (float) $validated['ceiling'] : null,
        ];
    }

    private function validateBracketConfig(Request $request): array
    {
        $validated = $request->validate([
            'brackets' => 'required|array|min:1',
            'brackets.*.min' => 'required|numeric|min:0',
            'brackets.*.max' => 'nullable|numeric|min:0',
            'brackets.*.base' => 'required|numeric|min:0',
            'brackets.*.rate_percent' => 'required|numeric|min:0',
        ]);

        $brackets = collect($validated['brackets'])
            ->map(fn ($b) => [
                'min' => (float) $b['min'],
                'max' => isset($b['max']) ? (float) $b['max'] : null,
                'base' => (float) $b['base'],
                'rate' => $b['rate_percent'] / 100,
            ])
            ->sortBy('min')
            ->values()
            ->all();

        return ['brackets' => $brackets];
    }

    /**
     * Update which HrisConstants::EMPLOYEE_TYPES a mandatory row applies to
     * (e.g. GSIS excluding "Job Orders", who aren't civil-service/GSIS
     * members). An excluded employee's computed amount for that program is
     * ₱0 and it's omitted from their payslip breakdown - see
     * PayrollComputationService::mandatoryAppliesToEmployee(). Selecting
     * every type canonicalizes to null (no restriction, same as never
     * having restricted it) rather than storing a redundant full list.
     */
    public function updateEligibility(Request $request, int $id): RedirectResponse
    {
        $deduction = Deduction::findOrFail($id);

        abort_if($deduction->isWithholdingTax(), 422, 'Withholding tax is no longer configured here - see the Withholding Tax Table.');
        abort_if(! $deduction->isAutoComputed(), 422, 'Employee type eligibility only applies to mandatory or standing-rate deduction types.');

        $request->validate([
            'employee_types' => 'required|array|min:1',
            'employee_types.*' => [Rule::in(HrisConstants::EMPLOYEE_TYPES)],
        ]);

        $selected = $request->input('employee_types');
        $coversEveryType = count(array_diff(HrisConstants::EMPLOYEE_TYPES, $selected)) === 0;

        $deduction->update([
            'eligible_employee_types' => $coversEveryType ? null : array_values($selected),
        ]);

        return redirect()->route('payroll.contributions.show', $deduction->id)
            ->with('status', 'Employee type eligibility updated.');
    }

    /**
     * Toggle any deduction type in or out of active use. For Loan/Other, an
     * inactive type is hidden from new-assignment ("Assign Loan"/"Assign
     * Employee(s)") but existing Loan/EmployeeDeduction rows under it are
     * untouched and keep computing every payroll run as normal. For a system
     * mandatory row (GSIS/PhilHealth/Pag-IBIG/BIR), there is no per-employee
     * assignment row to fall back on - deactivating stops that deduction
     * from being withheld from EVERY employee, immediately, on the next
     * payroll run - see "Extend the Active/Inactive toggle to mandatory
     * deduction rows" and PayrollComputationService::computeMandatoryAmount().
     * Deletion remains permanently blocked for mandatory rows regardless of
     * this flag - see destroy() below.
     */
    public function toggleActive(Request $request, int $id): RedirectResponse
    {
        $deduction = Deduction::findOrFail($id);

        $deduction->update(['is_active' => ! $deduction->is_active]);

        if ($deduction->is_active) {
            $message = "{$deduction->type} is now active.";
        } elseif ($deduction->mandatory_key !== null) {
            $message = "{$deduction->type} is now inactive - it will not be withheld from any employee's pay on any future payroll run.";
        } else {
            $message = "{$deduction->type} is now inactive - no new employees can be assigned to it, but existing assignments keep being deducted.";
        }

        return redirect($request->input('_redirect', route('payroll.contributions.index')))->with('status', $message);
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $deduction = Deduction::findOrFail($id);

        if ($deduction->mandatory_key !== null) {
            return redirect($request->input('_redirect', route('payroll.contributions.index')))
                ->with('error', 'Cannot delete a system mandatory deduction type.');
        }

        if ($deduction->employeeDeductions()->exists() || $deduction->loans()->exists()) {
            return redirect($request->input('_redirect', route('payroll.contributions.index')))
                ->with('error', 'Cannot delete this deduction type while employees are still assigned to it. Remove those assignments first.');
        }

        $deduction->delete();

        return redirect($request->input('_redirect', route('payroll.contributions.index')))
            ->with('status', 'Deduction type deleted.');
    }
}
