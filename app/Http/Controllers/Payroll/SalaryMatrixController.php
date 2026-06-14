<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\SalaryMatrix;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SalaryMatrixController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'digits:4', 'between:2000,2099'],
        ]);
        $year = $validated['year'] ?? (int) date('Y');

        $matrix = SalaryMatrix::where('year', $year)
            ->orderBy('sg')
            ->orderBy('step')
            ->get()
            ->groupBy('sg');

        $years = SalaryMatrix::select('year')->distinct()->orderByDesc('year')->pluck('year');

        return view('payroll.salary-matrix', compact('matrix', 'year', 'years'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.salary-matrix.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'sg' => 'required|integer|min:1|max:33',
            'step' => 'required|integer|min:1|max:8',
            'year' => 'required|digits:4',
            'amount' => 'required|numeric|min:0',
        ]);

        SalaryMatrix::updateOrCreate(
            $request->only('sg', 'step', 'year'),
            ['amount' => $request->amount],
        );

        return redirect()->route('payroll.salary-matrix.index')
            ->with('status', 'Salary matrix entry saved.');
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.salary-matrix.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.salary-matrix.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        SalaryMatrix::findOrFail($id)->update(['amount' => $request->amount]);

        return redirect()->route('payroll.salary-matrix.index')
            ->with('status', 'Salary matrix entry updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        SalaryMatrix::findOrFail($id)->delete();

        return redirect()->route('payroll.salary-matrix.index')
            ->with('status', 'Salary matrix entry deleted.');
    }
}
