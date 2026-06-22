<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\PayrollDetail;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentWordExportService
{
    private const PAGE_WIDTH_TWIP = 10800; // 7.5in usable at 0.5in margins each side (narrow)

    public function download(DocumentRequest $documentRequest, string $paper = 'letter'): StreamedResponse
    {
        $documentType = $documentRequest->documentType;
        $employee     = $documentRequest->employee;
        $parts        = $documentType?->parts ?? [];

        $salaryRaw = $employee
            ? PayrollDetail::where('employee_id', $employee->id)->latest('id')->value('basic_salary')
            : null;

        $replacements = [
            '{employee_name}' => $employee?->name ?? 'Employee',
            '{designation}'   => $employee?->designation ?? 'Position',
            '{employee_type}' => $employee?->employee_type ?? 'Permanent',
            '{department}'    => $employee?->dept_name ?? '',
            '{date}'          => now()->format('F d, Y'),
            '{salary}'        => $salaryRaw !== null ? '₱' . number_format((float) $salaryRaw, 2) : 'N/A',
        ];

        $paperSize = match (strtolower($paper)) {
            'legal' => 'Legal',
            'a4'    => 'A4',
            default => 'Letter',
        };

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Times New Roman');
        $phpWord->setDefaultFontSize(12);

        $section = $phpWord->addSection([
            'paperSize'    => $paperSize,
            'marginTop'    => Converter::inchToTwip(0.5),
            'marginBottom' => Converter::inchToTwip(0.5),
            'marginLeft'   => Converter::inchToTwip(0.5),
            'marginRight'  => Converter::inchToTwip(0.5),
        ]);

        $headerImage = $documentType?->header_image;
        if ($headerImage) {
            $path = storage_path('app/public/' . ltrim($headerImage, '/'));
            if (file_exists($path)) {
                $header = $section->addHeader();
                $header->addImage($path, ['width' => 500, 'alignment' => 'center']);
            }
        }

        // Title
        $title = strtoupper($parts['title'] ?? $documentRequest->document_type ?? 'Document');
        $section->addText($title, ['bold' => true, 'size' => 16], ['alignment' => 'center', 'spaceAfter' => 240]);

        // Salutation
        $section->addText($parts['salutation'] ?? 'To Whom It May Concern:', [], ['spaceAfter' => 160]);

        // Body
        $bodyD    = $this->normalizeStyledText($parts['body'] ?? []);
        $bodyRaw  = $bodyD['text'];
        if ($bodyRaw !== '') {
            $fontStyle        = $this->fontStyle($bodyD);
            $phStyles         = $parts['placeholder_styles'] ?? [];
            $bodyParaStyle    = ['alignment' => 'both', 'spaceAfter' => 0];
            $tokenMap         = [
                '{employee_name}' => 'employee_name',
                '{designation}'   => 'designation',
                '{employee_type}' => 'employee_type',
                '{department}'    => 'department',
                '{date}'          => 'date',
                '{salary}'        => 'salary',
            ];
            $tokenPattern = '/(' . implode('|', array_map(fn ($t) => preg_quote($t, '/'), array_keys($tokenMap))) . ')/';

            foreach (explode("\n", $bodyRaw) as $line) {
                if ($line === '') {
                    $section->addText(' ', $fontStyle, $bodyParaStyle);
                    continue;
                }
                $segments = preg_split($tokenPattern, $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
                $textRun  = $section->addTextRun($bodyParaStyle);
                foreach ($segments as $seg) {
                    if (isset($tokenMap[$seg])) {
                        $key   = $tokenMap[$seg];
                        $value = $replacements[$seg] ?? $seg;
                        $phFontStyle = !empty($phStyles[$key])
                            ? array_merge($fontStyle, $this->fontStyle($phStyles[$key]))
                            : $fontStyle;
                        $textRun->addText($value !== '' ? $value : ' ', $phFontStyle);
                    } else {
                        $textRun->addText($seg, $fontStyle);
                    }
                }
            }
            $section->addTextBreak(1);
        }

        // Closing remark
        $closingD    = $this->normalizeStyledText($parts['closing_remark'] ?? []);
        $closingText = strtr($closingD['text'], $replacements);
        if ($closingText !== '') {
            $fontStyle = $this->fontStyle($closingD);
            foreach (explode("\n", $closingText) as $line) {
                $section->addText($line !== '' ? $line : ' ', $fontStyle, ['spaceAfter' => 0]);
            }
            $section->addTextBreak(2);
        }

        // Signatories
        $signatories = is_array($parts['signatories'] ?? null) ? $parts['signatories'] : [];
        if ($signatories) {
            $colWidth = (int) (self::PAGE_WIDTH_TWIP / count($signatories));
            $table = $section->addTable(['borderSize' => 0, 'borderColor' => 'ffffff', 'cellMargin' => 0]);
            $row   = $table->addRow();
            foreach ($signatories as $sig) {
                $cell = $row->addCell($colWidth);
                $cell->addText(
                    $sig['name'] ?? '',
                    [
                        'bold'  => $sig['name_bold'] ?? true,
                        'size'  => $sig['name_size'] ?? 14,
                        'color' => $this->hexColor($sig['name_color'] ?? '000000'),
                    ],
                    ['alignment' => 'center']
                );
                $cell->addText(
                    $sig['designation'] ?? '',
                    [
                        'italic' => $sig['desig_italic'] ?? true,
                        'size'   => $sig['desig_size'] ?? 11,
                        'color'  => $this->hexColor($sig['desig_color'] ?? '000000'),
                    ],
                    ['alignment' => 'center']
                );
            }
            $section->addTextBreak(2);
        }

        // Footer
        $footerD    = $this->normalizeStyledText($parts['footer'] ?? []);
        $footerText = $footerD['text'];
        if ($footerText !== '') {
            $section->addText('', [], ['borderTopSize' => 6, 'borderTopColor' => 'aaaaaa', 'spaceBefore' => 120]);
            $fontStyle = array_merge($this->fontStyle($footerD), ['italic' => true, 'size' => 10]);
            foreach (explode("\n", $footerText) as $line) {
                $section->addText($line !== '' ? $line : ' ', $fontStyle, ['spaceAfter' => 0]);
            }
        }

        $footerImage = $documentType?->footer_image;
        if ($footerImage) {
            $path = storage_path('app/public/' . ltrim($footerImage, '/'));
            if (file_exists($path)) {
                $footer = $section->addFooter();
                $footer->addImage($path, ['width' => 400, 'alignment' => 'center']);
            }
        }

        $filename = str($documentRequest->document_type ?? 'document')->slug() . '-' . $documentRequest->id . '.docx';

        return new StreamedResponse(function () use ($phpWord) {
            IOFactory::createWriter($phpWord, 'Word2007')->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store',
        ]);
    }

    private function normalizeStyledText(mixed $value): array
    {
        if (is_array($value)) {
            return array_merge(['text' => ''], $value);
        }
        return ['text' => (string) ($value ?? '')];
    }

    private function fontStyle(array $d): array
    {
        $style = [];
        if (!empty($d['font']))      $style['name']      = $d['font'];
        if (!empty($d['size']))      $style['size']      = (int) $d['size'];
        if (!empty($d['color']))     $style['color']     = $this->hexColor($d['color']);
        if (!empty($d['bold']))      $style['bold']      = true;
        if (!empty($d['italic']))    $style['italic']    = true;
        if (!empty($d['underline'])) $style['underline'] = 'single';
        return $style;
    }

    private function hexColor(string $color): string
    {
        return ltrim($color, '#');
    }
}
