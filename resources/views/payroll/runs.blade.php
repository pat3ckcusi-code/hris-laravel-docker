@extends('dashboards.layout', [
    'title' => 'Payroll Runs',
    'subtitle' => 'Manage and monitor all payroll cycles.',
])

@section('top_actions')
    <button type="button" class="btn btn-sm" onclick="document.getElementById('createRunModal').showModal()"><i class="fas fa-plus"></i> New Payroll Run</button>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <table class="payroll-table" id="payroll-runs-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Period</th>
                <th>Start</th>
                <th>End</th>
                <th>Status</th>
                <th>Created By</th>
                <th>Approved By</th>
                <th>Locked At</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($runs as $run)
                <tr>
                    <td>{{ $run->id }}</td>
                    <td>{{ $run->period }}</td>
                    <td>{{ $run->period_start ? $run->period_start->format('M d, Y') : '—' }}</td>
                    <td>{{ $run->period_end ? $run->period_end->format('M d, Y') : '—' }}</td>
                    <td><span class="status-chip status-{{ $run->status }}">{{ ucfirst($run->status) }}</span></td>
                    <td>{{ $run->creator->name ?? '—' }}</td>
                    <td>{{ $run->approver->name ?? '—' }}</td>
                    <td>{{ $run->locked_at ? $run->locked_at->format('M d, Y H:i') : '—' }}</td>
                    <td>{{ $run->created_at->format('M d, Y') }}</td>
                    <td>
                        <a href="{{ route('payroll.runs.show', $run->id) }}" class="btn btn-sm btn-outline">View</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $runs->links() }}
@endsection

@section('modals')
<dialog id="createRunModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 style="margin:0">New Payroll Run</h3>
            <span class="record-email">Start a new payroll cycle</span>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>

    <form method="POST" action="{{ route('payroll.runs.store') }}" class="payroll-form" style="margin-top:12px">
        @csrf
        <div class="form-group">
            <label for="period">Pay Period</label>
            <input type="text" id="period" name="period" value="{{ old('period') }}" placeholder="e.g. April 1-15, 2026" required class="form-input">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="period_start">Period Start</label>
                <input type="date" id="period_start" name="period_start" value="{{ old('period_start') }}" required class="form-input">
            </div>
            <div class="form-group">
                <label for="period_end">Period End</label>
                <input type="date" id="period_end" name="period_end" value="{{ old('period_end') }}" required class="form-input">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn">Create Payroll Run</button>
            <button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Cancel</button>
        </div>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
    $(function () {
        $('#payroll-runs-table').DataTable({ paging: false, info: false });
    });
    @if ($errors->any())
        document.getElementById('createRunModal').showModal();
    @endif
</script>
@endsection
