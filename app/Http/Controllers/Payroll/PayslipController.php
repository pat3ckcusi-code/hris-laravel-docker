<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PayslipController extends Controller
{
    public function index(Request $request): View
    {
        $query = Payslip::with('employee', 'payrollRun')->latest();

        if ($request->filled('payroll_run_id')) {
            $query->where('payroll_run_id', $request->payroll_run_id);
        }

        $payslips = $query->paginate(20);
        $runs = PayrollRun::orderByDesc('id')->get();
        $approvedRuns = PayrollRun::where('status', 'approved')->get();

        return view('payroll.payslips', compact('payslips', 'runs', 'approvedRuns'));
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('payroll.payslips.index');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'payroll_run_id' => 'required|exists:payroll_runs,id',
        ]);

        // Placeholder: generate payslips for all employees in the run
        $run = PayrollRun::with('details')->findOrFail($request->payroll_run_id);

        foreach ($run->details as $detail) {
            Payslip::firstOrCreate([
                'employee_id' => $detail->employee_id,
                'payroll_run_id' => $run->id,
            ]);
        }

        return redirect()->route('payroll.payslips.index')
            ->with('status', 'Payslips generated.');
    }

    public function show(int $id): RedirectResponse
    {
        return redirect()->route('payroll.payslips.index');
    }

    public function edit(int $id): RedirectResponse
    {
        return redirect()->route('payroll.payslips.index');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        return redirect()->route('payroll.payslips.index');
    }

    public function destroy(int $id): RedirectResponse
    {
        Payslip::findOrFail($id)->delete();

        return redirect()->route('payroll.payslips.index')
            ->with('status', 'Payslip record deleted.');
    }
}
