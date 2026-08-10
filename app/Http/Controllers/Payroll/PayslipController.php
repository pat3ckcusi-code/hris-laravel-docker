<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\PayslipExcelExportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PayslipController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $departmentId = $request->query('department_id', '');

        $query = Payslip::with('employee.department', 'payrollRun')
            ->when($search !== '', function ($q) use ($search) {
                $q->whereHas('employee', function ($eq) use ($search) {
                    $eq->where('name', 'like', "%{$search}%")
                        ->orWhere('EmpNo', 'like', "%{$search}%");
                });
            })
            ->when($departmentId !== '', function ($q) use ($departmentId) {
                $q->whereHas('employee', fn ($eq) => $eq->where('Dept_id', $departmentId));
            })
            ->when($request->filled('payroll_run_id'), function ($q) use ($request) {
                $q->where('payroll_run_id', $request->payroll_run_id);
            })
            ->latest();

        $stats = [
            'total_payslips' => (clone $query)->count(),
            'total_net_pay' => (clone $query)->get()->sum('net_pay'),
            'employees_covered' => (clone $query)->distinct('employee_id')->count('employee_id'),
        ];

        $payslips = $query->paginate(20)->withQueryString();
        $runs = PayrollRun::orderByDesc('id')->get();
        $lockedRuns = PayrollRun::where('status', 'locked')->get();
        $departments = Department::orderBy('Dept_name')->get(['Dept_id', 'Dept_name']);

        return view('payroll.payslips', compact(
            'payslips', 'runs', 'lockedRuns', 'departments', 'stats', 'search', 'departmentId'
        ));
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

    public function downloadExcel(int $id, PayslipExcelExportService $excelService): Response
    {
        $payslip = Payslip::with('employee.department', 'payrollRun')->findOrFail($id);

        return $excelService->download($payslip);
    }
}
