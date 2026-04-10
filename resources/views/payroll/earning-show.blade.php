@extends('dashboards.layout', [
    'title' => $earning->type,
    'subtitle' => 'Earning type details and employee assignments.',
])

@section('content')
    <div class="detail-card">
        <div class="detail-row"><strong>Type:</strong> {{ $earning->type }}</div>
        <div class="detail-row"><strong>Description:</strong> {{ $earning->description ?? '—' }}</div>
        <div class="detail-row"><strong>Recurring:</strong> {{ $earning->recurring ? 'Yes' : 'No' }}</div>
    </div>

    <section class="payroll-section">
        <h2>Assigned Employees</h2>
        @if($earning->employeeEarnings->count())
            <table class="payroll-table">
                <thead><tr><th>Employee</th><th>Amount</th><th>Recurring</th></tr></thead>
                <tbody>
                    @foreach($earning->employeeEarnings as $ee)
                        <tr>
                            <td>{{ $ee->employee->name ?? '—' }}</td>
                            <td>₱{{ number_format($ee->amount, 2) }}</td>
                            <td>{{ $ee->recurring ? 'Yes' : 'No' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-state">No employees assigned to this earning type.</p>
        @endif
    </section>

    <div class="form-actions">
        <a href="{{ route('payroll.earnings.index') }}" class="btn btn-sm btn-outline">Back</a>
    </div>
@endsection
