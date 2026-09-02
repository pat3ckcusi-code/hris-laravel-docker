<?php

namespace App\Exports;

use App\Models\DtrExemptionPeriod;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DtrExemptionListExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    /**
     * @param  Collection<int, DtrExemptionPeriod>  $periods
     */
    public function __construct(private readonly Collection $periods) {}

    public function collection(): Collection
    {
        return $this->periods;
    }

    public function headings(): array
    {
        return ['Name', 'EmpNo', 'Department', 'Position', 'Effective Date', 'Date Until', 'Reason'];
    }

    public function map($period): array
    {
        return [
            trim("{$period->user->last_name}, {$period->user->first_name}"),
            $period->user->EmpNo,
            $period->user->department?->Dept_name ?? '',
            $period->user->designation ?? '',
            $period->effective_date?->format('M d, Y') ?? '',
            $period->until_date?->format('M d, Y') ?? '',
            $period->reason ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
