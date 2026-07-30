<?php

namespace App\Exports;

use App\Models\Deduction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Pre-populated with this provider's current active loan roster (Employee
 * Agency Number/Name/Department already filled in) so the encoder only has
 * to type Monthly Payment/Balance next to the right name, instead of
 * blind-typing every Employee Agency Number from a paper billing list. The
 * billing month is printed as a title row above the headers so a saved copy
 * stays self-identifying even once detached from the browser session it was
 * downloaded in. See "Billing template: pre-fill Name/Department, print the
 * billing month in the file".
 */
class LoanBillingImportTemplate implements FromArray, ShouldAutoSize, WithStyles
{
    public function __construct(
        private Deduction $deduction,
        private string $billingMonth,
        private Collection $employees,
    ) {}

    public function array(): array
    {
        $monthLabel = Carbon::createFromFormat('Y-m', $this->billingMonth)->format('F Y');

        $out = [
            ["Billing Month: {$monthLabel} - {$this->deduction->type}"],
            ['Employee Agency Number', 'Name', 'Department', 'Monthly Payment', 'Balance'],
        ];

        foreach ($this->employees as $employee) {
            $out[] = [$employee->EmpNo, $employee->name, $employee->department?->Dept_name ?? 'N/A', '', ''];
        }

        for ($i = 0; $i < 3; $i++) {
            $out[] = ['', '', '', '', '']; // room for a borrower new to this provider
        }

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->mergeCells('A1:E1');

        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            2 => ['font' => ['bold' => true]],
        ];
    }
}
