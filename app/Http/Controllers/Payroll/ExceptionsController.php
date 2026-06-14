<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollException;
use App\Models\PayrollRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ExceptionsController extends Controller
{
    public function index(Request $request): View
    {
        $query = PayrollException::with('payrollRun')->latest();

        if ($request->filled('resolved')) {
            $query->where('resolved_flag', $request->boolean('resolved'));
        }

        $exceptions = $query->paginate(20);
        $runs = PayrollRun::orderByDesc('id')->get();

        return view('payroll.exceptions', compact('exceptions', 'runs'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.exceptions.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'payroll_run_id' => 'required|exists:payroll_runs,id',
            'type' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
        ]);

        PayrollException::create($request->only('payroll_run_id', 'type', 'description'));

        return redirect()->route('payroll.exceptions.index')
            ->with('status', 'Exception logged.');
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.exceptions.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.exceptions.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->validate([
            'type' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'resolved_flag' => 'boolean',
        ]);

        PayrollException::findOrFail($id)->update($request->only('type', 'description', 'resolved_flag'));

        return redirect()->route('payroll.exceptions.index')
            ->with('status', 'Exception updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        PayrollException::findOrFail($id)->delete();

        return redirect()->route('payroll.exceptions.index')
            ->with('status', 'Exception deleted.');
    }
}
