<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayslipPdfService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $lockedRuns = PayrollRun::where('status', 'locked')->get();

        return view('payroll.payslips', compact('payslips', 'runs', 'lockedRuns'));
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

        $run = PayrollRun::with('details')->findOrFail($request->payroll_run_id);

        if ($run->status !== 'locked') {
            return redirect()->route('payroll.payslips.index')
                ->with('error', 'Payslips can only be generated for a locked payroll run.');
        }

        $generated = 0;

        foreach ($run->details as $detail) {
            $mandatoryDeductions = $detail->deductions;
            $totalDeductions = $mandatoryDeductions + $detail->loan_deduction + $detail->other_deductions + $detail->lwop_deduction;

            $payslip = Payslip::firstOrCreate(
                [
                    'employee_id' => $detail->employee_id,
                    'payroll_run_id' => $run->id,
                ],
                [
                    'basic_salary' => $detail->basic_salary,
                    'gross_pay' => $detail->gross_pay,
                    'mandatory_deductions' => $mandatoryDeductions,
                    'loan_deduction' => $detail->loan_deduction,
                    'other_deductions' => $detail->other_deductions,
                    'lwop_deduction' => $detail->lwop_deduction,
                    'total_deductions' => $totalDeductions,
                    'net_pay' => $detail->net_pay,
                    'deduction_breakdown' => $detail->deduction_breakdown,
                ]
            );

            if ($payslip->wasRecentlyCreated) {
                $generated++;
            }
        }

        return redirect()->route('payroll.payslips.index')
            ->with('status', "Payslips generated for {$generated} employee(s).");
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

    public function download(int $id, PayslipPdfService $pdfService): Response
    {
        $payslip = Payslip::with('employee', 'payrollRun')->findOrFail($id);

        return $pdfService->download($payslip);
    }
}
