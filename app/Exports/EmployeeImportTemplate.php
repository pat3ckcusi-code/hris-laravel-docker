<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class EmployeeImportTemplate implements FromArray, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return [
            'EmpNo',
            'Last Name',
            'First Name',
            'Middle Name',
            'Email',
            'Designation',
            'Department',
            'Date Hired',
            'Employee Type',
            'Access Level',
        ];
    }

    public function array(): array
    {
        return [
            [
                '2600001',
                'DELA CRUZ',
                'JUAN',
                'SANTOS',
                'juan.delacruz@lgu.gov.ph',
                'Administrative Aide',
                'OFFICE OF THE MAYOR',
                '2026-01-15',
                'Permanent',
                'employee',
            ],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
