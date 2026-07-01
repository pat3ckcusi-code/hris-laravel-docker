@extends('dashboards.layout', [
    'title'    => 'Employee Cancellation Requests',
    'subtitle' => 'Step 3 of 3 — Final approval for leave cancellation requests',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('content')

{{-- Workflow banner --}}
<div style="display:flex;align-items:flex-start;gap:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #16a34a;border-radius:10px;padding:14px 18px;margin-bottom:24px;">
    <i class="fa-solid fa-circle-info" style="color:#16a34a;font-size:1.2rem;margin-top:2px;flex-shrink:0;"></i>
    <div>
        <strong style="color:#14532d;font-size:0.92rem;">3-Step Cancellation Workflow — You are the Final Approver</strong>
        <p style="margin:4px 0 0;font-size:0.875rem;color:#166534;line-height:1.55;">
            Department Head
            &nbsp;→&nbsp;Administrative Officer
            &nbsp;→&nbsp;<span style="font-weight:600;color:#15803d;">You (Leave Manager)</span>
        </p>
        <p style="margin:4px 0 0;font-size:0.8rem;color:#14532d;line-height:1.5;">
            These requests have been recommended by the DH and endorsed by the AO. Approving cancels the leave and refunds credits.
        </p>
    </div>
</div>

{{-- Table card --}}
<div class="hris-table-card">
    <div class="hris-table-header" style="background:linear-gradient(90deg,#f0fdf4,#fff);">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-circle-check" style="color:#16a34a;margin-right:8px;"></i>
                Pending Final Approval
            </h2>
            <p class="hris-table-subtitle">DH-recommended and AO-endorsed cancellation requests</p>
        </div>
        {{-- Employee search --}}
        <div style="position:relative;min-width:260px;">
            <input type="text" id="claEmployeeSearch"
                placeholder="Search employee..."
                autocomplete="off"
                style="width:100%;padding:7px 12px 7px 34px;border:1px solid #d1d5db;border-radius:8px;font-size:0.875rem;">
            <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:0.8rem;pointer-events:none;"></i>
            <input type="hidden" id="claEmployee" value="">
            <div id="claEmployee_suggestions" class="list-group" style="position:absolute;z-index:1050;top:100%;left:0;right:0;display:none;max-height:240px;overflow:auto;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);"></div>
        </div>
    </div>

    {{-- Bulk toolbar --}}
    <div id="bulk-toolbar" style="display:none;align-items:center;gap:10px;padding:10px 16px;background:#f0fdf4;border-bottom:1px solid #bbf7d0;flex-wrap:wrap;">
        <span id="bulk-count-label" style="font-weight:600;color:#15803d;font-size:0.875rem;"></span>
        <button class="hris-btn hris-btn-success hris-btn-sm" id="bulk-approve-btn">
            <i class="fa-solid fa-check"></i> Bulk Approve
        </button>
        <button class="hris-btn hris-btn-danger hris-btn-sm" id="bulk-reject-btn">
            <i class="fa-solid fa-xmark"></i> Bulk Reject
        </button>
        <button id="bulk-clear-btn" style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:0.8rem;text-decoration:underline;padding:0;">
            Clear selection
        </button>
    </div>

    <div class="hris-table-wrapper">
        <table id="cancellation-table" class="hris-table" style="width:100%">
            <thead>
                <tr>
                    <th style="width:36px"><input type="checkbox" id="select-all-cb" title="Select all"></th>
                    <th style="width:44px">#</th>
                    <th>Employee</th>
                    <th>Leave Type</th>
                    <th>Leave Period</th>
                    <th>Cancellation Reason</th>
                    <th>DH Remarks</th>
                    <th>AO Remarks</th>
                    <th>Requested</th>
                    <th style="width:150px">Actions</th>
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
                        $dept = !empty($departments[$item->user?->Dept_id] ?? '') ? $departments[$item->user->Dept_id] : '';
                        $sameDay = $item->end_date && $item->end_date === $item->start_date;
                        $period = $item->start_date ? \Carbon\Carbon::parse($item->start_date)->format('M d, Y') : '-';
                        if (!$sameDay && $item->end_date) $period .= ' – '.\Carbon\Carbon::parse($item->end_date)->format('M d, Y');
                        $requestedAt   = $item->cancellation_requested_at ? \Carbon\Carbon::parse($item->cancellation_requested_at)->diffForHumans() : '-';
                        $requestedFull = $item->cancellation_requested_at ? \Carbon\Carbon::parse($item->cancellation_requested_at)->format('M d, Y H:i') : '-';
                        $dhRemarks = $item->cancellation_dh_remarks ?? '-';
                        $aoRemarks = $item->cancellation_ao_remarks ?? '-';
                    @endphp
                    <tr class="cancellation-row" style="cursor:pointer;"
                        data-id="{{ $item->id }}"
                        data-employee="{{ $empName }}"
                        data-dept="{{ $dept }}"
                        data-leave-type="{{ $leaveType }}"
                        data-period="{{ $period }}"
                        data-reason="{{ e($item->cancellation_reason ?? '-') }}"
                        data-dh-remarks="{{ e($dhRemarks) }}"
                        data-ao-remarks="{{ e($aoRemarks) }}"
                        data-requested="{{ $requestedFull }}"
                    >
                        <td class="text-center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="row-select" value="{{ $item->id }}">
                        </td>
                        <td class="text-center" style="color:#94a3b8;font-size:0.8rem;">{{ $item->id }}</td>
                        <td>
                            <div style="font-weight:600;font-size:0.9rem;">{{ $empName }}</div>
                            @if($dept)
                                <div style="font-size:0.75rem;color:#94a3b8;">{{ $dept }}</div>
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
                        <td style="max-width:140px;font-size:0.82rem;color:#475569;">
                            {{ $dhRemarks !== '-' ? \Illuminate\Support\Str::limit($dhRemarks, 45) : '—' }}
                        </td>
                        <td style="max-width:140px;font-size:0.82rem;color:#475569;">
                            {{ $aoRemarks !== '-' ? \Illuminate\Support\Str::limit($aoRemarks, 45) : '—' }}
                        </td>
                        <td style="font-size:0.8rem;color:#64748b;white-space:nowrap;" title="{{ $requestedFull }}">
                            <i class="fa-regular fa-clock" style="margin-right:4px;"></i>{{ $requestedAt }}
                        </td>
                        <td class="action-cell">
                            <div style="display:flex;gap:6px;flex-wrap:nowrap;">
                                <button class="hris-btn hris-btn-success hris-btn-sm approve-btn" data-id="{{ $item->id }}">
                                    <i class="fa-solid fa-check"></i> Approve
                                </button>
                                <button class="hris-btn hris-btn-danger hris-btn-sm reject-btn" data-id="{{ $item->id }}">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10">
                            <div style="text-align:center;padding:48px 24px;color:#94a3b8;">
                                <i class="fa-regular fa-circle-check" style="font-size:2.5rem;color:#d1d5db;margin-bottom:12px;display:block;"></i>
                                <div style="font-size:1rem;font-weight:600;color:#6b7280;margin-bottom:4px;">No pending cancellation requests</div>
                                <div style="font-size:0.85rem;">All cancellation requests have been processed.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($requests->total() > 0)
    <div class="paginate-bar paginate-bar--bottom" style="padding:10px 16px;">
        <span class="paginate-summary">Showing {{ $requests->firstItem() }}–{{ $requests->lastItem() }} of {{ $requests->total() }}</span>
        {{ $requests->appends(request()->query())->links() }}
    </div>
    @endif
</div>

@endsection

@section('modals')

{{-- Detail modal --}}
<dialog id="detail-modal" class="employee-modal" style="max-width:520px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title">
            <i class="fa-solid fa-circle-xmark" style="color:#16a34a;margin-right:6px;"></i>Cancellation Request
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body" id="detail-body" style="line-height:1.7;font-size:0.9rem;"></div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Close</button></form>
        <button class="hris-btn hris-btn-danger" id="detail-reject-btn"><i class="fa-solid fa-xmark"></i> Reject</button>
        <button class="hris-btn hris-btn-success" id="detail-approve-btn"><i class="fa-solid fa-check"></i> Approve</button>
    </div>
</dialog>

{{-- Approve confirmation modal --}}
<dialog id="approve-modal" class="employee-modal" style="max-width:420px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title" style="color:#15803d;">
            <i class="fa-solid fa-circle-check" style="margin-right:6px;"></i>Approve Cancellation?
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:0.87rem;color:#166534;">
            <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
            The leave will be <strong>cancelled</strong> and the employee's credits will be <strong>refunded</strong>.
        </div>
        <div id="approve-summary" style="margin-bottom:16px;font-size:0.87rem;color:#374151;line-height:1.6;"></div>
        <div>
            <label style="font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px;">
                Remarks <span style="font-weight:400;color:#94a3b8;">(optional)</span>
            </label>
            <textarea id="approve-remarks" rows="3"
                placeholder="Add a note for this approval..."
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.875rem;resize:vertical;font-family:inherit;"></textarea>
        </div>
    </div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Cancel</button></form>
        <button class="hris-btn hris-btn-success" id="approve-confirm-btn">
            <i class="fa-solid fa-check"></i> Yes, Approve
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
            The employee will be <strong>notified</strong> that their cancellation was denied. The leave remains approved.
        </div>
        <div id="reject-summary" style="margin-bottom:16px;font-size:0.87rem;color:#374151;line-height:1.6;"></div>
        <div>
            <label style="font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px;">
                Remarks <span style="color:#ef4444;">*</span>
            </label>
            <textarea id="reject-remarks" rows="3"
                placeholder="Required — explain why this request is being rejected..."
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

{{-- Bulk approve confirmation modal --}}
<dialog id="bulk-approve-modal" class="employee-modal" style="max-width:400px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title" style="color:#15803d;">
            <i class="fa-solid fa-check-double" style="margin-right:6px;"></i>Bulk Approve?
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-bottom:12px;font-size:0.87rem;color:#166534;">
            <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
            <span id="bulk-approve-count-label"></span> — each leave will be cancelled and credits refunded.
        </div>
    </div>
    <div class="modal-actions" style="margin-top:4px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Cancel</button></form>
        <button class="hris-btn hris-btn-success" id="bulk-approve-confirm-btn">
            <i class="fa-solid fa-check-double"></i> Yes, Approve All
        </button>
    </div>
</dialog>

{{-- Bulk reject confirmation modal --}}
<dialog id="bulk-reject-modal" class="employee-modal" style="max-width:420px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title" style="color:#dc2626;">
            <i class="fa-solid fa-xmark" style="margin-right:6px;"></i>Bulk Reject?
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:0.87rem;color:#991b1b;">
            <i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>
            <span id="bulk-reject-count-label"></span> — all selected employees will be notified.
        </div>
        <div>
            <label style="font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px;">
                Remarks <span style="color:#ef4444;">*</span>
            </label>
            <textarea id="bulk-reject-remarks" rows="3"
                placeholder="Required — applies to all selected rejections..."
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.875rem;resize:vertical;font-family:inherit;"></textarea>
            <p id="bulk-reject-remarks-error" style="color:#ef4444;font-size:0.8rem;margin:4px 0 0;display:none;">Remarks are required.</p>
        </div>
    </div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Cancel</button></form>
        <button class="hris-btn hris-btn-danger" id="bulk-reject-confirm-btn">
            <i class="fa-solid fa-xmark"></i> Yes, Reject All
        </button>
    </div>
</dialog>

@endsection

@section('page_scripts_after')
<script>
(function($){
    var pendingLeaveId = null;

    // ── Helpers ────────────────────────────────────────────────────────
    function escapeHtml(str) {
        if (!str && str !== 0) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    var typeColorMap = {
        'VL':   { bg:'#dbeafe', color:'#1e40af', border:'#93c5fd' },
        'SL':   { bg:'#fee2e2', color:'#991b1b', border:'#fca5a5' },
        'WLNS': { bg:'#dcfce7', color:'#166534', border:'#86efac' },
        'SPL':  { bg:'#ede9fe', color:'#5b21b6', border:'#c4b5fd' },
        'CTO':  { bg:'#fff7ed', color:'#9a3412', border:'#fdba74' },
        'SP':   { bg:'#ccfbf1', color:'#134e4a', border:'#5eead4' },
    };

    function typeBadge(type) {
        var tc = typeColorMap[type] || { bg:'#f1f5f9', color:'#475569', border:'#cbd5e1' };
        return '<span style="background:'+tc.bg+';color:'+tc.color+';border:1px solid '+tc.border+';padding:2px 10px;border-radius:12px;font-size:0.78rem;font-weight:700;white-space:nowrap;">'+escapeHtml(type)+'</span>';
    }

    function reasonHtml(reason) {
        return reason === 'Reported to work'
            ? '<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:2px 8px;border-radius:4px;font-size:0.78rem;font-weight:600;"><i class="fa-solid fa-check" style="margin-right:3px;"></i>Reported to work</span>'
            : escapeHtml(reason || '-');
    }

    function summaryHtml(data) {
        return '<strong>' + escapeHtml(data.employee) + '</strong>' +
               (data.leaveType ? ' &mdash; ' + escapeHtml(data.leaveType) : '') +
               (data.period ? '<br><span style="color:#64748b;font-size:0.82rem;">' + escapeHtml(data.period) + '</span>' : '');
    }

    function dRow(label, value) {
        return '<tr><td style="padding:6px 10px 6px 0;color:#64748b;white-space:nowrap;vertical-align:top;font-size:0.82rem;width:38%;">'+label+'</td>'+
               '<td style="padding:6px 0;font-weight:500;">'+value+'</td></tr>';
    }

    function showToast(msg, type) {
        var c = type === 'success'
            ? { bg:'#f0fdf4', border:'#86efac', color:'#166534', icon:'fa-circle-check' }
            : { bg:'#fef2f2', border:'#fca5a5', color:'#991b1b', icon:'fa-circle-xmark' };
        var $t = $('<div>').css({ position:'fixed', bottom:'24px', right:'24px', zIndex:9999, maxWidth:'360px',
            background:c.bg, border:'1px solid '+c.border, borderLeft:'4px solid '+c.border, borderRadius:'8px',
            padding:'12px 16px', display:'flex', alignItems:'center', gap:'10px',
            boxShadow:'0 4px 12px rgba(0,0,0,0.1)', fontSize:'0.875rem', color:c.color, opacity:0, transition:'opacity 0.2s' })
            .html('<i class="fa-solid '+c.icon+'" style="font-size:1.1rem;flex-shrink:0;"></i><span>'+escapeHtml(msg)+'</span>')
            .appendTo('body');
        setTimeout(function(){ $t.css('opacity',1); }, 10);
        setTimeout(function(){ $t.css('opacity',0); setTimeout(function(){ $t.remove(); }, 250); }, 2800);
    }

    // ── Badge polling ──────────────────────────────────────────────────
    function pollBadge() {
        var badge = $('.sidebar-badge[data-badge-key="pending_employee_cancellation_requests"]');
        if (!badge.length) return;
        $.getJSON('{{ route('api.leave-manager.pending-cancellation-count') }}')
            .done(function(resp){
                var count = parseInt(resp.count || 0, 10);
                badge.text(count).toggle(count > 0);
            });
    }

    // ── Checkbox / bulk toolbar ────────────────────────────────────────
    function getSelectedIds() {
        return $('.row-select:checked').map(function(){ return parseInt($(this).val(), 10); }).get();
    }

    function updateBulkToolbar() {
        var count = getSelectedIds().length;
        if (count > 0) {
            $('#bulk-count-label').text(count + ' selected');
            $('#bulk-toolbar').css('display', 'flex');
        } else {
            $('#bulk-toolbar').hide();
        }
    }

    $(document).on('change', '#select-all-cb', function(){
        $('.row-select').prop('checked', $(this).is(':checked'));
        updateBulkToolbar();
    });

    $(document).on('change', '.row-select', function(){
        var total = $('.row-select').length, checked = $('.row-select:checked').length;
        $('#select-all-cb').prop('indeterminate', checked > 0 && checked < total)
                           .prop('checked', checked === total && total > 0);
        updateBulkToolbar();
    });

    $(document).on('click', '#bulk-clear-btn', function(){
        $('.row-select, #select-all-cb').prop('checked', false);
        $('#select-all-cb').prop('indeterminate', false);
        updateBulkToolbar();
    });

    // ── Row click → detail modal ───────────────────────────────────────
    $(document).on('click', '.cancellation-row', function(e){
        if ($(e.target).closest('.action-cell, td:first-child').length) return;
        var $r = $(this);
        pendingLeaveId = $r.data('id');
        var dhR = $r.data('dh-remarks'), aoR = $r.data('ao-remarks');

        $('#detail-body').html(
            '<table style="width:100%;border-collapse:collapse;">' +
            dRow('Employee',            escapeHtml($r.data('employee'))) +
            dRow('Department',          escapeHtml($r.data('dept') || '—')) +
            dRow('Leave Type',          typeBadge($r.data('leave-type'))) +
            dRow('Leave Period',        escapeHtml($r.data('period'))) +
            dRow('Cancellation Reason', reasonHtml($r.data('reason'))) +
            dRow('DH Remarks',          dhR && dhR !== '-' ? escapeHtml(dhR) : '<span style="color:#d1d5db;">—</span>') +
            dRow('AO Remarks',          aoR && aoR !== '-' ? escapeHtml(aoR) : '<span style="color:#d1d5db;">—</span>') +
            dRow('Requested At',        escapeHtml($r.data('requested'))) +
            '</table>'
        );
        $('#detail-approve-btn, #detail-reject-btn').data('row', {
            employee: $r.data('employee'), leaveType: $r.data('leave-type'), period: $r.data('period'),
        });
        document.getElementById('detail-modal').showModal();
    });

    $(document).on('click', '#detail-approve-btn', function(){
        var row = $(this).data('row');
        document.getElementById('detail-modal').close();
        openApproveModal(pendingLeaveId, row);
    });

    $(document).on('click', '#detail-reject-btn', function(){
        var row = $(this).data('row');
        document.getElementById('detail-modal').close();
        openRejectModal(pendingLeaveId, row);
    });

    // ── Inline action buttons ──────────────────────────────────────────
    $(document).on('click', '.approve-btn', function(){
        var $tr = $(this).closest('tr');
        openApproveModal($(this).data('id'), {
            employee: $tr.data('employee'), leaveType: $tr.data('leave-type'), period: $tr.data('period'),
        });
    });

    $(document).on('click', '.reject-btn', function(){
        var $tr = $(this).closest('tr');
        openRejectModal($(this).data('id'), {
            employee: $tr.data('employee'), leaveType: $tr.data('leave-type'), period: $tr.data('period'),
        });
    });

    // ── Approve modal ──────────────────────────────────────────────────
    function openApproveModal(id, row) {
        pendingLeaveId = id;
        $('#approve-summary').html(summaryHtml(row));
        $('#approve-remarks').val('');
        document.getElementById('approve-modal').showModal();
        setTimeout(function(){ document.getElementById('approve-remarks').focus(); }, 80);
    }

    $(document).on('click', '#approve-confirm-btn', function(){
        var remarks = $('#approve-remarks').val().trim();
        document.getElementById('approve-modal').close();
        submitApprove(pendingLeaveId, remarks);
    });

    function submitApprove(id, remarks) {
        var $btn = $('#approve-confirm-btn').prop('disabled', true);
        $.post('{{ url('/api/leave') }}/' + id + '/approve-cancellation', { _token: '{{ csrf_token() }}', remarks: remarks })
            .done(function(resp){
                if (resp && resp.success) {
                    showToast('Leave cancelled and credits refunded.', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1400);
                } else {
                    showToast(resp && resp.error ? resp.error : 'Failed to approve.', 'error');
                }
            })
            .fail(function(xhr){
                var msg = 'Failed to approve cancellation.';
                try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                showToast(msg, 'error');
            })
            .always(function(){ $btn.prop('disabled', false); });
    }

    // ── Reject modal ───────────────────────────────────────────────────
    function openRejectModal(id, row) {
        pendingLeaveId = id;
        $('#reject-summary').html(summaryHtml(row));
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
        document.getElementById('reject-modal').close();
        submitReject(pendingLeaveId, remarks);
    });

    $(document).on('input', '#reject-remarks', function(){
        if ($(this).val().trim()) { $('#reject-remarks-error').hide(); $(this).css('border-color', '#d1d5db'); }
    });

    function submitReject(id, remarks) {
        $.post('{{ url('/api/leave') }}/' + id + '/reject-cancellation', { _token: '{{ csrf_token() }}', remarks: remarks })
            .done(function(resp){
                if (resp && resp.success) {
                    showToast('Cancellation rejected. Employee notified.', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1400);
                } else {
                    showToast(resp && resp.error ? resp.error : 'Failed to reject.', 'error');
                }
            })
            .fail(function(xhr){
                var msg = 'Failed to reject cancellation.';
                try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                showToast(msg, 'error');
            });
    }

    // ── Bulk approve ───────────────────────────────────────────────────
    $(document).on('click', '#bulk-approve-btn', function(){
        var ids = getSelectedIds();
        if (!ids.length) return;
        $('#bulk-approve-count-label').text('Approving ' + ids.length + ' cancellation request' + (ids.length > 1 ? 's' : ''));
        document.getElementById('bulk-approve-modal').showModal();
    });

    $(document).on('click', '#bulk-approve-confirm-btn', function(){
        var ids = getSelectedIds();
        document.getElementById('bulk-approve-modal').close();
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: '{{ route('api.leave.bulk-approve-cancellations') }}',
            method: 'POST',
            data: { leave_ids: ids, _token: '{{ csrf_token() }}' },
            dataType: 'json'
        }).done(function(resp){
            if (resp && resp.success) {
                var msg = resp.processed + ' request' + (resp.processed !== 1 ? 's' : '') + ' approved.';
                if (resp.errors && resp.errors.length) msg += ' ' + resp.errors.length + ' failed.';
                showToast(msg, resp.errors && resp.errors.length ? 'error' : 'success');
                setTimeout(function(){ window.location.reload(); }, 1400);
            } else {
                showToast(resp && resp.error ? resp.error : 'Bulk approve failed.', 'error');
            }
        }).fail(function(xhr){
            var msg = 'Bulk approve failed.';
            try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
            showToast(msg, 'error');
        }).always(function(){ $btn.prop('disabled', false); });
    });

    // ── Bulk reject ────────────────────────────────────────────────────
    $(document).on('click', '#bulk-reject-btn', function(){
        var ids = getSelectedIds();
        if (!ids.length) return;
        $('#bulk-reject-count-label').text('Rejecting ' + ids.length + ' cancellation request' + (ids.length > 1 ? 's' : ''));
        $('#bulk-reject-remarks').val('');
        $('#bulk-reject-remarks-error').hide();
        $('#bulk-reject-remarks').css('border-color', '#d1d5db');
        document.getElementById('bulk-reject-modal').showModal();
        setTimeout(function(){ document.getElementById('bulk-reject-remarks').focus(); }, 80);
    });

    $(document).on('click', '#bulk-reject-confirm-btn', function(){
        var remarks = $('#bulk-reject-remarks').val().trim();
        if (!remarks) {
            $('#bulk-reject-remarks-error').show();
            $('#bulk-reject-remarks').css('border-color', '#ef4444').focus();
            return;
        }
        var ids = getSelectedIds();
        document.getElementById('bulk-reject-modal').close();
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: '{{ route('api.leave.bulk-reject-cancellations') }}',
            method: 'POST',
            data: { leave_ids: ids, remarks: remarks, _token: '{{ csrf_token() }}' },
            dataType: 'json'
        }).done(function(resp){
            if (resp && resp.success) {
                var msg = resp.processed + ' request' + (resp.processed !== 1 ? 's' : '') + ' rejected.';
                if (resp.errors && resp.errors.length) msg += ' ' + resp.errors.length + ' failed.';
                showToast(msg, 'success');
                setTimeout(function(){ window.location.reload(); }, 1400);
            } else {
                showToast(resp && resp.error ? resp.error : 'Bulk reject failed.', 'error');
            }
        }).fail(function(xhr){
            var msg = 'Bulk reject failed.';
            try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
            showToast(msg, 'error');
        }).always(function(){ $btn.prop('disabled', false); });
    });

    $(document).on('input', '#bulk-reject-remarks', function(){
        if ($(this).val().trim()) { $('#bulk-reject-remarks-error').hide(); $(this).css('border-color', '#d1d5db'); }
    });

    // ── Employee search ────────────────────────────────────────────────
    var claTimer = null, claIdx = -1;
    function resetSuggestions() { claIdx = -1; $('#claEmployee_suggestions').hide().empty(); }

    $('#claEmployeeSearch').on('input', function(){
        var q = $(this).val(); $('#claEmployee').val('');
        if (claTimer) clearTimeout(claTimer);
        if (!q || q.length < 2) { resetSuggestions(); return; }
        claTimer = setTimeout(function(){
            $.getJSON('{{ route('api.employee.search') }}', { q: q }, function(rows){
                var $box = $('#claEmployee_suggestions'); $box.empty();
                if (!rows || !rows.length) { $box.hide(); return; }
                rows.forEach(function(r){
                    var label = (r.FullName || r.EmpNo) + (r.Position ? ' - ' + r.Position : '') + ' (' + r.EmpNo + ')';
                    $('<a href="#" class="list-group-item list-group-item-action">').text(label)
                        .data({ empno: r.EmpNo, label: label })
                        .on('click', function(e){
                            e.preventDefault();
                            $('#claEmployee').val($(this).data('empno'));
                            $('#claEmployeeSearch').val($(this).data('label'));
                            $box.hide();
                            applyFilters();
                        })
                        .appendTo($box);
                });
                $box.show(); claIdx = -1;
            });
        }, 200);
    });

    $('#claEmployeeSearch').on('keydown', function(e){
        var $box = $('#claEmployee_suggestions'), $items = $box.children('.list-group-item');
        if (!$items.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); claIdx = Math.min(claIdx+1, $items.length-1); $items.removeClass('active').eq(claIdx).addClass('active')[0].scrollIntoView({block:'nearest'}); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); claIdx = Math.max(claIdx-1, 0); $items.removeClass('active').eq(claIdx).addClass('active')[0].scrollIntoView({block:'nearest'}); }
        else if (e.key === 'Enter') { e.preventDefault(); if (claIdx >= 0) $items.eq(claIdx).trigger('click'); else applyFilters(); }
        else if (e.key === 'Escape') { $box.hide(); }
    });

    $(document).on('click', function(e){
        if (!$(e.target).closest('#claEmployee_suggestions, #claEmployeeSearch').length) resetSuggestions();
    });

    function applyFilters(){
        var emp = $('#claEmployee').val() || '';
        window.location.href = '{{ route('leave-manager.employee-cancellation-requests') }}' + (emp ? '?emp=' + encodeURIComponent(emp) : '');
    }

    $(function(){
        pollBadge();
        setInterval(pollBadge, 20000);
    });
})(jQuery);
</script>
@endsection
