<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Deduction;
use App\Models\EmployeeDeduction;
use App\Models\Loan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeductionsController extends Controller
{
    public function index(): View
    {
        $deductionTypes = Deduction::withCount('employeeDeductions', 'loans')->paginate(20);
        return view('payroll.deductions', compact('deductionTypes'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.deductions.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'type' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'formula' => 'nullable|string|max:500',
        ]);

        Deduction::create($request->only('type', 'description', 'formula'));

        return redirect()->route('payroll.deductions.index')
            ->with('status', 'Deduction type created.');
    }

    public function show(int $id): View
    {
        $deduction = Deduction::with('employeeDeductions.employee', 'loans.employee')->findOrFail($id);
        return view('payroll.deduction-show', compact('deduction'));
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.deductions.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'type' => 'required|string|max:150',
            'description' => 'nullable|string|max:500',
            'formula' => 'nullable|string|max:500',
        ]);

        Deduction::findOrFail($id)->update($request->only('type', 'description', 'formula'));

        return redirect()->route('payroll.deductions.index')
            ->with('status', 'Deduction type updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        Deduction::findOrFail($id)->delete();
        return redirect()->route('payroll.deductions.index')
            ->with('status', 'Deduction type deleted.');
    }
}
