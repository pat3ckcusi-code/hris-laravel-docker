@extends('dashboards.layout', [
    'title' => 'Attendance Adjustment Submissions',
    'subtitle' => 'History of attendance adjustment reports forwarded to the Leave Manager.',
])

@section('content')
<div class="hris-table-card">
    <div class="hris-table-header">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title"><i class="fas fa-paper-plane" style="color:#1d4ed8;margin-right:.5rem;"></i>Submission History</h2>
            <p class="hris-table-subtitle">Read-only record of past Attendance Adjustment Summary submissions.</p>
        </div>
    </div>

    <div class="hris-table-wrapper">
        <table class="hris-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th style="text-align:left;">Scope</th>
                    <th style="text-align:left;">Submitted By</th>
                    <th>Submitted At</th>
                    <th>Submitted</th>
                    <th>Skipped</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($submissions as $s)
                    <tr>
                        <td>{{ \Carbon\Carbon::createFromDate($s->year, $s->month, 1)->format('F Y') }}</td>
                        <td style="text-align:left;">
                            {{ $s->department_label ?: 'All Departments' }}
                            @if ($s->employee_type)
                                &mdash; {{ $s->employee_type }}
                            @endif
                        </td>
                        <td style="text-align:left;">{{ $s->submittedBy->name ?? '-' }}</td>
                        <td>{{ $s->created_at->format('M d, Y g:i A') }}</td>
                        <td>{{ $s->item_count }}</td>
                        <td>{{ $s->skipped_count }}</td>
                        <td>{{ ucfirst($s->status) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div class="hris-empty-state">
                                <div class="hris-empty-state-icon"><i class="fas fa-inbox"></i></div>
                                <div class="hris-empty-state-title">No Submissions Yet</div>
                                <p class="hris-empty-state-text">Submit an Attendance Adjustment Summary from the main page to see it here.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="padding:.75rem 1.25rem;">
        {{ $submissions->links() }}
    </div>
</div>
@endsection
