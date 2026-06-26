@extends('dashboards.layout', [
    'title' => 'Payroll Manager Dashboard',
    'subtitle' => 'Monitor payroll cycles, exceptions, and recent activity.',
])

@section('tiles')
    <article class="tile metric-tile">
        <span class="metric-label"><i class="fas fa-receipt"></i> Total Runs</span>
        <strong>{{ $totalRuns }}</strong>
        <small>All-time payroll runs</small>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label"><i class="fas fa-edit"></i> Draft / Pending</span>
        <strong>{{ $pendingRuns }}</strong>
        <small>Awaiting computation</small>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label"><i class="fas fa-lock"></i> Locked</span>
        <strong>{{ $lockedRuns }}</strong>
        <small>Finalized payroll runs</small>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label"><i class="fas fa-exclamation-triangle"></i> Exceptions</span>
        <strong>{{ $unresolvedExceptions }}</strong>
        <small>Unresolved issues</small>
    </article>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <section class="payroll-section">
        <div class="section-header">
            <h2>Recent Payroll Runs</h2>
            <a href="{{ route('payroll.runs.create') }}" class="btn btn-sm"><i class="fas fa-plus"></i> New Run</a>
        </div>

        @if($recentRuns->count())
            <div class="hris-table-wrapper">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Period</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentRuns as $run)
                        <tr>
                            <td>{{ $run->id }}</td>
                            <td>{{ $run->period }}</td>
                            <td><span class="status-chip status-{{ $run->status }}">{{ ucfirst($run->status) }}</span></td>
                            <td>{{ $run->creator->name ?? '-' }}</td>
                            <td>{{ $run->created_at->format('M d, Y') }}</td>
                            <td><a href="{{ route('payroll.runs.show', $run->id) }}" class="btn btn-sm btn-outline">View</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="empty-state">No payroll runs yet. <a href="{{ route('payroll.runs.create') }}">Create one</a>.</p>
        @endif
    </section>

    <section class="payroll-section">
        <h2>Recent Audit Activity</h2>
        @if($recentAudit->count())
            <div class="hris-table-wrapper">
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>User</th>
                        <th>Run ID</th>
                        <th>Details</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentAudit as $log)
                        <tr>
                            <td><span class="status-chip">{{ $log->action }}</span></td>
                            <td>{{ $log->user->name ?? '-' }}</td>
                            <td>{{ $log->payroll_run_id ?? '-' }}</td>
                            <td>{{ str($log->details)->limit(60) }}</td>
                            <td>{{ $log->created_at->format('M d, Y H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @else
            <p class="empty-state">No audit activity recorded yet.</p>
        @endif
    </section>
@endsection
