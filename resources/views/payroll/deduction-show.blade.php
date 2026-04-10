@extends('dashboards.layout', [
    'title' => $deduction->type,
    'subtitle' => 'Deduction type details.',
])

@section('content')
    <div class="detail-card">
        <div class="detail-row"><strong>Type:</strong> {{ $deduction->type }}</div>
        <div class="detail-row"><strong>Description:</strong> {{ $deduction->description ?? '—' }}</div>
        <div class="detail-row"><strong>Formula:</strong> {{ $deduction->formula ?? '—' }}</div>
    </div>

    <section class="payroll-section">
        <h2>Employee Deductions</h2>
        @if($deduction->employeeDeductions->count())
            <table class="payroll-table">
                <thead><tr><th>Employee</th><th>Amount</th><th>Recurring</th></tr></thead>
                <tbody>
                    @foreach($deduction->employeeDeductions as $ed)
                        <tr>
                            <td>{{ $ed->employee->name ?? '—' }}</td>
                            <td>₱{{ number_format($ed->amount, 2) }}</td>
                            <td>{{ $ed->recurring ? 'Yes' : 'No' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-state">No employee deductions.</p>
        @endif
    </section>

    <section class="payroll-section">
        <h2>Active Loans</h2>
        @if($deduction->loans->count())
            <table class="payroll-table">
                <thead><tr><th>Employee</th><th>Balance</th><th>Monthly</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach($deduction->loans as $loan)
                        <tr>
                            <td>{{ $loan->employee->name ?? '—' }}</td>
                            <td>₱{{ number_format($loan->balance, 2) }}</td>
                            <td>₱{{ number_format($loan->monthly_payment, 2) }}</td>
                            <td><span class="status-chip status-{{ $loan->status }}">{{ ucfirst($loan->status) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-state">No active loans under this deduction.</p>
        @endif
    </section>

    <div class="form-actions">
        <a href="{{ route('payroll.deductions.index') }}" class="btn btn-sm btn-outline">Back</a>
    </div>
@endsection
