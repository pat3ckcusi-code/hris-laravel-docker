@extends('dashboards.layout', [
    'title' => 'Payroll Reports',
    'subtitle' => 'Summary reports per payroll run.',
])

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <div class="payroll-filters">
        <form method="GET" action="{{ route('payroll.reports.index') }}" class="filter-form">
            <select name="payroll_run_id" class="form-input">
                <option value="">Select Payroll Run</option>
                @foreach($runs as $run)
                    <option value="{{ $run->id }}" @selected(request('payroll_run_id') == $run->id)>Run #{{ $run->id }} — {{ $run->period }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-sm">View Report</button>
        </form>
    </div>

    @if($selectedRun && $summary)
        <section class="grid" style="margin-top:16px;">
            <article class="tile metric-tile">
                <span class="metric-label">Total Employees</span>
                <strong>{{ $summary['employee_count'] }}</strong>
            </article>
            <article class="tile metric-tile">
                <span class="metric-label">Total Basic</span>
                <strong>₱{{ number_format($summary['total_basic'], 2) }}</strong>
            </article>
            <article class="tile metric-tile">
                <span class="metric-label">Total Earnings</span>
                <strong>₱{{ number_format($summary['total_earnings'], 2) }}</strong>
            </article>
            <article class="tile metric-tile">
                <span class="metric-label">Total Deductions</span>
                <strong>₱{{ number_format($summary['total_deductions'], 2) }}</strong>
            </article>
            <article class="tile metric-tile">
                <span class="metric-label">Total Net Pay</span>
                <strong>₱{{ number_format($summary['total_net'], 2) }}</strong>
            </article>
        </section>

        <section class="payroll-section">
            <h2>Run #{{ $selectedRun->id }} — {{ $selectedRun->period }}</h2>
            <table class="payroll-table">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Basic Salary</th>
                        <th>Earnings</th>
                        <th>Deductions</th>
                        <th>Net Pay</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($selectedRun->details as $d)
                        <tr>
                            <td>{{ $d->employee->name ?? '—' }}</td>
                            <td>₱{{ number_format($d->basic_salary, 2) }}</td>
                            <td>₱{{ number_format($d->earnings, 2) }}</td>
                            <td>₱{{ number_format($d->deductions, 2) }}</td>
                            <td><strong>₱{{ number_format($d->net_pay, 2) }}</strong></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @else
        <p class="empty-state" style="margin-top:20px;">Select a payroll run above to view the summary report.</p>
    @endif
@endsection
