<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; color:#1f2937; margin:0; padding:0; }
        .header { background:#0f172a; color:#fff; padding:20px 24px; }
        .header-title { font-size:18px; font-weight:bold; }
        .header-sub { font-size:12px; color:#94a3b8; margin-top:4px; }
        .container { padding:24px; max-width:620px; margin:0 auto; }
        .card { border:1px solid #e5e7eb; padding:16px; border-radius:6px; margin-bottom:16px; }
        .card-title { font-size:13px; font-weight:bold; margin-bottom:10px; color:#374151; text-transform:uppercase; letter-spacing:.05em; }
        ul { margin:0; padding-left:18px; }
        ul li { margin-bottom:6px; font-size:14px; line-height:1.5; }
        .hr { border-top:1px solid #e5e7eb; margin:20px 0; }
        .muted { color:#6b7280; font-size:12px; }
    </style>
    <title>New Official File Locator</title>
</head>
<body>
    <div class="header">
        <div class="header-title">HRIS &mdash; File Locator (Official)</div>
        <div class="header-sub">Human Resource Information System</div>
    </div>
    <div class="container">
        <p>Dear <strong>{{ $employee->dept_head_name ?? 'Department Head' }}</strong>,</p>

        <p><strong>{{ trim(($employee->first_name ?? '') . ' ' . ($employee->middle_name ?? '') . ' ' . ($employee->last_name ?? '')) }}</strong> from your department has filed a new Official File Locator application.</p>

        <div class="card">
            <div class="card-title">Employee Details</div>
            <ul>
                <li><strong>Employee No:</strong> {{ $employee->EmpNo ?? 'N/A' }}</li>
                <li><strong>Name:</strong> {{ trim(($employee->first_name ?? '') . ' ' . ($employee->middle_name ?? '') . ' ' . ($employee->last_name ?? '')) }}</li>
                <li><strong>Department:</strong> {{ $employee->department_name ?? ($employee->Dept_id ?? 'N/A') }}</li>
            </ul>
        </div>

        <div class="card">
            <div class="card-title">Travel Details</div>
            <ul>
                <li><strong>Destination / Location:</strong> {{ $locator->location ?? 'N/A' }}</li>
                <li><strong>Travel Date:</strong> {{ $locator->travel_date ? \Carbon\Carbon::parse($locator->travel_date)->format('l, F j, Y') : 'N/A' }}</li>
                <li><strong>Purpose:</strong> Official</li>
                <li><strong>Travel Details:</strong> {{ $locator->detail ?? 'N/A' }}</li>
                <li><strong>Time of Departure:</strong> {{ $locator->intended_departure_time ? (\Carbon\Carbon::parse($locator->intended_departure_time)->format('H:i') . ' (' . \Carbon\Carbon::parse($locator->intended_departure_time)->format('h:i A') . ')') : 'N/A' }}</li>
                <li><strong>Time of Arrival:</strong> {{ $locator->intended_arrival_time ? (\Carbon\Carbon::parse($locator->intended_arrival_time)->format('H:i') . ' (' . \Carbon\Carbon::parse($locator->intended_arrival_time)->format('h:i A') . ')') : 'N/A' }}</li>
            </ul>
        </div>

        <div class="hr"></div>
        <p style="font-size:14px;">If you have questions, please contact the HR office.</p>
        <p style="font-size:14px;">Regards,<br/><strong>HRIS Team</strong></p>
        <p class="muted">This is an automated notification. Please do not reply to this email.</p>
    </div>
</body>
</html>
