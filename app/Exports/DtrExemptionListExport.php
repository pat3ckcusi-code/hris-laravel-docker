<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DtrExemptionListExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  Collection<int, User>  $employees
     */
    public function __construct(private readonly Collection $employees) {}

    public function collection(): Collection
    {
        return $this->employees;
    }

    public function headings(): array
    {
        return ['Name', 'EmpNo', 'Department', 'Position', 'Effective Date', 'Reason'];
    }

    public function map($employee): array
    {
        return [
            trim("{$employee->last_name}, {$employee->first_name}"),
            $employee->EmpNo,
            $employee->department?->Dept_name ?? '',
            $employee->designation ?? '',
            $employee->dtr_exempt_effective_date?->format('M d, Y') ?? '',
            $employee->dtr_exempt_reason ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
