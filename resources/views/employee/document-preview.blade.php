@extends('dashboards.layout', [
    'title' => 'Document Preview',
    'subtitle' => 'Preview your requested document.',
])

@section('content')
@php
use Illuminate\Support\Facades\Storage;

$parts = $documentType?->parts ?? [];

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

$headerImage = $parts['header_image'] ?? null;

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

$closingD = is_array($parts['closing_remark'] ?? null)
    ? $parts['closing_remark']
    : ['text' => ($parts['closing_remark'] ?? ''), 'font' => 'Times New Roman', 'size' => 12, 'color' => '#000000'];

$closingText = $closingD['text'] ?? '';
$closingText = str_replace(
    ['{employee_name}', '{date}', '{designation}', '{employee_type}'],
    [$user->name ?? '', now()->format('F d, Y'), $user->designation ?? '', $user->employee_type ?? ''],
    $closingText
);

$signatories = is_array($parts['signatories'] ?? null) ? $parts['signatories'] : [];

$footerD = is_array($parts['footer'] ?? null)
    ? $parts['footer']
    : ['text' => ($parts['footer'] ?? ''), 'font' => 'Calibri', 'size' => 10, 'color' => '#000000', 'italic' => true];

$footerImage = $footerD['image'] ?? null;
@endphp

<div style="max-width:800px;margin:0 auto;">
    <div style="background:#fff;padding:40px 50px;border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.12);font-family:'Times New Roman',serif;line-height:1.7;">

        {{-- Header Image --}}
        @if ($headerImage)
            <div style="text-align:center;margin-bottom:20px;">
                <img src="{{ Storage::url($headerImage) }}" alt="Header"
                     style="max-width:100%;max-height:120px;object-fit:contain;">
            </div>
        @endif

        {{-- Title --}}
        <h2 style="text-align:center;text-transform:uppercase;margin-bottom:24px;font-size:16pt;">
            {{ $parts['title'] ?? $documentType?->name ?? 'Document' }}
        </h2>

        {{-- Salutation --}}
        <p style="margin-bottom:16px;">{{ $parts['salutation'] ?? 'To Whom It May Concern:' }}</p>

        {{-- Body --}}
        @if ($bodyText)
            <div style="margin:20px 0;white-space:pre-line;text-align:justify;{{ $css($bodyD) }}">{{ $bodyText }}</div>
        @endif

        {{-- Closing Remark --}}
        @if ($closingText)
            <p style="margin-top:20px;white-space:pre-line;{{ $css($closingD) }}">{{ $closingText }}</p>
        @endif

        {{-- Signatories --}}
        @if (count($signatories))
            <div style="margin-top:48px;text-align:center;">
                @foreach ($signatories as $sig)
                    <div style="display:inline-block;text-align:center;margin:0 32px;vertical-align:top;">
                        <span style="display:block;{{ $css([
                            'font'   => $sig['name_font']  ?? 'Times New Roman',
                            'size'   => $sig['name_size']  ?? 14,
                            'color'  => $sig['name_color'] ?? '#000000',
                            'bold'   => $sig['name_bold']  ?? true,
                            'italic' => $sig['name_italic'] ?? false,
                        ]) }}">{{ $sig['name'] ?? '' }}</span>
                        <span style="display:block;{{ $css([
                            'font'   => $sig['desig_font']  ?? 'Times New Roman',
                            'size'   => $sig['desig_size']  ?? 11,
                            'color'  => $sig['desig_color'] ?? '#000000',
                            'italic' => $sig['desig_italic'] ?? true,
                        ]) }}">{{ $sig['designation'] ?? '' }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Footer --}}
        @if (($footerD['text'] ?? '') !== '' || $footerImage)
            <div style="margin-top:40px;padding-top:12px;border-top:1px solid #ccc;white-space:pre-line;{{ $css($footerD) }}">{{ $footerD['text'] ?? '' }}</div>
            @if ($footerImage)
                <div style="text-align:center;margin-top:10px;">
                    <img src="{{ Storage::url($footerImage) }}" alt="Footer"
                         style="max-width:100%;max-height:80px;object-fit:contain;">
                </div>
            @endif
        @endif
    </div>

    <div style="margin-top:16px;display:flex;gap:10px;">
        <a href="{{ route('document-requests.print', $documentRequest->id) }}"
           style="padding:10px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:4px;font-size:.95em;">
            Print
        </a>
        <a href="{{ route('dashboard.employee.request-documents') }}"
           style="padding:10px 20px;background:#6b7280;color:#fff;text-decoration:none;border-radius:4px;font-size:.95em;">
            Back
        </a>
    </div>
</div>
@endsection
