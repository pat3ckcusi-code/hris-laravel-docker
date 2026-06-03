@extends('dashboards.layout', [
    'title' => 'Approvals',
    'subtitle' => 'Review and approve/reject payroll runs.',
])

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    @if($pendingRuns->count())
        <section class="payroll-section">
            <h2>Pending Approval</h2>
            <table class="hris-table">
                <thead>
                    <tr>
                        <th>Run ID</th>
                        <th>Period</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pendingRuns as $run)
                        <tr>
                            <td>#{{ $run->id }}</td>
                            <td>{{ $run->period }}</td>
                            <td><span class="status-chip status-{{ $run->status }}">{{ ucfirst($run->status) }}</span></td>
                            <td>
                                <div class="action-btns">
                                    <form method="POST" action="{{ route('payroll.approvals.store') }}" style="display:inline" id="approve-form-{{ $run->id }}">
                                        @csrf
                                        <input type="hidden" name="payroll_run_id" value="{{ $run->id }}">
                                        <input type="hidden" name="status" value="approved">
                                        <button type="button" class="btn btn-sm" onclick="confirmApproval({{ $run->id }}, 'approve')"><i class="fas fa-check"></i> Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('payroll.approvals.store') }}" style="display:inline" id="reject-form-{{ $run->id }}">
                                        @csrf
                                        <input type="hidden" name="payroll_run_id" value="{{ $run->id }}">
                                        <input type="hidden" name="status" value="rejected">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="confirmApproval({{ $run->id }}, 'reject')"><i class="fas fa-times"></i> Reject</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <section class="payroll-section">
        <h2>Approval History</h2>
        <table class="hris-table" id="approvals-table">
            <thead>
                <tr>
                    <th>Run</th>
                    <th>Approver</th>
                    <th>Decision</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                    <tr id="approval-row-{{ $log->id }}"
                        data-run="#{{ $log->payroll_run_id }} — {{ $log->payrollRun->period ?? '' }}"
                        data-approver="{{ $log->approver->name ?? '—' }}"
                        data-status="{{ $log->status }}"
                        data-date="{{ $log->actioned_at ? $log->actioned_at->format('M d, Y H:i') : '—' }}">
                        <td>Run #{{ $log->payroll_run_id }} — {{ $log->payrollRun->period ?? '' }}</td>
                        <td>{{ $log->approver->name ?? '—' }}</td>
                        <td><span class="status-chip status-{{ $log->status }}">{{ ucfirst($log->status) }}</span></td>
                        <td>{{ $log->actioned_at ? $log->actioned_at->format('M d, Y H:i') : '—' }}</td>
                        <td><button type="button" class="btn btn-sm btn-outline" onclick="openShowApproval({{ $log->id }})">View</button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-state">No approval history.</td></tr>
                @endforelse
            </tbody>
        </table>

        {{ $logs->links() }}
    </section>
@endsection

@section('modals')
<dialog id="showApprovalModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div><h3 style="margin:0">Approval Log Details</h3></div>
        <form method="dialog"><button type="submit" class="modal-close" aria-label="Close">x</button></form>
    </div>
    <div id="showApprovalBody" style="margin-top:12px"></div>
    <form method="dialog" class="form-actions" style="margin-top:12px;text-align:right">
        <button class="btn btn-outline" type="submit">Close</button>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function confirmApproval(runId, action) {
    var title = action === 'approve' ? 'Approve this payroll run?' : 'Reject this payroll run?';
    var formId = action === 'approve' ? 'approve-form-' + runId : 'reject-form-' + runId;
    if (typeof Swal !== 'undefined') {
        Swal.fire({ title: title, icon: 'question', showCancelButton: true, confirmButtonText: 'Yes' })
            .then(function(r) { if (r.isConfirmed) document.getElementById(formId).submit(); });
    } else if (confirm(title)) {
        document.getElementById(formId).submit();
    }
}
function openShowApproval(id) {
    var row = document.getElementById('approval-row-' + id);
    if (!row) return;
    var status = row.dataset.status.charAt(0).toUpperCase() + row.dataset.status.slice(1);
    document.getElementById('showApprovalBody').innerHTML =
        '<table style="width:100%;border-collapse:collapse"><tbody>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Payroll Run</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.run + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Approver</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.approver + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Decision</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + status + '</td></tr>' +
        '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Date</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row.dataset.date + '</td></tr>' +
        '</tbody></table>';
    document.getElementById('showApprovalModal').showModal();
}
</script>
@endsection
