<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Services\PayslipExcelExportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeePayslipController extends Controller
{
    public function index(Request $request): View
    {
        $employeeId = $request->user()->id;

        $payslips = Payslip::with('payrollRun')
            ->where('employee_id', $employeeId)
            ->latest()
            ->paginate(20);

        $latestPayslip = Payslip::with('payrollRun')
            ->where('employee_id', $employeeId)
            ->latest()
            ->first();

        $currentYear = now()->year;
        $stats = [
            'total_payslips' => Payslip::where('employee_id', $employeeId)->count(),
            'ytd_net_pay' => Payslip::where('employee_id', $employeeId)
                ->whereHas('payrollRun', fn ($q) => $q->whereYear('period_end', $currentYear))
                ->get()->sum('net_pay'),
            'average_net_pay' => Payslip::where('employee_id', $employeeId)->get()->avg('net_pay') ?? 0,
        ];

        return view('employee.payslips', compact('payslips', 'latestPayslip', 'stats', 'currentYear'));
    }

    public function downloadExcel(Request $request, int $id, PayslipExcelExportService $excelService): Response
    {
        $payslip = Payslip::with('employee.department', 'payrollRun')
            ->where('employee_id', $request->user()->id)
            ->findOrFail($id);

        return $excelService->download($payslip);
    }
}
