@extends('dashboards.layout', [
    'title' => 'Audit Logs',
    'subtitle' => 'Full audit trail of payroll actions.',
])

@section('top_actions')
    <form method="GET" action="{{ route('payroll.audit-logs.index') }}" class="filter-form" style="display:inline-flex;gap:8px;">
        <select name="action" class="form-input">
            <option value="">All Actions</option>
            @foreach($actions as $a)
                <option value="{{ $a }}" @selected(request('action') == $a)>{{ $a }}</option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-sm">Filter</button>
    </form>
@endsection

@section('content')
    <x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$logs">
        <table class="hris-table" id="audit-table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>User</th>
                    <th>Run ID</th>
                    <th>Details</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr id="audit-row-{{ $log->id }}"
                        data-action="{{ $log->action }}"
                        data-user="{{ $log->user->name ?? '-' }}"
                        data-run="{{ $log->payroll_run_id ? 'Run #' . $log->payroll_run_id . ' - ' . ($log->payrollRun->period ?? '') : '-' }}"
                        data-details="{{ $log->details ?? '-' }}"
                        data-date="{{ $log->created_at->format('M d, Y H:i:s') }}">
                        <td><span class="status-chip">{{ $log->action }}</span></td>
                        <td>{{ $log->user->name ?? '-' }}</td>
                        <td>{{ $log->payroll_run_id ?? '-' }}</td>
                        <td>{{ Str::limit($log->details, 80) }}</td>
                        <td>{{ $log->created_at->format('M d, Y H:i') }}</td>
                        <td>
                            <div class="action-btns">
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openShowAudit({{ $log->id }})">View</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted">No audit logs recorded.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-hris.table-layout>
@endsection

@section('modals')
<dialog id="showAuditModal" class="employee-modal">
    <div class="modal-icon-header">
        <div class="modal-icon-heading">
            <span class="modal-icon-badge"><i class="fas fa-clock-rotate-left"></i></span>
            <div><h3>Audit Log Details</h3></div>
        </div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <div id="showAuditBody" style="margin-top:12px"></div>
    <form method="dialog" class="form-actions" style="margin-top:12px;text-align:right">
        <button class="btn btn-outline" type="submit">Close</button>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openShowAudit(id) {
    var row = document.getElementById('audit-row-' + id);
    if (!row) return;
    document.getElementById('showAuditBody').innerHTML =
        '<table style="width:100%;border-collapse:collapse"><tbody>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Action</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.action + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>User</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.user + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Payroll Run</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.run + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Details</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.details + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Date</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.date + '</td></tr>' +
        '</tbody></table>';
    document.getElementById('showAuditModal').showModal();
}
</script>
@endsection
