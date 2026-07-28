<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Services\PayslipPdfService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EmployeePayslipController extends Controller
{
    public function index(Request $request): View
    {
        $payslips = Payslip::with('payrollRun')
            ->where('employee_id', $request->user()->id)
            ->latest()
            ->paginate(20);

        return view('employee.payslips', compact('payslips'));
    }

    public function download(Request $request, int $id, PayslipPdfService $pdfService): Response
    {
        $payslip = Payslip::with('employee', 'payrollRun')
            ->where('employee_id', $request->user()->id)
            ->findOrFail($id);

        return $pdfService->download($payslip);
    }
}
