<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OfficeOrderWordExportService
{
    /**
     * Build the Office Order memo as a .docx on long bond / Folio paper (8.5" x 13").
     *
     * @param  object  $order  office_orders DB row
     * @param  iterable  $recipients  list of ['name','designation'] (the "To")
     * @param  array|null  $issuer  ['name','designation'] of the department head (the "From")
     */
    public function download($order, iterable $recipients, ?array $issuer): BinaryFileResponse
    {
        $recipients = collect($recipients);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Times New Roman');
        $phpWord->setDefaultFontSize(12);

        $section = $phpWord->addSection([
            'pageSizeW' => Converter::inchToTwip(8.5),
            'pageSizeH' => Converter::inchToTwip(13),
            'marginTop' => Converter::inchToTwip(0.8),
            'marginBottom' => Converter::inchToTwip(0.8),
            'marginLeft' => Converter::inchToTwip(0.8),
            'marginRight' => Converter::inchToTwip(0.8),
        ]);

        // Office Order number (number underlined)
        $titleRun = $section->addTextRun(['spaceAfter' => 300]);
        $titleRun->addText('Office Order No. ', ['bold' => true, 'size' => 13]);
        $titleRun->addText((string) ($order->office_order_num ?? $order->id), ['bold' => true, 'size' => 13, 'underline' => 'single']);

        // Memo header (To / From / Subject / Date) as a borderless table
        $labelW = Converter::inchToTwip(0.9);
        $valueW = Converter::inchToTwip(6.0);
        $table = $section->addTable(['cellMargin' => 40, 'borderSize' => 0, 'borderColor' => 'ffffff']);

        // To
        $row = $table->addRow();
        $row->addCell($labelW)->addText('To', ['bold' => true]);
        $toCell = $row->addCell($valueW);
        if ($recipients->isNotEmpty()) {
            foreach ($recipients as $r) {
                $toCell->addText(strtoupper($r['name'] ?? ''), ['bold' => true], ['spaceAfter' => 0]);
                if (! empty($r['designation'])) {
                    $toCell->addText($r['designation'], ['italic' => true], ['spaceAfter' => 80]);
                }
            }
        } else {
            $toCell->addText('—');
        }

        // From
        $row = $table->addRow();
        $row->addCell($labelW)->addText('From', ['bold' => true]);
        $fromCell = $row->addCell($valueW);
        $fromCell->addText(strtoupper($issuer['name'] ?? '—'), ['bold' => true], ['spaceAfter' => 0]);
        if (! empty($issuer['designation'])) {
            $fromCell->addText($issuer['designation'], ['italic' => true], ['spaceAfter' => 0]);
        }

        // Subject
        $row = $table->addRow();
        $row->addCell($labelW)->addText('Subject', ['bold' => true]);
        $row->addCell($valueW)->addText($order->subject ?? '—', ['bold' => true]);

        // Date
        $date = ! empty($order->issued_date)
            ? strtoupper(Carbon::parse($order->issued_date)->format('F d, Y'))
            : '—';
        $row = $table->addRow();
        $row->addCell($labelW)->addText('Date', ['bold' => true]);
        $row->addCell($valueW)->addText($date, ['bold' => true]);

        // Thick rule
        $section->addText('', [], [
            'borderBottomSize' => 18,
            'borderBottomColor' => '000000',
            'spaceBefore' => 140,
            'spaceAfter' => 240,
        ]);

        // Directive body
        foreach (explode("\n", (string) ($order->details ?? '')) as $line) {
            $section->addText($line !== '' ? $line : ' ', [], ['alignment' => 'both', 'spaceAfter' => 0]);
        }

        // Standard closing
        $section->addTextBreak(1);
        $section->addText('For information and strict compliance.');

        // Conformed by recipient(s)
        $section->addTextBreak(3);
        $section->addText('Conformed:');
        $section->addTextBreak(2);
        if ($recipients->isNotEmpty()) {
            foreach ($recipients as $r) {
                $section->addText(strtoupper($r['name'] ?? ''), ['bold' => true], ['spaceAfter' => 240]);
            }
        } else {
            $section->addText('_______________________', ['bold' => true]);
        }

        $filename = 'office-order-'.str($order->office_order_num ?? $order->id)->slug().'.docx';

        // Write to a temp file and return a BinaryFileResponse so the framework
        // sends a correct Content-Length. A streamed body with no Content-Length
        // can be truncated/corrupted through nginx/php-fpm, which Word then reports
        // as a damaged document.
        $tmp = tempnam(sys_get_temp_dir(), 'oo_word_');
        IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);

        return response()->download($tmp, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ])->deleteFileAfterSend(true);
    }
}
