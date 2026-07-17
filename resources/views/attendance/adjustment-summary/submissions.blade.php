@extends('dashboards.layout', [
    'title' => 'Attendance Adjustment Submissions',
    'subtitle' => 'History of attendance adjustment reports forwarded to the Leave Manager.',
])

@section('content')
<div class="hris-table-card">
    <div class="hris-table-header">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title"><i class="fas fa-paper-plane" style="color:#1d4ed8;margin-right:.5rem;"></i>Submission History</h2>
            <p class="hris-table-subtitle">Record of past Attendance Adjustment Summary submissions and the Leave Manager's review outcome per employee.</p>
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
                    <th>Reviewed</th>
                    <th style="width:110px">Details</th>
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
                        <td style="font-size:0.8rem;white-space:nowrap;">
                            @if($s->processed_count > 0)
                                <span style="color:#15803d;font-weight:600;">{{ $s->processed_count }} deducted</span>
                            @endif
                            @if($s->dismissed_count > 0)
                                <span style="color:#dc2626;font-weight:600;">{{ $s->processed_count > 0 ? ', ' : '' }}{{ $s->dismissed_count }} dismissed</span>
                            @endif
                            @if($s->pending_count > 0)
                                <span style="color:#94a3b8;">{{ ($s->processed_count > 0 || $s->dismissed_count > 0) ? ', ' : '' }}{{ $s->pending_count }} pending</span>
                            @endif
                            @if($s->processed_count == 0 && $s->dismissed_count == 0 && $s->pending_count == 0)
                                <span style="color:#d1d5db;">&mdash;</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <button class="hris-btn hris-btn-secondary hris-btn-sm view-items-btn" data-id="{{ $s->id }}"
                                data-period="{{ \Carbon\Carbon::createFromDate($s->year, $s->month, 1)->format('F Y') }}">
                                <i class="fa-solid fa-list"></i> View
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
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

@section('modals')
<dialog id="items-modal" class="employee-modal" style="max-width:720px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title">
            <i class="fa-solid fa-list" style="color:#1d4ed8;margin-right:6px;"></i>
            Submission Items <span id="items-modal-period" style="color:#6b7280;font-weight:400;font-size:0.85rem;"></span>
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body" style="max-height:60vh;overflow:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
                <tr style="text-align:left;border-bottom:1px solid #e5e7eb;">
                    <th style="padding:6px 8px;">Employee</th>
                    <th style="padding:6px 8px;">Status</th>
                    <th style="padding:6px 8px;">Details</th>
                </tr>
            </thead>
            <tbody id="items-modal-body"></tbody>
        </table>
    </div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Close</button></form>
    </div>
</dialog>
@endsection

@section('page_scripts_after')
<script>
(function($){
    function escapeHtml(str) {
        if (!str && str !== 0) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    var statusBadges = {
        pending:   { bg:'#f1f5f9', color:'#475569', border:'#cbd5e1', label:'Pending' },
        processed: { bg:'#f0fdf4', color:'#166534', border:'#86efac', label:'Deducted' },
        dismissed: { bg:'#fef2f2', color:'#991b1b', border:'#fca5a5', label:'Dismissed' },
    };

    function statusBadge(status) {
        var b = statusBadges[status] || statusBadges.pending;
        return '<span style="background:'+b.bg+';color:'+b.color+';border:1px solid '+b.border+';padding:2px 8px;border-radius:12px;font-size:0.75rem;font-weight:700;white-space:nowrap;">'+b.label+'</span>';
    }

    function detailsHtml(item) {
        if (item.processed_status === 'processed') {
            return 'Deducted <strong>' + escapeHtml(item.deducted_days) + '</strong> VL day(s)'
                + (item.processed_by ? ' by ' + escapeHtml(item.processed_by) : '')
                + (item.processed_at ? '<br><span style="color:#94a3b8;font-size:0.78rem;">' + escapeHtml(item.processed_at) + '</span>' : '');
        }
        if (item.processed_status === 'dismissed') {
            return escapeHtml(item.action_remarks || '-')
                + (item.processed_by ? '<br><span style="color:#94a3b8;font-size:0.78rem;">by ' + escapeHtml(item.processed_by) + (item.processed_at ? ' on ' + escapeHtml(item.processed_at) : '') + '</span>' : '');
        }
        return '<span style="color:#d1d5db;">Awaiting Leave Manager review</span>';
    }

    $(document).on('click', '.view-items-btn', function(){
        var id = $(this).data('id');
        $('#items-modal-period').text('- ' + $(this).data('period'));
        $('#items-modal-body').html('<tr><td colspan="3" style="padding:16px;text-align:center;color:#94a3b8;">Loading...</td></tr>');
        document.getElementById('items-modal').showModal();

        $.getJSON('{{ url('/attendance/adjustment-summary/submissions') }}/' + id + '/items')
            .done(function(resp){
                var items = (resp && resp.items) || [];
                if (!items.length) {
                    $('#items-modal-body').html('<tr><td colspan="3" style="padding:16px;text-align:center;color:#94a3b8;">No items in this submission.</td></tr>');
                    return;
                }
                var rows = items.map(function(item){
                    return '<tr style="border-bottom:1px solid #f1f5f9;">' +
                        '<td style="padding:8px;"><div style="font-weight:600;">' + escapeHtml(item.name) + '</div>' +
                        '<div style="font-size:0.75rem;color:#94a3b8;">' + escapeHtml(item.emp_no) + (item.department ? ' &middot; ' + escapeHtml(item.department) : '') + '</div></td>' +
                        '<td style="padding:8px;vertical-align:top;">' + statusBadge(item.processed_status) + '</td>' +
                        '<td style="padding:8px;vertical-align:top;">' + detailsHtml(item) + '</td>' +
                        '</tr>';
                });
                $('#items-modal-body').html(rows.join(''));
            })
            .fail(function(){
                $('#items-modal-body').html('<tr><td colspan="3" style="padding:16px;text-align:center;color:#991b1b;">Failed to load items.</td></tr>');
            });
    });
})(jQuery);
</script>
@endsection
