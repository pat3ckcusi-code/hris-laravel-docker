<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $template['title'] ?? 'Document' }} — Print</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Times New Roman', serif;
            font-size: 12pt;
            color: #000;
            background: #fff;
            padding: 40px 60px;
            line-height: 1.7;
        }

        .print-container {
            max-width: 8.5in;
            margin: 0 auto;
            background: #fff;
            padding: 40px;
        }

        .no-print {
            margin-bottom: 20px;
            text-align: center;
        }

        .no-print button {
            padding: 10px 20px;
            margin: 0 5px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-print {
            background: #2563eb;
            color: #fff;
        }

        .btn-close {
            background: #6b7280;
            color: #fff;
        }

        .doc-header-img {
            text-align: center;
            margin-bottom: 20px;
        }

        .doc-header-img img {
            max-width: 100%;
            max-height: 120px;
            object-fit: contain;
        }

        .doc-title {
            text-align: center;
            text-transform: uppercase;
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 24px;
        }

        .doc-salutation {
            margin-bottom: 16px;
        }

        .document-body {
            margin: 20px 0;
            white-space: pre-line;
            text-align: justify;
            line-height: 1.5;
        }

        .doc-closing {
            margin-top: 20px;
            white-space: pre-line;
        }

        .signatories {
            margin-top: 48px;
            text-align: center;
            line-height: normal;
            margin: 0;
        }

        .sig-block {
            display: inline-block;
            text-align: center;
            margin: 0 32px;
            vertical-align: top;
            line-height: normal;
        }

        .sig-name {
            display: block;
            margin-bottom: 8px;
            line-height: normal;
        }

        .sig-desig {
            display: block;
            font-style: italic;
            line-height: normal;
        }

        .footer {
            margin-top: 40px;
            padding-top: 12px;
            border-top: 1px solid #aaa;
            white-space: pre-line;
            line-height: normal;
            margin: 0;
            font-style: italic;
            font-size: smaller;
        }

        .doc-footer-img {
            margin-top: 10px;
            text-align: center;
        }

        .doc-footer-img img {
            max-width: 100%;
            max-height: 80px;
            object-fit: contain;
        }

        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
            .print-container {
                padding: 0;
                max-width: 100%;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">Print Document</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>

<div class="print-container">
    @php
        $parts = $template ?? [];
        
        /* Helper to build inline CSS from styling array */
        $css = function(array $s = [], array $extra = []): string {
            $out = [];
            if (!empty($s['font']))      $out[] = "font-family:'" . addslashes($s['font']) . "',serif";
            if (!empty($s['size']))      $out[] = 'font-size:' . (int)$s['size'] . 'pt';
            if (!empty($s['color']))     $out[] = 'color:' . $s['color'];
            if (!empty($s['bold']))      $out[] = 'font-weight:bold';
            if (!empty($s['italic']))    $out[] = 'font-style:italic';
            if (!empty($s['underline'])) $out[] = 'text-decoration:underline';
            foreach ($extra as $k => $v) $out[] = "$k:$v";
            return implode(';', $out);
        };

        /* Extract parts with defaults */
        $headerImage = $documentRequest->documentType->header_image ?? ($parts['header_image'] ?? null);
        
        $bodyD = is_array($parts['body'] ?? null)
            ? $parts['body']
            : ['text' => ($parts['body'] ?? ''), 'font' => 'Times New Roman', 'size' => 12, 'color' => '#000000'];

        $closingD = is_array($parts['closing_remark'] ?? null)
            ? $parts['closing_remark']
            : ['text' => ($parts['closing_remark'] ?? ''), 'font' => 'Times New Roman', 'size' => 12, 'color' => '#000000'];

        $signatories = is_array($parts['signatories'] ?? null) ? $parts['signatories'] : [];

        $footerD = is_array($parts['footer'] ?? null)
            ? $parts['footer']
            : ['text' => ($parts['footer'] ?? ''), 'font' => 'Calibri', 'size' => 10, 'color' => '#000000', 'italic' => true];

        $footerImage = $documentRequest->documentType->footer_image ?? ($footerD['image'] ?? null);

        /* Prepare replacements */
        $employeeName = $employee->name ?? 'Employee';
        $designation = $employee->designation ?? 'Position';
        $employeeType = $employee->employee_type ?? 'Permanent';
        $department = $employee->dept_name ?? '';
        $dateToday = now()->format('F d, Y');

        /* Replace placeholders in body text */
        $bodyText = $bodyD['text'] ?? '';
        $bodyText = str_replace(
            ['{employee_name}', '{designation}', '{employee_type}', '{department}', '{date}'],
            [$employeeName, $designation, $employeeType, $department, $dateToday],
            $bodyText
        );

        /* Replace placeholders in closing text */
        $closingText = $closingD['text'] ?? '';
        $closingText = str_replace(
            ['{employee_name}', '{designation}', '{employee_type}', '{department}', '{date}'],
            [$employeeName, $designation, $employeeType, $department, $dateToday],
            $closingText
        );
    @endphp

    {{-- Header Image --}}
    @if ($headerImage)
        <div class="doc-header-img">
            <img src="{{ asset('storage/' . ltrim($headerImage, '/')) }}" alt="Header Banner">
        </div>
    @endif

    {{-- Title --}}
    <div class="doc-title">
        {{ $parts['title'] ?? $documentRequest->document_type ?? 'Document' }}
    </div>

    {{-- Salutation --}}
    <p class="doc-salutation">{{ $parts['salutation'] ?? 'To Whom It May Concern:' }}</p>

    {{-- Body --}}
    @if ($bodyText)
        <div class="document-body" style="{{ $css($bodyD) }}">
            {!! nl2br(e($bodyText)) !!}
        </div>
    @endif

    {{-- Closing Remark --}}
    @if ($closingText)
        <p class="doc-closing" style="{{ $css($closingD) }}">
            {!! nl2br(e($closingText)) !!}
        </p>
    @endif

    {{-- Signatories --}}
    @if (count($signatories))
        <div class="signatories">
            @foreach ($signatories as $sig)
                <div class="sig-block">
                    <span class="sig-name" style="{{ $css([
                        'font'   => $sig['name_font']  ?? 'Times New Roman',
                        'size'   => $sig['name_size']  ?? 14,
                        'color'  => $sig['name_color'] ?? '#000000',
                        'bold'   => $sig['name_bold']  ?? true,
                        'italic' => $sig['name_italic'] ?? false,
                    ]) }}">
                        {{ $sig['name'] ?? '' }}
                    </span>
                    <span class="sig-desig" style="{{ $css([
                        'font'   => $sig['desig_font']  ?? 'Times New Roman',
                        'size'   => $sig['desig_size']  ?? 11,
                        'color'  => $sig['desig_color'] ?? '#000000',
                        'italic' => $sig['desig_italic'] ?? true,
                    ]) }}">
                        {{ $sig['designation'] ?? '' }}
                    </span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Footer --}}
    @if (($footerD['text'] ?? '') !== '' || $footerImage)
        <div class="footer" style="{{ $css($footerD) }}">
            {!! nl2br(e($footerD['text'] ?? '')) !!}
        </div>
        @if ($footerImage)
            <div class="doc-footer-img">
                <img src="{{ asset('storage/' . ltrim($footerImage, '/')) }}" alt="Footer Logo">
            </div>
        @endif
    @endif
</div>

</body>
</html>
