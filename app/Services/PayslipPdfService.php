<?php

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\Payslip;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class PayslipPdfService
{
    public function download(Payslip $payslip): Response
    {
        $employee = $payslip->employee;
        $run = $payslip->payrollRun;

        $assignment = EmployeeAssignment::where('employee_id', $employee->id)
            ->current($run->period_end?->toDateString())
            ->with('plantilla')
            ->first();

        $position = $assignment?->plantilla?->title;
        $department = $employee->department?->Dept_name;

        $settings = Setting::first();

        $preparedByName = $settings?->payroll_preparer_name;
        $preparedByDesignation = $settings?->payroll_preparer_designation;
        $certifiedByName = $settings?->hr_manager_name;
        $certifiedByDesignation = $settings?->hr_manager_designation ?? 'OIC-CHRMD';
        $orgName = $settings?->org_name ?? 'City Government of Calapan';

        $filename = 'Payslip-'.($employee->EmpNo ?: $employee->id).'-'.str_replace(' ', '-', (string) $run->period).'.pdf';

        return Pdf::loadView('payroll.payslip-pdf', [
            'payslip' => $payslip,
            'employee' => $employee,
            'run' => $run,
            'position' => $position,
            'department' => $department,
            'orgName' => $orgName,
            'preparedByName' => $preparedByName,
            'preparedByDesignation' => $preparedByDesignation,
            'certifiedByName' => $certifiedByName,
            'certifiedByDesignation' => $certifiedByDesignation,
        ])->setPaper('letter', 'portrait')->download($filename);
    }
}
