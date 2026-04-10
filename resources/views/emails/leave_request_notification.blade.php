<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; color:#1f2937; }
        .header { background:#0f172a; color:#fff; padding:16px; }
        .container { padding:20px; }
        .card { border:1px solid #e5e7eb; padding:16px; border-radius:6px; }
        .muted { color:#6b7280; }
        .hr { border-top:1px solid #e5e7eb; margin:16px 0; }
    </style>
    <title>New Leave Request</title>
</head>
<body>
    <div class="header">
        <strong>HRIS — Leave Request</strong>
    </div>
    <div class="container">
        <p>Dear {{ $employee->dept_head_name ?? 'Department Head' }},</p>

        <p><strong>{{ trim(($employee->first_name ?? '') . ' ' . ($employee->middle_name ?? '') . ' ' . ($employee->last_name ?? '')) }}</strong> from your department has filed a new Leave Request.</p>

        <div class="card">
            <p><strong>Details:</strong></p>
            <ul>
                <li><strong>Employee No:</strong> {{ $employee->EmpNo ?? '' }}</li>
                <li><strong>Name:</strong> {{ trim(($employee->first_name ?? '') . ' ' . ($employee->middle_name ?? '') . ' ' . ($employee->last_name ?? '')) }}</li>
                <li><strong>Department:</strong> {{ $employee->department_name ?? ($employee->Dept_id ?? '') }}</li>
                <li><strong>Leave Type:</strong> {{ $leave->leave_type ?? '' }}</li>
                <li><strong>Date Filed:</strong> {{ $formatted['filed'] ?? (\Carbon\Carbon::parse($leave->created_at)->format('l, F j, Y') ?? '') }}</li>
                <li><strong>Leave Start Date:</strong> {{ $formatted['start'] ?? (\Carbon\Carbon::parse($leave->start_date)->format('l, F j, Y') ?? '') }}</li>
                <li><strong>Leave End Date:</strong> {{ $formatted['end'] ?? (\Carbon\Carbon::parse($leave->end_date)->format('l, F j, Y') ?? '') }}</li>
                <li><strong>Reason / Remarks:</strong> {{ $leave->reason ?? '' }}</li>
            </ul>
        </div>

        <div class="card" style="margin-top:12px;">
            <p><strong>Leave Balance</strong></p>
            <table style="width:100%;border-collapse:collapse;border:1px solid #d1d5db;">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:8px;border:1px solid #d1d5db;background:#f9fafb;">Leave Type</th>
                        <th style="text-align:right;padding:8px;border:1px solid #d1d5db;background:#f9fafb;">Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:8px;border:1px solid #d1d5db;">Vacation Leave (VL)</td>
                        <td style="padding:8px;border:1px solid #d1d5db;text-align:right;">{{ number_format($leave->balance_vacation_leave ?? ($employee->leaveBalance->VL ?? 0), 3) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #d1d5db;">Sick Leave (SL)</td>
                        <td style="padding:8px;border:1px solid #d1d5db;text-align:right;">{{ number_format($leave->balance_sick_leave ?? ($employee->leaveBalance->SL ?? 0), 0) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #d1d5db;">Wellness Leave (WLNS)</td>
                        <td style="padding:8px;border:1px solid #d1d5db;text-align:right;">{{ number_format($leave->balance_wellness_leave ?? ($employee->leaveBalance->WLNS ?? 0), 0) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #d1d5db;">Special Privilege Leave (SPL)</td>
                        <td style="padding:8px;border:1px solid #d1d5db;text-align:right;">{{ number_format($leave->balance_special_leave_privilege ?? ($employee->leaveBalance->SPL ?? 0), 0) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #d1d5db;">Compensatory Time Off (CTO)</td>
                        <td style="padding:8px;border:1px solid #d1d5db;text-align:right;">{{ number_format($employee->leaveBalance->CTO ?? 0, 0) }}</td>
                    </tr>
                    <tr>
                        <td style="padding:8px;border:1px solid #d1d5db;">Solo Parent (SPRNT)</td>
                        <td style="padding:8px;border:1px solid #d1d5db;text-align:right;">{{ number_format($leave->balance_solo_parent_leave ?? ($employee->leaveBalance->SP ?? 0), 0) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="hr"></div>
        <p class="muted">This is an automated notification from the HRIS system.</p>
    </div>
</body>
</html>
