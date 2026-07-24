<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TravelOrderExportService
{
    private const SHEET_NAME = 'to';

    private const EMPLOYEE_START_ROW = 11;

    private const BUILT_IN_EMPLOYEE_ROWS = 4;

    private const LAST_BUILT_IN_EMPLOYEE_ROW = 14;

    private const INSERT_BEFORE_ROW = 15;

    private const SPACER_ROWS_BEFORE_DEPARTURE = 1;

    public function download(object $order, Collection $employees, ?string $deptOffice): StreamedResponse
    {
        $templatePath = storage_path('app/templates/Travel Order.xlsx');
        if (! file_exists($templatePath)) {
            abort(500, 'Travel Order Excel template not found.');
        }

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);

        // The template file also carries several hidden sheets with a real, already-filled
        // sample DV/OBR for an unrelated past travel order — strip them so a generated
        // download only ever contains this order's own data.
        foreach ($spreadsheet->getSheetNames() as $name) {
            if ($name !== self::SHEET_NAME) {
                $spreadsheet->removeSheetByIndex($spreadsheet->getIndex($spreadsheet->getSheetByName($name)));
            }
        }
        $spreadsheet->setActiveSheetIndex(0);

        $offset = $this->makeRoomForEmployees($sheet, $employees->count());
        $offset += $this->addSpacingBeforeDeparture($sheet, $offset);

        $this->fillHeader($sheet, $order, $deptOffice);
        $this->fillEmployees($sheet, $employees);
        $this->fillBody($sheet, $order, $offset);
        $this->fillPerDiemAndAppropriation($sheet, $order, $offset);

        $filename = 'TravelOrder-'.($order->travel_order_num ?? $order->id).'.xlsx';

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

    // Inserts extra Name/Position rows beyond the 4 built into the template, cloning row 14's
    // style/merge pattern onto each. Returns how many rows everything below row 14 shifted by.
    private function makeRoomForEmployees(Worksheet $sheet, int $employeeCount): int
    {
        $extra = max(0, $employeeCount - self::BUILT_IN_EMPLOYEE_ROWS);
        if ($extra === 0) {
            return 0;
        }

        $sheet->insertNewRowBefore(self::INSERT_BEFORE_ROW, $extra);

        for ($i = 0; $i < $extra; $i++) {
            $newRow = self::LAST_BUILT_IN_EMPLOYEE_ROW + 1 + $i;
            foreach (range('A', 'G') as $col) {
                $sheet->duplicateStyle($sheet->getStyle("{$col}".self::LAST_BUILT_IN_EMPLOYEE_ROW), "{$col}{$newRow}");
            }
            $sheet->getRowDimension($newRow)->setRowHeight(
                $sheet->getRowDimension(self::LAST_BUILT_IN_EMPLOYEE_ROW)->getRowHeight()
            );
            $sheet->mergeCells("B{$newRow}:C{$newRow}");
            $sheet->mergeCells("F{$newRow}:G{$newRow}");
        }

        return $extra;
    }

    // Inserts blank spacer row(s) right before "DATE OF DEPARTURE" (wherever it currently
    // sits after any employee-row insertion), for visual breathing room. Returns how many
    // rows were added, to be folded into the running offset.
    private function addSpacingBeforeDeparture(Worksheet $sheet, int $currentOffset): int
    {
        $sheet->insertNewRowBefore(self::INSERT_BEFORE_ROW + $currentOffset, self::SPACER_ROWS_BEFORE_DEPARTURE);

        return self::SPACER_ROWS_BEFORE_DEPARTURE;
    }

    private function fillHeader(Worksheet $sheet, object $order, ?string $deptOffice): void
    {
        $filedDate = $order->created_at ? Carbon::parse($order->created_at)->format('M j, Y') : '';
        $sheet->setCellValue('B8', $filedDate);
        $sheet->setCellValue('F10', $deptOffice ?? '');

        // Long department names wrap instead of overflowing/clipping.
        $sheet->getStyle('F10:G10')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(10)->setRowHeight(25);
    }

    private function fillEmployees(Worksheet $sheet, Collection $employees): void
    {
        $row = self::EMPLOYEE_START_ROW;
        foreach ($employees as $employee) {
            $sheet->setCellValue("B{$row}", $employee['name'] ?? '');
            $sheet->setCellValue("F{$row}", $employee['designation'] ?? '');
            $row++;
        }
    }

    private function fillBody(Worksheet $sheet, object $order, int $offset): void
    {
        $departure = $order->start_date ? Carbon::parse($order->start_date)->format('M j, Y') : '';
        $return = $order->end_date ? Carbon::parse($order->end_date)->format('M j, Y') : '';
        $lastTravel = $order->date_of_last_travel ? Carbon::parse($order->date_of_last_travel)->format('M j, Y') : '';

        $sheet->setCellValue('B'.(15 + $offset), $departure);
        $sheet->setCellValue('B'.(17 + $offset), $return);
        $sheet->setCellValue('B'.(19 + $offset), $order->destination ?? '');
        $sheet->setCellValue('B'.(21 + $offset), $order->report_to ?? '');
        $sheet->setCellValue('B'.(24 + $offset), $order->purpose ?? '');
        $sheet->setCellValue('B'.(26 + $offset), $lastTravel);
        $sheet->setCellValue('C'.(30 + $offset), $order->Remarks ?? '');
    }

    // A27/A28 are rich text: a static label run followed by a value run. Swap only the
    // value run's text so the actual per_diem/appropriation prints in place of the
    // template's placeholder wording, while keeping the label's own formatting untouched.
    private function fillPerDiemAndAppropriation(Worksheet $sheet, object $order, int $offset): void
    {
        $this->replaceLastRichTextRun($sheet, 'A'.(27 + $offset), (string) ($order->per_diem ?? ''));
        $this->replaceLastRichTextRun($sheet, 'A'.(28 + $offset), (string) ($order->appropriation ?? ''));
    }

    private function replaceLastRichTextRun(Worksheet $sheet, string $coordinate, string $value): void
    {
        $richText = $sheet->getCell($coordinate)->getValue();
        if (! $richText instanceof RichText) {
            return;
        }

        $elements = $richText->getRichTextElements();
        $lastRun = end($elements);
        if ($lastRun) {
            $lastRun->setText($value);
        }
    }
}
