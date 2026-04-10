@extends('dashboards.layout', [
    'title' => 'My Attendance Logs',
    'subtitle' => 'View your daily time records.',
])

@section('content')
<div style="overflow-x:auto;">
    <table class="payroll-table" style="width:100%; border-collapse:collapse;">
        <thead>
            <tr>
                <th style="padding:10px 12px; text-align:left; border-bottom:2px solid #e2e8f0;">Date</th>
                <th style="padding:10px 12px; text-align:center; border-bottom:2px solid #e2e8f0;">AM In</th>
                <th style="padding:10px 12px; text-align:center; border-bottom:2px solid #e2e8f0;">AM Out</th>
                <th style="padding:10px 12px; text-align:center; border-bottom:2px solid #e2e8f0;">PM In</th>
                <th style="padding:10px 12px; text-align:center; border-bottom:2px solid #e2e8f0;">PM Out</th>
                <th style="padding:10px 12px; text-align:center; border-bottom:2px solid #e2e8f0;">Late (min)</th>
                <th style="padding:10px 12px; text-align:center; border-bottom:2px solid #e2e8f0;">Undertime (min)</th>
                <th style="padding:10px 12px; text-align:center; border-bottom:2px solid #e2e8f0;">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
            <tr>
                <td style="padding:10px 12px; border-bottom:1px solid #f1f5f9;">{{ \Carbon\Carbon::parse($record->date)->format('M d, Y (D)') }}</td>
                <td style="padding:10px 12px; text-align:center; border-bottom:1px solid #f1f5f9;">{{ $record->time_in_am ?? '—' }}</td>
                <td style="padding:10px 12px; text-align:center; border-bottom:1px solid #f1f5f9;">{{ $record->time_out_am ?? '—' }}</td>
                <td style="padding:10px 12px; text-align:center; border-bottom:1px solid #f1f5f9;">{{ $record->time_in_pm ?? '—' }}</td>
                <td style="padding:10px 12px; text-align:center; border-bottom:1px solid #f1f5f9;">{{ $record->time_out_pm ?? '—' }}</td>
                <td style="padding:10px 12px; text-align:center; border-bottom:1px solid #f1f5f9;">{{ $record->late_minutes ?? 0 }}</td>
                <td style="padding:10px 12px; text-align:center; border-bottom:1px solid #f1f5f9;">{{ $record->undertime_minutes ?? 0 }}</td>
                <td style="padding:10px 12px; text-align:center; border-bottom:1px solid #f1f5f9;">
                    @if ($record->is_absent)
                        <span class="badge-rejected">Absent</span>
                    @else
                        <span class="badge-approved">Present</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" style="padding:20px; text-align:center; color:#94a3b8;">No attendance records found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:16px;">
    {{ $records->links() }}
</div>
@endsection
