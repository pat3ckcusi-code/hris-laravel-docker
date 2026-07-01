@extends('dashboards.layout', [
    'title'    => 'Leave Cancellation Endorsements',
    'subtitle' => 'Step 2 of 3 — Review DH-recommended requests and endorse to the Leave Manager',
])

@section('page_head')
    @include('partials.table-styles')
@endsection


@section('content')

{{-- Workflow banner --}}
<div style="display:flex;align-items:flex-start;gap:14px;background:#eff6ff;border:1px solid #bfdbfe;border-left:4px solid #3b82f6;border-radius:10px;padding:14px 18px;margin-bottom:24px;">
    <i class="fa-solid fa-circle-info" style="color:#3b82f6;font-size:1.2rem;margin-top:2px;flex-shrink:0;"></i>
    <div>
        <strong style="color:#1e3a8a;font-size:0.92rem;">3-Step Cancellation Workflow — You are Step 2</strong>
        <p style="margin:4px 0 0;font-size:0.875rem;color:#1e40af;line-height:1.55;">
            Department Head
            &nbsp;→&nbsp;<span style="font-weight:600;color:#2563eb;">You (AO)</span>
            &nbsp;→&nbsp;Leave Manager
        </p>
        <p style="margin:4px 0 0;font-size:0.8rem;color:#1e3a8a;line-height:1.5;">
            These requests have already been recommended by the Department Head. Endorsing forwards them to the Leave Manager for final approval.
        </p>
    </div>
</div>

{{-- Table --}}
<div class="hris-table-card">
    <div class="hris-table-header" style="background:linear-gradient(90deg,#eff6ff,#fff);">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-stamp" style="color:#3b82f6;margin-right:8px;"></i>
                Pending Endorsements
            </h2>
            <p class="hris-table-subtitle">DH-recommended cancellation requests from your department</p>
        </div>
    </div>
    <div class="hris-table-wrapper">
        <table id="ao-cancellation-table" class="hris-table" style="width:100%">
            <thead>
                <tr>
                    <th style="width:48px">#</th>
                    <th>Employee</th>
                    <th>Leave Type</th>
                    <th>Leave Period</th>
                    <th>Cancellation Reason</th>
                    <th>DH Remarks</th>
                    <th>Requested</th>
                    <th style="width:160px">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requests as $item)
                    @php
                        $leaveType = strtoupper($item->leave_type ?? '');
                        $typeColors = [
                            'VL'   => ['bg'=>'#dbeafe','color'=>'#1e40af','border'=>'#93c5fd'],
                            'SL'   => ['bg'=>'#fee2e2','color'=>'#991b1b','border'=>'#fca5a5'],
                            'WLNS' => ['bg'=>'#dcfce7','color'=>'#166534','border'=>'#86efac'],
                            'SPL'  => ['bg'=>'#ede9fe','color'=>'#5b21b6','border'=>'#c4b5fd'],
                            'CTO'  => ['bg'=>'#fff7ed','color'=>'#9a3412','border'=>'#fdba74'],
                            'SP'   => ['bg'=>'#ccfbf1','color'=>'#134e4a','border'=>'#5eead4'],
                        ];
                        $tc = $typeColors[$leaveType] ?? ['bg'=>'#f1f5f9','color'=>'#475569','border'=>'#cbd5e1'];
                        $empName = $item->user ? trim(($item->user->last_name ?? '').', '.($item->user->first_name ?? '')) : '-';
                        $sameDay = $item->end_date && $item->end_date === $item->start_date;
                        $period = $item->start_date ? \Carbon\Carbon::parse($item->start_date)->format('M d, Y') : '-';
                        if (!$sameDay && $item->end_date) {
                            $period .= ' – '.\Carbon\Carbon::parse($item->end_date)->format('M d, Y');
                        }
                        $requestedAt = $item->cancellation_requested_at
                            ? \Carbon\Carbon::parse($item->cancellation_requested_at)->diffForHumans()
                            : '-';
                        $requestedFull = $item->cancellation_requested_at
                            ? \Carbon\Carbon::parse($item->cancellation_requested_at)->format('M d, Y H:i')
                            : '-';
                        $dhRemarks = $item->cancellation_dh_remarks ?? '-';
                    @endphp
                    <tr style="cursor:pointer;" class="cancellation-row" data-id="{{ $item->id }}"
                        data-employee="{{ $empName }}"
                        data-leave-type="{{ $leaveType }}"
                        data-period="{{ $period }}"
                        data-reason="{{ e($item->cancellation_reason ?? '-') }}"
                        data-dh-remarks="{{ e($dhRemarks) }}"
                        data-requested="{{ $requestedFull }}"
                    >
                        <td class="text-center" style="color:#94a3b8;font-size:0.8rem;">{{ $item->id }}</td>
                        <td>
                            <div style="font-weight:600;font-size:0.9rem;">{{ $empName }}</div>
                            @if($item->user && $item->user->designation)
                                <div style="font-size:0.75rem;color:#94a3b8;">{{ $item->user->designation }}</div>
                            @endif
                        </td>
                        <td class="text-center">
                            <span style="background:{{ $tc['bg'] }};color:{{ $tc['color'] }};border:1px solid {{ $tc['border'] }};padding:2px 10px;border-radius:12px;font-size:0.78rem;font-weight:700;white-space:nowrap;">
                                {{ $leaveType }}
                            </span>
                        </td>
                        <td style="font-size:0.85rem;white-space:nowrap;">{{ $period }}</td>
                        <td style="max-width:180px;">
                            @if(($item->cancellation_reason ?? '') === 'Reported to work')
                                <span style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:2px 8px;border-radius:4px;font-size:0.78rem;font-weight:600;">
                                    <i class="fa-solid fa-check" style="margin-right:3px;"></i>Reported to work
                                </span>
                            @else
                                <span style="font-size:0.85rem;">{{ $item->cancellation_reason ?? '-' }}</span>
                            @endif
                        </td>
                        <td style="max-width:160px;font-size:0.82rem;color:#475569;">
                            @if($dhRemarks !== '-')
                                <span title="{{ $dhRemarks }}">{{ \Illuminate\Support\Str::limit($dhRemarks, 50) }}</span>
                            @else
                                <span style="color:#d1d5db;">—</span>
                            @endif
                        </td>
                        <td style="font-size:0.8rem;color:#64748b;white-space:nowrap;" title="{{ $requestedFull }}">
                            <i class="fa-regular fa-clock" style="margin-right:4px;"></i>{{ $requestedAt }}
                        </td>
                        <td class="action-cell">
                            <div style="display:flex;gap:6px;flex-wrap:nowrap;">
                                <button class="hris-btn hris-btn-primary hris-btn-sm endorse-btn"
                                    data-id="{{ $item->id }}">
                                    <i class="fa-solid fa-stamp"></i> Endorse
                                </button>
                                <button class="hris-btn hris-btn-danger hris-btn-sm reject-btn"
                                    data-id="{{ $item->id }}">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">
                            <div style="text-align:center;padding:48px 24px;color:#94a3b8;">
                                <i class="fa-regular fa-circle-check" style="font-size:2.5rem;color:#d1d5db;margin-bottom:12px;display:block;"></i>
                                <div style="font-size:1rem;font-weight:600;color:#6b7280;margin-bottom:4px;">No pending endorsements</div>
                                <div style="font-size:0.85rem;">There are no DH-recommended cancellation requests awaiting your endorsement.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($requests instanceof \Illuminate\Pagination\LengthAwarePaginator && $requests->total() > 0)
    <div class="paginate-bar paginate-bar--bottom" style="padding:10px 16px;">
        <span class="paginate-summary">Showing {{ $requests->firstItem() }}–{{ $requests->lastItem() }} of {{ $requests->total() }}</span>
        {{ $requests->links() }}
    </div>
    @endif
</div>

@endsection

@section('modals')

{{-- Detail modal --}}
<dialog id="detail-modal" class="employee-modal" style="max-width:500px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title"><i class="fa-solid fa-stamp" style="color:#3b82f6;margin-right:6px;"></i>Cancellation Request</h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body" id="detail-body" style="line-height:1.7;font-size:0.9rem;"></div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Close</button></form>
        <button class="hris-btn hris-btn-danger" id="detail-reject-btn"><i class="fa-solid fa-xmark"></i> Reject</button>
        <button class="hris-btn hris-btn-primary" id="detail-endorse-btn"><i class="fa-solid fa-stamp"></i> Endorse</button>
    </div>
</dialog>

{{-- Endorse confirmation modal --}}
<dialog id="endorse-modal" class="employee-modal" style="max-width:420px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title" style="color:#1d4ed8;">
            <i class="fa-solid fa-stamp" style="margin-right:6px;"></i>Endorse Cancellation?
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:0.87rem;color:#1e40af;">
            <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
            This will forward the cancellation request to the <strong>Leave Manager</strong> for final approval.
        </div>
        <div id="endorse-summary" style="margin-bottom:16px;font-size:0.87rem;color:#374151;line-height:1.6;"></div>
        <div>
            <label style="font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px;">
                Remarks <span style="font-weight:400;color:#94a3b8;">(optional)</span>
            </label>
            <textarea id="endorse-remarks" rows="3"
                placeholder="Add a note for the Leave Manager..."
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.875rem;resize:vertical;font-family:inherit;"></textarea>
        </div>
    </div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Cancel</button></form>
        <button class="hris-btn hris-btn-primary" id="endorse-confirm-btn">
            <i class="fa-solid fa-stamp"></i> Yes, Endorse
        </button>
    </div>
</dialog>

{{-- Reject confirmation modal --}}
<dialog id="reject-modal" class="employee-modal" style="max-width:420px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title" style="color:#dc2626;">
            <i class="fa-solid fa-circle-xmark" style="margin-right:6px;"></i>Reject Cancellation?
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:0.87rem;color:#991b1b;">
            <i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>
            The employee will be <strong>notified</strong> that their cancellation request has been denied. The leave will remain approved.
        </div>
        <div id="reject-summary" style="margin-bottom:16px;font-size:0.87rem;color:#374151;line-height:1.6;"></div>
        <div>
            <label style="font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px;">
                Remarks <span style="color:#ef4444;">*</span>
            </label>
            <textarea id="reject-remarks" rows="3"
                placeholder="Required — explain why the request is being rejected..."
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.875rem;resize:vertical;font-family:inherit;"></textarea>
            <p id="reject-remarks-error" style="color:#ef4444;font-size:0.8rem;margin:4px 0 0;display:none;">Remarks are required.</p>
        </div>
    </div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Cancel</button></form>
        <button class="hris-btn hris-btn-danger" id="reject-confirm-btn">
            <i class="fa-solid fa-xmark"></i> Yes, Reject
        </button>
    </div>
</dialog>

@endsection

@section('page_scripts_after')
<script>
(function($){
    var pendingLeaveId = null;

    function escapeHtml(str) {
        if (!str && str !== 0) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function summaryHtml(row) {
        return '<strong>' + escapeHtml(row.employee || row) + '</strong>' +
               (row.leaveType ? ' &mdash; ' + escapeHtml(row.leaveType) : '') +
               (row.period ? '<br><span style="color:#64748b;font-size:0.82rem;">' + escapeHtml(row.period) + '</span>' : '');
    }

    function pollBadge() {
        const badge = $('.sidebar-badge[data-badge-key="pending_cancellation_ao"]');
        if (!badge.length) return;
        $.getJSON('{{ route('api.admin-officer.pending-cancellation-count') }}')
            .done(function(resp){
                const count = parseInt(resp.count || 0, 10);
                badge.text(count).toggle(count > 0);
            });
    }

    // ── Row click → detail modal (skip if action button clicked) ──────
    $(document).on('click', '.cancellation-row', function(e){
        if ($(e.target).closest('.action-cell').length) return;
        var $row = $(this);
        pendingLeaveId = $row.data('id');
        var dhR = $row.data('dh-remarks');

        $('#detail-body').html(
            '<table style="width:100%;border-collapse:collapse;">' +
            dRow('Employee',            escapeHtml($row.data('employee'))) +
            dRow('Leave Type',          '<strong>' + escapeHtml($row.data('leave-type')) + '</strong>') +
            dRow('Leave Period',        escapeHtml($row.data('period'))) +
            dRow('Cancellation Reason', escapeHtml($row.data('reason'))) +
            dRow('DH Remarks',          dhR && dhR !== '-' ? escapeHtml(dhR) : '<span style="color:#d1d5db;">—</span>') +
            dRow('Requested At',        escapeHtml($row.data('requested'))) +
            '</table>'
        );
        $('#detail-endorse-btn, #detail-reject-btn').data('row', {
            employee: $row.data('employee'),
            leaveType: $row.data('leave-type'),
            period: $row.data('period'),
        });
        document.getElementById('detail-modal').showModal();
    });

    function dRow(label, value) {
        return '<tr><td style="padding:6px 10px 6px 0;color:#64748b;white-space:nowrap;vertical-align:top;font-size:0.82rem;width:38%;">' + label + '</td>' +
               '<td style="padding:6px 0;font-weight:500;">' + value + '</td></tr>';
    }

    // ── Detail modal → confirmation modals ─────────────────────────────
    $(document).on('click', '#detail-endorse-btn', function(){
        var rowData = $(this).data('row');
        document.getElementById('detail-modal').close();
        openEndorseModal(pendingLeaveId, rowData);
    });

    $(document).on('click', '#detail-reject-btn', function(){
        var rowData = $(this).data('row');
        document.getElementById('detail-modal').close();
        openRejectModal(pendingLeaveId, rowData);
    });

    // ── Inline buttons ─────────────────────────────────────────────────
    $(document).on('click', '.endorse-btn', function(){
        var $tr = $(this).closest('tr');
        openEndorseModal($(this).data('id'), {
            employee: $tr.data('employee'), leaveType: $tr.data('leave-type'), period: $tr.data('period'),
        });
    });

    $(document).on('click', '.reject-btn', function(){
        var $tr = $(this).closest('tr');
        openRejectModal($(this).data('id'), {
            employee: $tr.data('employee'), leaveType: $tr.data('leave-type'), period: $tr.data('period'),
        });
    });

    // ── Endorse modal ──────────────────────────────────────────────────
    function openEndorseModal(id, rowData) {
        pendingLeaveId = id;
        $('#endorse-summary').html(summaryHtml(rowData));
        $('#endorse-remarks').val('');
        document.getElementById('endorse-modal').showModal();
        setTimeout(function(){ document.getElementById('endorse-remarks').focus(); }, 80);
    }

    $(document).on('click', '#endorse-confirm-btn', function(){
        var remarks = $('#endorse-remarks').val().trim();
        document.getElementById('endorse-modal').close();
        submitEndorse(pendingLeaveId, remarks);
    });

    function submitEndorse(id, remarks) {
        var $btn = $('#endorse-confirm-btn').prop('disabled', true);
        $.ajax({
            url: '{{ url('/admin-officer/leave') }}/' + id + '/endorse-cancellation',
            method: 'POST',
            data: { remarks: remarks, _token: '{{ csrf_token() }}' },
            dataType: 'json'
        }).done(function(resp) {
            if (resp && resp.success) {
                showToast('Forwarded to the Leave Manager for final approval.', 'success');
                setTimeout(function(){ window.location.reload(); }, 1400);
            } else {
                showToast(resp && resp.error ? resp.error : 'Failed to endorse.', 'error');
            }
        }).fail(function(xhr) {
            var msg = 'Failed to endorse cancellation.';
            try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
            showToast(msg, 'error');
        }).always(function(){ $btn.prop('disabled', false); });
    }

    // ── Reject modal ───────────────────────────────────────────────────
    function openRejectModal(id, rowData) {
        pendingLeaveId = id;
        $('#reject-summary').html(summaryHtml(rowData));
        $('#reject-remarks').val('');
        $('#reject-remarks-error').hide();
        $('#reject-remarks').css('border-color', '#d1d5db');
        document.getElementById('reject-modal').showModal();
        setTimeout(function(){ document.getElementById('reject-remarks').focus(); }, 80);
    }

    $(document).on('click', '#reject-confirm-btn', function(){
        var remarks = $('#reject-remarks').val().trim();
        if (!remarks) {
            $('#reject-remarks-error').show();
            $('#reject-remarks').css('border-color', '#ef4444').focus();
            return;
        }
        $('#reject-remarks-error').hide();
        document.getElementById('reject-modal').close();
        submitReject(pendingLeaveId, remarks);
    });

    $(document).on('input', '#reject-remarks', function(){
        if ($(this).val().trim()) {
            $('#reject-remarks-error').hide();
            $(this).css('border-color', '#d1d5db');
        }
    });

    function submitReject(id, remarks) {
        $.ajax({
            url: '{{ url('/admin-officer/leave') }}/' + id + '/reject-cancellation',
            method: 'POST',
            data: { remarks: remarks, _token: '{{ csrf_token() }}' },
            dataType: 'json'
        }).done(function(resp) {
            if (resp && resp.success) {
                showToast('Cancellation request rejected. Employee notified.', 'success');
                setTimeout(function(){ window.location.reload(); }, 1400);
            } else {
                showToast(resp && resp.error ? resp.error : 'Failed to reject.', 'error');
            }
        }).fail(function(xhr) {
            var msg = 'Failed to reject cancellation.';
            try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
            showToast(msg, 'error');
        });
    }

    // ── Toast ──────────────────────────────────────────────────────────
    function showToast(message, type) {
        var c = type === 'success'
            ? { bg:'#f0fdf4', border:'#86efac', color:'#166534', icon:'fa-circle-check' }
            : { bg:'#fef2f2', border:'#fca5a5', color:'#991b1b', icon:'fa-circle-xmark' };
        var $t = $('<div>').css({ position:'fixed', bottom:'24px', right:'24px', zIndex:9999, maxWidth:'360px',
            background:c.bg, border:'1px solid '+c.border, borderLeft:'4px solid '+c.border, borderRadius:'8px',
            padding:'12px 16px', display:'flex', alignItems:'center', gap:'10px',
            boxShadow:'0 4px 12px rgba(0,0,0,0.1)', fontSize:'0.875rem', color:c.color, opacity:0, transition:'opacity 0.2s' })
            .html('<i class="fa-solid '+c.icon+'" style="font-size:1.1rem;flex-shrink:0;"></i><span>'+escapeHtml(message)+'</span>')
            .appendTo('body');
        setTimeout(function(){ $t.css('opacity',1); }, 10);
        setTimeout(function(){ $t.css('opacity',0); setTimeout(function(){ $t.remove(); }, 250); }, 2800);
    }

    $(function(){
        pollBadge();
        setInterval(pollBadge, 20000);
    });
})(jQuery);
</script>
@endsection
