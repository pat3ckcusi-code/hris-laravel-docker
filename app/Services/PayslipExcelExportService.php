<?php

namespace App\Services;

use App\Models\EmployeeAssignment;
use App\Models\PayrollSetting;
use App\Models\Payslip;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayslipExcelExportService
{
    private const SHEET_NAME = 'Sheet1';

    private const GRID_COLUMNS = [
        ['label' => 'A', 'amount' => 'C'],
        ['label' => 'D', 'amount' => 'F'],
        ['label' => 'G', 'amount' => 'H'],
        ['label' => 'I', 'amount' => 'J'],
    ];

    private const GRID_ROWS = [9, 10, 11, 12, 13, 14, 15];

    // The template's own peso cells are inconsistently formatted - some "#,##0.00", some
    // "0.00", plenty just "General" (no thousands separator, no fixed decimals) - since
    // whichever cells happened to get a sample value during authoring got a matching
    // format and the rest never did. Every amount cell this service writes gets this
    // format applied explicitly so the output is uniform regardless of the template's own,
    // pre-existing per-cell formatting.
    private const PESO_FORMAT = '"P "#,##0.00';

    public function download(Payslip $payslip): StreamedResponse
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
        $orgName = $settings?->org_name ?? 'City Government of Calapan';
        $certifiedByName = $settings?->hr_manager_name;
        $certifiedByDesignation = $settings?->hr_manager_designation;

        $preparedBySettings = PayrollSetting::whereIn('key', [
            'payroll_signatory_payslip_prepared_by_name',
            'payroll_signatory_payslip_prepared_by_designation',
        ])->pluck('value', 'key');
        $preparedByName = $preparedBySettings->get('payroll_signatory_payslip_prepared_by_name');
        $preparedByDesignation = $preparedBySettings->get('payroll_signatory_payslip_prepared_by_designation');

        $templatePath = storage_path('app/templates/payslip.xlsx');
        if (! file_exists($templatePath)) {
            abort(500, 'Payslip Excel template not found.');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);

        $this->fillHeader($sheet, $orgName, $run, $employee, $position, $department, $payslip);
        $this->fillDeductionGrid($sheet, $payslip);
        $this->fillAmountDueSplit($sheet, $payslip);
        $this->fillSignatories($sheet, $preparedByName, $preparedByDesignation, $certifiedByName, $certifiedByDesignation, $employee->name);

        $filename = 'Payslip-'.($employee->EmpNo ?: $employee->id).'-'.str_replace(' ', '-', (string) $run->period).'.xlsx';

        return response()->streamDownload(
            function () use ($spreadsheet): void {
                $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    private function fillHeader(Worksheet $sheet, string $orgName, $run, $employee, ?string $position, ?string $department, Payslip $payslip): void
    {
        $sheet->setCellValue('A1', $orgName);
        $sheet->setCellValue('A2', 'P A Y S L I P  For the Month of '.$run->period);
        $sheet->setCellValue('B4', $employee->name);
        $sheet->setCellValue('J4', $employee->EmpNo ?? '');
        $sheet->setCellValue('B5', $position ?? '');
        $sheet->setCellValue('B6', $department ?? '');
        $sheet->setCellValue('J7', (float) $payslip->basic_salary);
        $sheet->getStyle('J7')->getNumberFormat()->setFormatCode(self::PESO_FORMAT);
    }

    // The 28-slot grid (7 rows x 4 columns) is a generic flowing list, not a fixed set of
    // named cells - every real deduction the employee has is written in, column A first
    // then D, G, I, wrapping to the next column's row 9 once a column's 7 rows fill up.
    // deduction_breakdown never itemizes LWOP (it's a separate Payslip column), so it's
    // appended here as its own line - otherwise row 16's SUM formulas would omit it and
    // the sheet's computed Net Home Pay would overstate the real net_pay.
    private function fillDeductionGrid(Worksheet $sheet, Payslip $payslip): void
    {
        $slots = [];
        foreach (self::GRID_COLUMNS as $col) {
            foreach (self::GRID_ROWS as $row) {
                $slots[] = ["{$col['label']}{$row}", "{$col['amount']}{$row}"];
                $sheet->setCellValue("{$col['label']}{$row}", '');
                $sheet->setCellValue("{$col['amount']}{$row}", null);
                $sheet->getStyle("{$col['amount']}{$row}")->getNumberFormat()->setFormatCode(self::PESO_FORMAT);
            }
        }

        $lines = [];
        foreach ($payslip->deduction_breakdown ?? [] as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if ($amount === 0.0) {
                continue;
            }
            $label = $line['label'] ?? '';
            if (! empty($line['provider'])) {
                $label .= ' ('.$line['provider'].')';
            }
            $lines[] = [$label, $amount];
        }

        if ((float) $payslip->lwop_deduction > 0) {
            $lines[] = ['LWOP', (float) $payslip->lwop_deduction];
        }

        if (count($lines) > count($slots)) {
            Log::warning('Payslip Excel export: deduction grid truncated', [
                'payslip_id' => $payslip->id,
                'line_count' => count($lines),
                'slot_count' => count($slots),
            ]);
            $lines = array_slice($lines, 0, count($slots));
        }

        foreach ($lines as $i => [$label, $amount]) {
            [$labelCell, $amountCell] = $slots[$i];
            $sheet->setCellValue($labelCell, $label);
            $sheet->setCellValue($amountCell, $amount);
        }

        // C16/G16/J16 (Monthly Rate / Total Deductions / Net Home Pay) are formula-driven,
        // not written directly, but are peso values too - lock their format for the same
        // reason as the grid cells above.
        $sheet->getStyle('C16')->getNumberFormat()->setFormatCode(self::PESO_FORMAT);
        $sheet->getStyle('G16')->getNumberFormat()->setFormatCode(self::PESO_FORMAT);
        $sheet->getStyle('J16')->getNumberFormat()->setFormatCode(self::PESO_FORMAT);
    }

    private function fillAmountDueSplit(Worksheet $sheet, Payslip $payslip): void
    {
        $netPay = (float) $payslip->net_pay;
        $firstHalf = floor($netPay / 2);
        $secondHalf = $netPay - $firstHalf;

        $sheet->setCellValue('D17', $firstHalf);
        $sheet->setCellValue('I17', $secondHalf);
        $sheet->getStyle('D17')->getNumberFormat()->setFormatCode(self::PESO_FORMAT);
        $sheet->getStyle('I17')->getNumberFormat()->setFormatCode(self::PESO_FORMAT);
    }

    private function fillSignatories(Worksheet $sheet, ?string $preparedByName, ?string $preparedByDesignation, ?string $certifiedByName, ?string $certifiedByDesignation, string $employeeName): void
    {
        if (! empty($preparedByName)) {
            $sheet->setCellValue('A20', $preparedByName);
        }
        if (! empty($preparedByDesignation)) {
            $sheet->setCellValue('A21', $preparedByDesignation);
        }
        if (! empty($certifiedByName)) {
            $sheet->setCellValue('D20', $certifiedByName);
        }
        if (! empty($certifiedByDesignation)) {
            $sheet->setCellValue('D21', $certifiedByDesignation);
        }

        // The Acknowledgement slot's employee name used to come from a "=B4" formula built
        // into the template; the current template no longer has that formula, so it's set
        // directly here to keep it in sync with B4 (the employee name field above).
        $sheet->setCellValue('H20', $employeeName);
    }
}
