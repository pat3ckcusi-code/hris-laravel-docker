@extends('dashboards.layout', [
    'title' => 'Payroll Run #' . $run->id,
    'subtitle' => 'Period: ' . $run->period . ($run->period_start ? ' (' . $run->period_start->format('M d') . ' – ' . $run->period_end->format('M d, Y') . ')' : ''),
])

@section('top_actions')
    <div class="header-actions">
        @if(!$run->locked_at)
            <form method="POST" action="{{ route('payroll.runs.compute', $run->id) }}" style="display:inline">
                @csrf
                <button type="submit" class="btn btn-sm"><i class="fas fa-calculator"></i> Compute</button>
            </form>
            <form method="POST" action="{{ route('payroll.runs.lock', $run->id) }}" style="display:inline" id="lock-form">
                @csrf
                <button type="button" class="btn btn-sm btn-danger" onclick="confirmLock()"><i class="fas fa-lock"></i> Lock</button>
            </form>
        @endif
        <a href="{{ route('payroll.runs.export', $run->id) }}" class="btn btn-sm btn-outline"><i class="fas fa-download"></i> Export</a>
        <a href="{{ route('payroll.runs.index') }}" class="btn btn-sm btn-outline">Back</a>
    </div>
@endsection

@section('tiles')
    <article class="tile metric-tile">
        <span class="metric-label">Status</span>
        <strong><span class="status-chip status-{{ $run->status }}">{{ ucfirst($run->status) }}</span></strong>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label">Employees</span>
        <strong>{{ $run->details->count() }}</strong>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label">Total Net Pay</span>
        <strong>₱{{ number_format($run->details->sum('net_pay'), 2) }}</strong>
    </article>
    <article class="tile metric-tile">
        <span class="metric-label">Exceptions</span>
        <strong>{{ $run->exceptions->where('resolved_flag', false)->count() }}</strong>
    </article>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <section class="payroll-section">
        <h2>Payroll Details</h2>
        @if($run->details->count())
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Days Worked</th>
                        <th>Late (min)</th>
                        <th>UT (min)</th>
                        <th>Absent</th>
                        <th>Basic Salary</th>
                        <th>Earnings</th>
                        <th>Deductions</th>
                        <th>LWOP Ded.</th>
                        <th>Loan Ded.</th>
                        <th>Net Pay</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($run->details as $detail)
                        <tr>
                            <td>{{ $detail->employee->name ?? '—' }}</td>
                            <td>{{ $detail->days_worked ?? '—' }}</td>
                            <td>{{ $detail->late_minutes ?? 0 }}</td>
                            <td>{{ $detail->undertime_minutes ?? 0 }}</td>
                            <td>{{ $detail->absent_days ?? 0 }}</td>
                            <td>₱{{ number_format($detail->basic_salary, 2) }}</td>
                            <td>₱{{ number_format($detail->earnings, 2) }}</td>
                            <td>₱{{ number_format($detail->deductions, 2) }}</td>
                            <td>₱{{ number_format($detail->lwop_deduction ?? 0, 2) }}</td>
                            <td>₱{{ number_format($detail->loan_deduction ?? 0, 2) }}</td>
                            <td><strong>₱{{ number_format($detail->net_pay, 2) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-state">No payroll details computed yet. Click <strong>Compute</strong> to generate.</p>
        @endif
    </section>

    <section class="payroll-section">
        <h2>Approval History</h2>
        @if($run->approvalLogs->count())
            <table class="hris-table">
                <thead><tr><th>Approver</th><th>Status</th><th>Date</th></tr></thead>
                <tbody>
                    @foreach($run->approvalLogs as $log)
                        <tr>
                            <td>{{ $log->approver->name ?? '—' }}</td>
                            <td><span class="status-chip status-{{ $log->status }}">{{ ucfirst($log->status) }}</span></td>
                            <td>{{ $log->actioned_at ? $log->actioned_at->format('M d, Y H:i') : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-state">No approval actions yet.</p>
        @endif
    </section>
@endsection

@section('page_scripts_after')
<script>
function confirmLock() {
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Lock this payroll run?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Lock',
        }).then((result) => { if (result.isConfirmed) document.getElementById('lock-form').submit(); });
    } else if (confirm('Lock this payroll run?')) {
        document.getElementById('lock-form').submit();
    }
}
</script>
@endsection
