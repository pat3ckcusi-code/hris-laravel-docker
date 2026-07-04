<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>[HRIS] {{ $requestType }} &ndash; {{ $status }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #1f2937; margin: 0; padding: 0; background: #f9fafb; }
        .wrapper { max-width: 620px; margin: 0 auto; background: #ffffff; }
        .header { background: #0f172a; color: #fff; padding: 20px 24px; }
        .header-title { font-size: 18px; font-weight: bold; }
        .header-sub { font-size: 12px; color: #94a3b8; margin-top: 4px; }
        .body { padding: 24px; }
        .banner { padding: 12px 16px; border-radius: 6px; font-size: 15px; font-weight: bold; margin-bottom: 20px; text-align: center; }
        .banner-filed               { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
        .banner-approved            { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .banner-accepted            { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .banner-completed           { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
        .banner-rejected            { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .banner-declined            { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .banner-cancelled           { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .banner-cancellation-requested { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .banner-printing-allowed    { background: #f3e8ff; color: #7e22ce; border: 1px solid #d8b4fe; }
        .banner-default             { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .card { border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 16px; overflow: hidden; }
        .card-title { font-size: 11px; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; padding: 10px 16px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; }
        table.details { width: 100%; border-collapse: collapse; }
        table.details td { padding: 8px 16px; font-size: 14px; vertical-align: top; border-bottom: 1px solid #f3f4f6; }
        table.details tr:last-child td { border-bottom: none; }
        table.details td:first-child { color: #6b7280; font-weight: bold; white-space: nowrap; width: 38%; }
        .notes-box { background: #fef9c3; border: 1px solid #fde047; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
        hr { border: none; border-top: 1px solid #e5e7eb; margin: 20px 0; }
        .footer { color: #9ca3af; font-size: 12px; }
    </style>
</head>
<body>
<div class="wrapper">

    <div class="header">
        <div class="header-title">{{ $settings?->system_name ?? 'HRIS' }} {{ $requestType }}</div>
        <div class="header-sub">Human Resource Information System &bull; {{ $settings?->org_name ?? 'LGU Calapan' }}</div>
    </div>

    <div class="body">
        @php
            $name = trim(collect([
                $notifiable->first_name ?? null,
                $notifiable->middle_name ?? null,
                $notifiable->last_name  ?? null,
            ])->filter()->implode(' ')) ?: ($notifiable->name ?? 'Employee');

            $bannerKey = strtolower(str_replace(' ', '-', $status));
            $knownBanners = ['filed','approved','accepted','completed','rejected','declined','cancelled','cancellation-requested','printing-allowed'];
            $bannerClass  = in_array($bannerKey, $knownBanners) ? "banner-{$bannerKey}" : 'banner-default';

            $isPositive = in_array($bannerKey, ['approved','accepted','completed']);
            $isNegative = in_array($bannerKey, ['rejected','declined']);
        @endphp

        <p>Dear <strong>{{ $name }}</strong>,</p>

        <div class="banner {{ $bannerClass }}">
            @if($isPositive)&#10003;&nbsp;
            @elseif($isNegative)&#10007;&nbsp;
            @else&bull;&nbsp;
            @endif
            Your <strong>{{ $requestType }}</strong> has been marked as <strong>{{ strtoupper($status) }}</strong>.
        </div>

        <div class="card">
            <div class="card-title">Request Details</div>
            <table class="details">
                <tr><td>Request Type</td><td>{{ $requestType }}</td></tr>
                <tr><td>Status</td><td><strong>{{ $status }}</strong></td></tr>
                @foreach($details as $label => $value)
                    @if(!is_null($value) && $value !== '')
                    <tr><td>{{ $label }}</td><td>{{ $value }}</td></tr>
                    @endif
                @endforeach
                @if($actor)
                <tr><td>Processed by</td><td>{{ $actor }}</td></tr>
                @endif
                <tr><td>Sent at</td><td>{{ $sentAt }}</td></tr>
            </table>
        </div>

        @if($notes)
        <div class="notes-box">
            <strong>Remarks / Notes:</strong><br>{{ $notes }}
        </div>
        @endif

        <hr>
        <p style="font-size:14px;">For questions, please contact your Department Head or the HR office directly.</p>
        <p style="font-size:14px;">Regards,<br><strong>{{ $s?->mail_from_name ?? $s?->org_name ?? 'City Human Resource Office Department' }}</strong></p>
        <p class="footer">This is an automated notification from the HRIS system. Please do not reply to this email.</p>
    </div>

</div>
</body>
</html>
