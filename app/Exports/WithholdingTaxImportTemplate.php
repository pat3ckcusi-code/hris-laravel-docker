<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Pre-populated with every active employee's Employee Agency Number/Name and,
 * for the requested year, whatever monthly withholding tax figures are
 * already uploaded (blank for a month not yet uploaded) - so re-uploading a
 * correction only requires changing the month(s) that actually changed. See
 * "Replace computed BIR withholding tax with an Accounting-uploaded monthly
 * table" and LoanBillingImportTemplate, which this mirrors.
 */
class WithholdingTaxImportTemplate implements FromArray, ShouldAutoSize, WithStyles
{
    private const MONTH_LABELS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    public function __construct(
        private int $year,
        private Collection $employees,
        private Collection $existingByEmployee,
    ) {}

    public function array(): array
    {
        $header = ['Employee Agency Number', 'Name', ...self::MONTH_LABELS];

        $out = [
            ["Withholding Tax: {$this->year}"],
            $header,
        ];

        foreach ($this->employees as $employee) {
            $amountsByMonth = $this->existingByEmployee->get($employee->id, collect())->keyBy('month');

            $row = [$employee->EmpNo, $employee->name];
            for ($month = 1; $month <= 12; $month++) {
                $row[] = $amountsByMonth->get($month)?->amount;
            }

            $out[] = $row;
        }

        return $out;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->mergeCells('A1:N1');

        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            2 => ['font' => ['bold' => true]],
        ];
    }
}
