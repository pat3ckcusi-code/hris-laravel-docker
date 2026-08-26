<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $template['title'] ?? 'Document' }}</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        {{-- Bottom margin is deliberately large - it reserves the fixed
             signature/footer zone below (bottom 1.65in-5.0in from the page
             edge) so normal-flow body/closing-remark text can never run
             into it; content long enough to reach this margin naturally
             overflows to a new page instead of colliding with the fixed
             elements (only position:fixed content ignores @page margins;
             normal flow always respects them). --}}
        @page {
            size: letter;
            margin: 0.75in 0.9in 4.8in 0.9in;
        }

        body {
            font-family: 'Times New Roman', 'DejaVu Serif', serif;
            font-size: 12pt;
            color: #000;
            background: #fff;
            line-height: 1.7;
        }

        {{-- dompdf renders <body>'s own content box at the full page width
             (confirmed empirically - see .doc-header-img img below), ignoring
             @page's left/right margins for normal-flow sizing purposes. Every
             block-level normal-flow element (title/salutation/body/closing)
             would otherwise expand to that full width with no margin at all -
             wrapping them all in this explicitly-sized, auto-centered
             container is what actually gives them the intended 0.9in margins,
             matching @page's own value: (8.5in page - 6.7in) / 2 = 0.9in. The
             position:fixed elements below (.footer etc.) don't need this -
             their own explicit left:0.9in/right:0.9in already positions them
             correctly, confirmed separately. --}}
        .page-content {
            width: 6.7in;
            margin: 0 auto;
        }

        .doc-header-img {
            text-align: center;
            margin-bottom: 20px;
        }

        {{-- Explicit absolute width, not 100% - dompdf renders <body>'s own
             content box at the full page width here, ignoring @page's
             left/right margins for normal-flow sizing purposes (confirmed
             empirically: a 100% header image rendered edge-to-edge at
             8.5in instead of the intended 6.7in content width). 6.7in =
             8.5in page width - 0.9in left margin - 0.9in right margin. --}}
        .doc-header-img img {
            width: 6.7in;
            height: auto;
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
            text-align: justify;
            line-height: 1.5;
        }

        .doc-closing {
            margin-top: 20px;
        }

        .sig-block {
            display: inline-block;
            text-align: center;
            margin: 0 32px;
            line-height: 1.2;
        }

        .sig-name {
            display: block;
            margin-bottom: 8px;
            line-height: 1.2;
        }

        .sig-desig {
            display: block;
            font-style: italic;
            line-height: 1.2;
        }

        .doc-footer-img {
            margin-top: 10px;
            text-align: center;
        }

        .doc-footer-img img {
            width: 100%;
            height: auto;
        }

        {{-- Fixed signature/footer zone. Every piece below is independently
             position:fixed with its own explicit `bottom`, rather than
             stacked in normal flow inside one container - that keeps each
             piece's page position fully predictable regardless of how much
             text any of the others actually renders (font sizes/footer
             length are admin-configurable per DocumentType). dompdf repeats
             a position:fixed element identically on every rendered page, so
             these coordinates hold regardless of body length; only the page
             number to stamp on varies (resolved via get_page_count() in
             DocumentRequestEsignatureService::renderUnsignedPdf(), which
             also hardcodes this same rect - keep the two in sync). --}}
        .footer {
            position: fixed;
            left: 0.9in;
            right: 0.9in;
            bottom: 0.5in;
            text-align: left;
            font-style: italic;
            font-size: 8pt;
            line-height: 1.3;
            padding-top: 10px;
            border-top: 1px solid #aaa;
        }

        .primary-sig-block {
            position: fixed;
            left: 0.9in;
            right: 0.9in;
            bottom: 2.6in;
            text-align: center;
            line-height: 1.2;
        }

        {{-- Blank, reserved area for the HR Manager's PNPKI stamp - always
             directly above the primary (first-listed) signatory's printed
             name in .primary-sig-block above. --}}
        .signature-area {
            position: fixed;
            left: 0.9in;
            right: 0.9in;
            bottom: 3.25in;
            height: 0.55in;
        }

        .other-signatories {
            position: fixed;
            left: 0.9in;
            right: 0.9in;
            bottom: 4.35in;
            text-align: center;
            line-height: 1.2;
        }
    </style>
</head>
<body>

@php
    $parts = $template ?? [];

    $css = function (array $s = [], array $extra = []): string {
        $out = [];
        if (! empty($s['font'])) {
            $sansFamilies = ['Arial', 'Calibri', 'Verdana'];
            $dejaVuFallback = in_array($s['font'], $sansFamilies, true) ? 'DejaVu Sans' : 'DejaVu Serif';
            $out[] = "font-family:'".addslashes($s['font'])."','{$dejaVuFallback}',serif";
        }
        if (! empty($s['size'])) $out[] = 'font-size:'.(int) $s['size'].'pt';
        if (! empty($s['color'])) $out[] = 'color:'.$s['color'];
        if (! empty($s['bold'])) $out[] = 'font-weight:bold';
        if (! empty($s['italic'])) $out[] = 'font-style:italic';
        if (! empty($s['underline'])) $out[] = 'text-decoration:underline';
        foreach ($extra as $k => $v) $out[] = "$k:$v";

        return implode(';', $out);
    };

    // Guards a missing/moved upload from ever surfacing as dompdf's raw
    // alt-text-as-canvas-text fallback (its documented behavior for any
    // <img> it can't load) in a signed legal document - skip the image
    // block entirely instead.
    $imageExists = fn (?string $path): bool => $path && file_exists(public_path('storage/'.ltrim($path, '/')));

    $headerImage = $documentRequest->documentType->header_image ?? ($parts['header_image'] ?? null);

    $bodyD = is_array($parts['body'] ?? null)
        ? $parts['body']
        : ['text' => ($parts['body'] ?? ''), 'font' => 'Times New Roman', 'size' => 12, 'color' => '#000000'];

    $closingD = is_array($parts['closing_remark'] ?? null)
        ? $parts['closing_remark']
        : ['text' => ($parts['closing_remark'] ?? ''), 'font' => 'Times New Roman', 'size' => 12, 'color' => '#000000'];

    $signatories = is_array($parts['signatories'] ?? null) ? $parts['signatories'] : [];
    $primarySignatory = $signatories[0] ?? null;
    $otherSignatories = array_slice($signatories, 1);

    $footerD = is_array($parts['footer'] ?? null)
        ? $parts['footer']
        : ['text' => ($parts['footer'] ?? ''), 'font' => 'Calibri', 'size' => 10, 'color' => '#000000', 'italic' => true];

    $footerImage = $documentRequest->documentType->footer_image ?? ($footerD['image'] ?? null);

    $employeeName = $replacements['employee_name'];
    $designation = $replacements['designation'];
    $employeeType = $replacements['employee_type'];
    $department = $replacements['department'];
    $dateToday = $replacements['date'];
    $salaryFormatted = $replacements['salary'];

    $phStyles = $parts['placeholder_styles'] ?? [];
    $phMap = [
        '{employee_name}' => ['employee_name', $employeeName],
        '{designation}' => ['designation', $designation],
        '{employee_type}' => ['employee_type', $employeeType],
        '{department}' => ['department', $department],
        '{date}' => ['date', $dateToday],
        '{salary}' => ['salary', $salaryFormatted],
    ];
    $bodyHtml = e($bodyD['text'] ?? '');
    foreach ($phMap as $token => [$key, $value]) {
        $s = $phStyles[$key] ?? [];
        $inner = e($value);
        $replacement = ! empty($s) ? '<span style="'.$css($s).'">'.$inner.'</span>' : $inner;
        $bodyHtml = str_replace($token, $replacement, $bodyHtml);
    }
    $bodyHtml = nl2br($bodyHtml);

    $closingText = $closingD['text'] ?? '';
    $closingText = str_replace(
        ['{employee_name}', '{designation}', '{employee_type}', '{department}', '{date}'],
        [$employeeName, $designation, $employeeType, $department, $dateToday],
        $closingText
    );
@endphp

<div class="page-content">
    @if ($headerImage && $imageExists($headerImage))
        <div class="doc-header-img">
            <img src="{{ public_path('storage/'.ltrim($headerImage, '/')) }}" alt="Header Banner">
        </div>
    @endif

    <div class="doc-title">
        {{ $parts['title'] ?? $documentRequest->document_type ?? 'Document' }}
    </div>

    <p class="doc-salutation">{{ $parts['salutation'] ?? 'To Whom It May Concern:' }}</p>

    @if ($bodyHtml)
        <div class="document-body" style="{{ $css($bodyD) }}">
            {!! $bodyHtml !!}
        </div>
    @endif

    @if ($closingText)
        <p class="doc-closing" style="{{ $css($closingD) }}">
            {!! nl2br(e($closingText)) !!}
        </p>
    @endif
</div>

<div class="signature-area"></div>

@if ($primarySignatory)
    <div class="primary-sig-block">
        <span class="sig-name" style="{{ $css([
            'font' => $primarySignatory['name_font'] ?? 'Times New Roman',
            'size' => $primarySignatory['name_size'] ?? 14,
            'color' => $primarySignatory['name_color'] ?? '#000000',
            'bold' => $primarySignatory['name_bold'] ?? true,
            'italic' => $primarySignatory['name_italic'] ?? false,
        ]) }}">
            {{ $primarySignatory['name'] ?? '' }}
        </span>
        <span class="sig-desig" style="{{ $css([
            'font' => $primarySignatory['desig_font'] ?? 'Times New Roman',
            'size' => $primarySignatory['desig_size'] ?? 11,
            'color' => $primarySignatory['desig_color'] ?? '#000000',
            'italic' => $primarySignatory['desig_italic'] ?? true,
        ]) }}">
            {{ $primarySignatory['designation'] ?? '' }}
        </span>
    </div>
@endif

@if (count($otherSignatories))
    <div class="other-signatories">
        @foreach ($otherSignatories as $sig)
            <div class="sig-block">
                <span class="sig-name" style="{{ $css([
                    'font' => $sig['name_font'] ?? 'Times New Roman',
                    'size' => $sig['name_size'] ?? 14,
                    'color' => $sig['name_color'] ?? '#000000',
                    'bold' => $sig['name_bold'] ?? true,
                    'italic' => $sig['name_italic'] ?? false,
                ]) }}">
                    {{ $sig['name'] ?? '' }}
                </span>
                <span class="sig-desig" style="{{ $css([
                    'font' => $sig['desig_font'] ?? 'Times New Roman',
                    'size' => $sig['desig_size'] ?? 11,
                    'color' => $sig['desig_color'] ?? '#000000',
                    'italic' => $sig['desig_italic'] ?? true,
                ]) }}">
                    {{ $sig['designation'] ?? '' }}
                </span>
            </div>
        @endforeach
    </div>
@endif

@if (($footerD['text'] ?? '') !== '' || ($footerImage && $imageExists($footerImage)))
    <div class="footer" style="{{ $css($footerD) }}">
        {!! nl2br(e($footerD['text'] ?? '')) !!}
        @if ($footerImage && $imageExists($footerImage))
            <div class="doc-footer-img">
                <img src="{{ public_path('storage/'.ltrim($footerImage, '/')) }}" alt="Footer Logo">
            </div>
        @endif
    </div>
@endif

</body>
</html>
