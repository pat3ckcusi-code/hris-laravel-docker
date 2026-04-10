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
        table.balance { width:100%; border-collapse:collapse; }
        table.balance th,
        table.balance td { border:1px solid #d1d5db; padding:8px 12px; font-size:14px; }
        table.balance th { background:#f9fafb; font-weight:bold; }
        table.balance th.right,
        table.balance td.right { text-align:right; }
        .hr { border-top:1px solid #e5e7eb; margin:20px 0; }
        .muted { color:#6b7280; font-size:12px; }
    </style>
    <title>Leave Request Status</title>
</head>
<body>
    <div class="header">
        <div class="header-title">HRIS &mdash; Leave Request Update</div>
        <div class="header-sub">Human Resource Information System</div>
    </div>
    <div class="container">

        <p>Dear <strong>{{ $employee->first_name ?? ($employee->name ?? 'Employee') }}</strong>,</p>

        @if($action === 'approved')
        <div class="status-banner status-approved">&#10003;&nbsp; Your leave request has been APPROVED.</div>
        @else
        <div class="status-banner status-declined">&#10007;&nbsp; Your leave request has been REJECTED.</div>
        @endif

        <div class="card">
            <div class="card-title">Leave Request Details</div>
            <ul>
                <li><strong>Employee No:</strong> {{ $employee->EmpNo ?? 'N/A' }}</li>
                <li><strong>Name:</strong> {{ trim(($employee->first_name ?? '') . ' ' . ($employee->middle_name ?? '') . ' ' . ($employee->last_name ?? '')) }}</li>
                <li><strong>Department:</strong> {{ $employee->department_name ?? 'N/A' }}</li>
                <li><strong>Leave Type:</strong> {{ $leave->leave_type ?? 'N/A' }}</li>
                <li><strong>Date Filed:</strong> {{ $formatted['filed'] ?? \Carbon\Carbon::parse($leave->created_at)->format('l, F j, Y') }}</li>
                <li><strong>Leave Start Date:</strong> {{ $formatted['start'] ?? \Carbon\Carbon::parse($leave->start_date)->format('l, F j, Y') }}</li>
                <li><strong>Leave End Date:</strong> {{ $formatted['end'] ?? \Carbon\Carbon::parse($leave->end_date)->format('l, F j, Y') }}</li>
                @if(!empty($leave->total_days))
                <li><strong>Total Days:</strong> {{ $leave->total_days }}</li>
                @endif
                <li><strong>Status:</strong>
                    @if($action === 'approved')
                        <span style="color:#15803d;font-weight:bold;">Approved</span>
                    @else
                        <span style="color:#b91c1c;font-weight:bold;">Rejected</span>
                    @endif
                </li>
            </ul>
        </div>

        @if($action === 'declined' && !empty($notes))
        <div class="notes-box">
            <strong>Rejection Remarks:</strong><br/>
            {{ $notes }}
        </div>
        @endif

        <div class="card">
            <div class="card-title">Leave Balance</div>
            <table class="balance">
                <thead>
                    <tr>
                        <th>Leave Type</th>
                        <th class="right">Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Vacation Leave (VL)</td>
                        <td class="right">{{ number_format($balances['VL'] ?? 0, 3) }}</td>
                    </tr>
                    <tr>
                        <td>Sick Leave (SL)</td>
                        <td class="right">{{ number_format($balances['SL'] ?? 0, 0) }}</td>
                    </tr>
                    <tr>
                        <td>Wellness Leave (WLNS)</td>
                        <td class="right">{{ number_format($balances['WLNS'] ?? 0, 0) }}</td>
                    </tr>
                    <tr>
                        <td>Special Privilege Leave (SPL)</td>
                        <td class="right">{{ number_format($balances['SPL'] ?? 0, 0) }}</td>
                    </tr>
                    <tr>
                        <td>Compensatory Time Off (CTO)</td>
                        <td class="right">{{ number_format($balances['CTO'] ?? 0, 0) }}</td>
                    </tr>
                    <tr>
                        <td>Solo Parent (SPRNT)</td>
                        <td class="right">{{ number_format($balances['SP'] ?? 0, 0) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="hr"></div>
        <p style="font-size:14px;">If you have questions, please contact your department head or HR office.</p>
        <p style="font-size:14px;">Regards,<br/><strong>HRIS Team</strong></p>
        <p class="muted">This is an automated notification. Please do not reply to this email.</p>

    </div>
</body>
</html>
