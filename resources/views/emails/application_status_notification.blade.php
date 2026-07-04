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
        .status-banner { padding:14px 16px; border-radius:6px; font-size:15px; font-weight:bold; margin-bottom:20px; text-align:center; }
        .status-approved { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
        .status-declined { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }
        .card { border:1px solid #e5e7eb; padding:16px; border-radius:6px; margin-bottom:16px; }
        .card-title { font-size:13px; font-weight:bold; margin-bottom:10px; color:#374151; text-transform:uppercase; letter-spacing:.05em; }
        ul { margin:0; padding-left:18px; }
        ul li { margin-bottom:6px; font-size:14px; line-height:1.5; }
        .notes-box { background:#fef9c3; border:1px solid #fde047; padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:14px; }
        .hr { border-top:1px solid #e5e7eb; margin:20px 0; }
        .muted { color:#6b7280; font-size:12px; }
    </style>
    <title>{{ $application_type }} Status</title>
</head>
<body>
    <div class="header">
        <div class="header-title">HRIS {{ $application_type }} Update</div>
        <div class="header-sub">Human Resource Information System</div>
    </div>
    <div class="container">

        <p>Dear <strong>{{ $employee->first_name ?? ($employee->name ?? 'Employee') }}</strong>,</p>

        @if($action === 'approved')
        <div class="status-banner status-approved">&#10003;&nbsp; Your {{ $application_type }} has been APPROVED.</div>
        @else
        <div class="status-banner status-declined">&#10007;&nbsp; Your {{ $application_type }} has been REJECTED.</div>
        @endif

        <div class="card">
            <div class="card-title">Employee Details</div>
            <ul>
                <li><strong>Employee No:</strong> {{ $employee->EmpNo ?? 'N/A' }}</li>
                <li><strong>Name:</strong> {{ trim(($employee->first_name ?? '') . ' ' . ($employee->middle_name ?? '') . ' ' . ($employee->last_name ?? '')) }}</li>
                <li><strong>Department:</strong> {{ $employee->department_name ?? 'N/A' }}</li>
                <li><strong>Application Type:</strong> {{ $application_type }}</li>
                <li><strong>Status:</strong>
                    @if($action === 'approved')
                        <span style="color:#15803d;font-weight:bold;">Approved</span>
                    @else
                        <span style="color:#b91c1c;font-weight:bold;">Rejected</span>
                    @endif
                </li>
            </ul>
        </div>

        <div class="card">
            <div class="card-title">Application Details</div>
            <ul>
            @if($application_type === 'ETA')
                <li><strong>Destination:</strong> {{ $application->destination ?? $application->location ?? 'N/A' }}</li>
                <li><strong>Departure Date:</strong> {{ $formatted['departure'] ?? (\Carbon\Carbon::parse($application->departure_date ?? $application->travel_date ?? now())->format('l, F j, Y')) }}</li>
                <li><strong>Arrival Date:</strong> {{ $formatted['arrival'] ?? (\Carbon\Carbon::parse($application->arrival_date ?? now())->format('l, F j, Y')) }}</li>
                <li><strong>Purpose:</strong> {{ $application->purpose ?? 'N/A' }}</li>
                <li><strong>Details:</strong> {{ $application->purpose_details ?? $application->detail ?? 'N/A' }}</li>
            @elseif($application_type === 'Locator - Official' || $application_type === 'Locator - Personal' || $application_type === 'Locator')
                <li><strong>Location:</strong> {{ $application->location ?? $application->destination ?? 'N/A' }}</li>
                <li><strong>Travel Date:</strong> {{ $formatted['travel'] ?? (\Carbon\Carbon::parse($application->travel_date ?? now())->format('l, F j, Y')) }}</li>
                <li><strong>Time of Departure:</strong> {{ ($formatted['departure_time_24'] ?? (\Carbon\Carbon::parse($application->intended_departure_time ?? now())->format('H:i'))) }} @if(isset($formatted['departure_time_ampm'])) ({{ $formatted['departure_time_ampm'] }}) @endif</li>
                <li><strong>Time of Arrival:</strong> {{ ($formatted['arrival_time_24'] ?? (\Carbon\Carbon::parse($application->intended_arrival_time ?? now())->format('H:i'))) }} @if(isset($formatted['arrival_time_ampm'])) ({{ $formatted['arrival_time_ampm'] }}) @endif</li>
                <li><strong>Details:</strong> {{ $application->detail ?? $application->purpose_details ?? 'N/A' }}</li>
            @else
                <li><strong>Start:</strong> {{ $formatted['start'] ?? (\Carbon\Carbon::parse($application->start_date ?? now())->format('l, F j, Y')) }}</li>
                <li><strong>End:</strong> {{ $formatted['end'] ?? (\Carbon\Carbon::parse($application->end_date ?? now())->format('l, F j, Y')) }}</li>
                <li><strong>Leave Type:</strong> {{ $application->leave_type ?? 'N/A' }}</li>
            @endif
            </ul>
        </div>

        @if(!empty($notes))
        <div class="notes-box">
            <strong>Remarks:</strong><br/>
            {{ $notes }}
        </div>
        @endif

        <div class="hr"></div>
        <p style="font-size:14px;">If you have questions, please contact your department head or HR office.</p>
        <p style="font-size:14px;">Regards,<br/><strong>HRIS Team</strong></p>
        <p class="muted">This is an automated notification. Please do not reply to this email.</p>

    </div>
</body>
</html>
