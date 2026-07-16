@extends('dashboards.layout', ['title' => 'Attendance Adjustment Summary - Print', 'subtitle' => 'Print preview'])

@section('content')
<div style="max-width:1100px;margin:24px auto;font-family:sans-serif">
    <h2 style="text-align:center;margin-bottom:4px;">Attendance Adjustment Summary</h2>
    <p style="text-align:center;color:#475569;margin-top:0;">
        {{ $departmentLabel }} &middot; {{ \Carbon\Carbon::createFromDate($year, $month, 1)->format('F Y') }}
    </p>

    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
            <tr>
                <th style="border:1px solid #ddd;padding:6px;">#</th>
                <th style="border:1px solid #ddd;padding:6px;">Employee No.</th>
                <th style="border:1px solid #ddd;padding:6px;text-align:left;">Name</th>
                <th style="border:1px solid #ddd;padding:6px;text-align:left;">Department</th>
                <th style="border:1px solid #ddd;padding:6px;text-align:left;">Position</th>
                <th style="border:1px solid #ddd;padding:6px;">Employee Type</th>
                <th style="border:1px solid #ddd;padding:6px;">Unfiled Leave</th>
                <th style="border:1px solid #ddd;padding:6px;">Tardiness (Count)</th>
                <th style="border:1px solid #ddd;padding:6px;">Tardiness (Min)</th>
                <th style="border:1px solid #ddd;padding:6px;">Undertime (Count)</th>
                <th style="border:1px solid #ddd;padding:6px;">Undertime (Min)</th>
                <th style="border:1px solid #ddd;padding:6px;">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $i => $row)
                <tr>
                    <td style="border:1px solid #ddd;padding:6px;text-align:center;">{{ $i + 1 }}</td>
                    <td style="border:1px solid #ddd;padding:6px;text-align:center;">{{ $row['emp_no'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;">{{ $row['name'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;">{{ $row['department'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;">{{ $row['position'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;text-align:center;">{{ $row['employee_type'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;text-align:center;">{{ $row['unfiled_count'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;text-align:center;">{{ $row['tardiness_count'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;text-align:center;">{{ $row['tardiness_minutes'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;text-align:center;">{{ $row['undertime_count'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;text-align:center;">{{ $row['undertime_minutes'] }}</td>
                    <td style="border:1px solid #ddd;padding:6px;text-align:center;">{{ $row['status'] }}</td>
                </tr>
            @empty
                <tr><td colspan="12" style="border:1px solid #ddd;padding:12px;text-align:center;">No records for the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div style="margin-top:16px;text-align:right">
        <button onclick="window.print()" style="padding:8px 12px;border-radius:6px">Print</button>
    </div>
</div>
@endsection
