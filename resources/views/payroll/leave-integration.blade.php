@extends('dashboards.layout', [
    'title' => 'Leave Integration',
    'subtitle' => 'Approved leave requests synced from Leave Manager (read-only).',
])

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    <div class="payroll-filters">
        <form method="GET" action="{{ route('payroll.leave-integration.index') }}" class="filter-form">
            <select name="employee_id" class="form-input">
                <option value="">All Employees</option>
                @foreach($employees as $emp)
                    <option value="{{ $emp->id }}" @selected(request('employee_id') == $emp->id)>{{ $emp->name }}</option>
                @endforeach
            </select>
            <select name="leave_type" class="form-input">
                <option value="">All Types</option>
                @foreach(['Vacation Leave','Sick Leave','Special Privilege Leave','Maternity Leave','Paternity Leave','Solo Parent Leave','LWOP'] as $t)
                    <option value="{{ $t }}" @selected(request('leave_type') == $t)>{{ $t }}</option>
                @endforeach
            </select>
            <label class="checkbox-label" style="display:inline-flex;align-items:center;gap:4px;">
                <input type="checkbox" name="lwop_only" value="1" @checked(request('lwop_only'))> LWOP Only
            </label>
            <button type="submit" class="btn btn-sm">Filter</button>
        </form>
    </div>

    <table class="payroll-table" id="leave-int-table">
        <thead>
            <tr>
                <th>Employee</th>
                <th>Leave Type</th>
                <th>Start</th>
                <th>End</th>
                <th>Total Days</th>
                <th>Paid Days</th>
                <th>LWOP Days</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($records as $rec)
                <tr>
                    <td>{{ $rec->user->name ?? '—' }}</td>
                    <td>{{ $rec->leave_type }}</td>
                    <td>{{ $rec->start_date ? \Carbon\Carbon::parse($rec->start_date)->format('M d, Y') : '—' }}</td>
                    <td>{{ $rec->end_date ? \Carbon\Carbon::parse($rec->end_date)->format('M d, Y') : '—' }}</td>
                    <td>{{ $rec->total_days ?? '—' }}</td>
                    <td>{{ $rec->paid_days ?? '—' }}</td>
                    <td>
                        @if($rec->lwop_days > 0)
                            <span class="status-chip status-rejected">{{ $rec->lwop_days }}</span>
                        @else
                            <span class="status-chip status-approved">0</span>
                        @endif
                    </td>
                    <td><span class="status-chip status-approved">Approved</span></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $records->appends(request()->query())->links() }}

    <p style="margin-top:12px;color:#64748b;font-size:0.85rem;">
        <i class="fas fa-info-circle"></i> Leave records are automatically synced from the Leave Manager module. Only approved requests are displayed.
    </p>
@endsection

@section('page_scripts_after')
<script>
    $(function () {
        $('#leave-int-table').DataTable({ paging: false, info: false, language: { emptyTable: 'No approved leave requests found.' } });
    });
</script>
@endsection
