<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $documentType?->name ?? 'Document' }} - Print</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Times New Roman', serif; font-size: 12pt; color: #000; background: #fff; padding: 40px 60px; line-height: 1.7; }
        .doc-header-img { text-align: center; margin-bottom: 20px; }
        .doc-header-img img { max-width: 100%; max-height: 120px; object-fit: contain; }
        .doc-title { text-align: center; text-transform: uppercase; font-size: 16pt; font-weight: bold; margin-bottom: 24px; }
        .doc-salutation { margin-bottom: 16px; }
        .doc-body { margin: 20px 0; white-space: pre-line; text-align: justify; }
        .doc-closing { margin-top: 20px; white-space: pre-line; }
        .doc-signatories { margin-top: 48px; text-align: center; }
        .sig-block { display: inline-block; text-align: center; margin: 0 32px; vertical-align: top; }
        .sig-name { display: block; }
        .sig-desig { display: block; }
        .doc-footer-text { margin-top: 40px; padding-top: 12px; border-top: 1px solid #aaa; white-space: pre-line; }
        .doc-footer-img { margin-top: 10px; text-align: center; }
        .doc-footer-img img { max-width: 100%; max-height: 80px; object-fit: contain; }
        @media print {
            body { padding: 20px 40px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="no-print" style="margin-bottom:20px;">
    <button onclick="window.print()" style="padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px;">
        Print Document
    </button>
    <button onclick="window.close()" style="margin-left:8px;padding:8px 16px;background:#6b7280;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:14px;">
        Close
    </button>
</div>

@php
use Illuminate\Support\Facades\Storage;

$parts = $documentType?->parts ?? [];

/* ── helper: build inline CSS string from a styling array ── */
$css = function(array $s, array $extra = []): string {
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

/* ── header image ── */
$headerImage = $parts['header_image'] ?? null;

/* ── body ── */
$bodyD = is_array($parts['body'] ?? null)
    ? $parts['body']
    : ['text' => ($parts['body'] ?? ''), 'font' => 'Times New Roman', 'size' => 12, 'color' => '#000000'];

$bodyText = $bodyD['text'] ?? '';
$bodyText = str_replace(
    ['{employee_name}', '{date}', '{designation}', '{employee_type}', '{position}', '{department}'],
    [
        $user->name ?? '',
        now()->format('F d, Y'),
        $user->designation ?? '',
        $user->employee_type ?? '',
        $user->designation ?? '',
        '',
    ],
    $bodyText
);

/* ── closing remark ── */
$closingD = is_array($parts['closing_remark'] ?? null)
    ? $parts['closing_remark']
    : ['text' => ($parts['closing_remark'] ?? ''), 'font' => 'Times New Roman', 'size' => 12, 'color' => '#000000'];

$closingText = $closingD['text'] ?? '';
$closingText = str_replace(
    ['{employee_name}', '{date}', '{designation}', '{employee_type}'],
    [$user->name ?? '', now()->format('F d, Y'), $user->designation ?? '', $user->employee_type ?? ''],
    $closingText
);

/* ── signatories ── */
$signatories = is_array($parts['signatories'] ?? null) ? $parts['signatories'] : [];

/* ── footer ── */
$footerD = is_array($parts['footer'] ?? null)
    ? $parts['footer']
    : ['text' => ($parts['footer'] ?? ''), 'font' => 'Calibri', 'size' => 10, 'color' => '#000000', 'italic' => true];

$footerImage = $footerD['image'] ?? null;
@endphp

{{-- ── Header Image ── --}}
@if ($headerImage)
    <div class="doc-header-img">
        <img src="{{ Storage::url($headerImage) }}" alt="Header">
    </div>
@endif

{{-- ── Title ── --}}
<div class="doc-title">
    {{ $parts['title'] ?? $documentType?->name ?? 'Document' }}
</div>

{{-- ── Salutation ── --}}
<p class="doc-salutation">{{ $parts['salutation'] ?? 'To Whom It May Concern:' }}</p>

{{-- ── Body ── --}}
@if ($bodyText)
    <div class="doc-body" style="{{ $css($bodyD) }}">{{ $bodyText }}</div>
@endif

{{-- ── Closing Remark ── --}}
@if ($closingText)
    <p class="doc-closing" style="{{ $css($closingD) }}">{{ $closingText }}</p>
@endif

{{-- ── Signatories ── --}}
@if (count($signatories))
    <div class="doc-signatories">
        @foreach ($signatories as $sig)
            <div class="sig-block">
                <span class="sig-name" style="{{ $css([
                    'font'   => $sig['name_font']  ?? 'Times New Roman',
                    'size'   => $sig['name_size']  ?? 14,
                    'color'  => $sig['name_color'] ?? '#000000',
                    'bold'   => $sig['name_bold']  ?? true,
                    'italic' => $sig['name_italic'] ?? false,
                ]) }}">{{ $sig['name'] ?? '' }}</span>
                <span class="sig-desig" style="{{ $css([
                    'font'   => $sig['desig_font']  ?? 'Times New Roman',
                    'size'   => $sig['desig_size']  ?? 11,
                    'color'  => $sig['desig_color'] ?? '#000000',
                    'italic' => $sig['desig_italic'] ?? true,
                ]) }}">{{ $sig['designation'] ?? '' }}</span>
            </div>
        @endforeach
    </div>
@endif

{{-- ── Footer ── --}}
@if (($footerD['text'] ?? '') !== '' || $footerImage)
    <div class="doc-footer-text" style="{{ $css($footerD) }}">{{ $footerD['text'] ?? '' }}</div>
    @if ($footerImage)
        <div class="doc-footer-img">
            <img src="{{ Storage::url($footerImage) }}" alt="Footer">
        </div>
    @endif
@endif

</body>
</html>
