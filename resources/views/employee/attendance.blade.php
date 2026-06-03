@extends('dashboards.layout', [
    'title' => 'My Attendance Logs',
    'subtitle' => 'View your daily time records.',
])

@section('content')
<x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$records">
    <table class="hris-table">
        <thead>
            <tr>
                <th>Date</th>
                <th class="text-center">AM In</th>
                <th class="text-center">AM Out</th>
                <th class="text-center">PM In</th>
                <th class="text-center">PM Out</th>
                <th class="text-center">Late (min)</th>
                <th class="text-center">Undertime (min)</th>
                <th class="text-center">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($records as $record)
            <tr>
                <td>{{ \Carbon\Carbon::parse($record->date)->format('M d, Y (D)') }}</td>
                <td class="text-center">{{ $record->time_in_am ?? '—' }}</td>
                <td class="text-center">{{ $record->time_out_am ?? '—' }}</td>
                <td class="text-center">{{ $record->time_in_pm ?? '—' }}</td>
                <td class="text-center">{{ $record->time_out_pm ?? '—' }}</td>
                <td class="text-center">{{ $record->late_minutes ?? 0 }}</td>
                <td class="text-center">{{ $record->undertime_minutes ?? 0 }}</td>
                <td class="text-center">
                    @if ($record->is_absent)
                        <span class="hris-badge badge-rejected">Absent</span>
                    @else
                        <span class="hris-badge badge-approved">Present</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="text-center text-muted">No attendance records found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</x-hris.table-layout>
@endsection
