@extends('dashboards.layout', [
    'title'    => 'Attendance Deductions',
    'subtitle' => 'Review attendance deficiencies forwarded by Timekeeper/HR Manager and apply the Vacation Leave deduction',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('content')

{{-- Workflow banner --}}
<div style="display:flex;align-items:flex-start;gap:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-left:4px solid #16a34a;border-radius:10px;padding:14px 18px;margin-bottom:24px;">
    <i class="fa-solid fa-circle-info" style="color:#16a34a;font-size:1.2rem;margin-top:2px;flex-shrink:0;"></i>
    <div>
        <strong style="color:#14532d;font-size:0.92rem;">Attendance Adjustment Review</strong>
        <p style="margin:4px 0 0;font-size:0.875rem;color:#166534;line-height:1.55;">
            Timekeeper/HR Manager flags unfiled leave, tardiness, and undertime
            &nbsp;→&nbsp;<span style="font-weight:600;color:#15803d;">You (Leave Manager) deduct or dismiss</span>
        </p>
        <p style="margin:4px 0 0;font-size:0.8rem;color:#14532d;line-height:1.5;">
            Suggested VL Deduction = unfiled days + (tardiness minutes + undertime minutes) / 480. Deducting applies this exact amount to the employee's VL balance; Dismiss applies no deduction.
        </p>
    </div>
</div>

{{-- Table card --}}
<div class="hris-table-card">
    <div class="hris-table-header" style="background:linear-gradient(90deg,#f0fdf4,#fff);">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-scale-balanced" style="color:#16a34a;margin-right:8px;"></i>
                Pending Attendance Deductions
            </h2>
            <p class="hris-table-subtitle">Submitted by Timekeeper/HR Manager, not yet actioned</p>
        </div>
    </div>

    {{-- Filter bar --}}
    <form method="GET" action="{{ route('leave-manager.attendance-deductions') }}"
        style="display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;">
        <select name="month" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;">
            @foreach(range(1, 12) as $m)
                <option value="{{ $m }}" @selected(($filters['month'] ?? now()->month) == $m)>
                    {{ \Carbon\Carbon::create()->month($m)->format('F') }}
                </option>
            @endforeach
        </select>
        <input type="number" name="year" value="{{ $filters['year'] ?? now()->year }}" min="2000" max="2100"
            style="width:90px;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;">
        <select name="department" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;min-width:160px;">
            <option value="">All Departments</option>
            @foreach($departments as $dept)
                <option value="{{ $dept }}" @selected(($filters['department'] ?? '') === $dept)>{{ $dept }}</option>
            @endforeach
        </select>
        <select name="issue" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;">
            <option value="unfiled" @selected(($filters['issue'] ?? 'unfiled') === 'unfiled')>Unfiled Leave</option>
            <option value="tardiness" @selected(($filters['issue'] ?? '') === 'tardiness')>Tardiness</option>
            <option value="undertime" @selected(($filters['issue'] ?? '') === 'undertime')>Undertime</option>
        </select>
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search name/EmpNo..."
            style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;min-width:180px;">
        <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm">
            <i class="fa-solid fa-filter"></i> Filter
        </button>
    </form>

    {{-- Bulk toolbar --}}
    <div id="bulk-toolbar" style="display:none;align-items:center;gap:10px;padding:10px 16px;background:#f0fdf4;border-bottom:1px solid #bbf7d0;flex-wrap:wrap;">
        <span id="bulk-count-label" style="font-weight:600;color:#15803d;font-size:0.875rem;"></span>
        <button class="hris-btn hris-btn-success hris-btn-sm" id="bulk-deduct-btn">
            <i class="fa-solid fa-check"></i> Bulk Deduct VL
        </button>
        <button class="hris-btn hris-btn-danger hris-btn-sm" id="bulk-dismiss-btn">
            <i class="fa-solid fa-xmark"></i> Bulk Dismiss
        </button>
        <button id="bulk-clear-btn" style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:0.8rem;text-decoration:underline;padding:0;">
            Clear selection
        </button>
    </div>

    <div class="hris-table-wrapper">
        @php
            $issueColumn = match($filters['issue'] ?? 'unfiled') {
                'tardiness' => 'Tardiness',
                'undertime' => 'Undertime',
                default => 'Unfiled',
            };
        @endphp
        <table id="deduction-table" class="hris-table" style="width:100%">
            <thead>
                <tr>
                    <th style="width:36px"><input type="checkbox" id="select-all-cb" title="Select all"></th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Period</th>
                    <th>{{ $issueColumn }}</th>
                    <th>Suggested VL Deduction</th>
                    <th style="width:170px">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($items as $item)
                    @php
                        $period = \Carbon\Carbon::create()->month($item->month)->format('F').' '.$item->year;
                        $issueValue = match($filters['issue'] ?? 'unfiled') {
                            'tardiness' => $item->tardiness_minutes.' min',
                            'undertime' => $item->undertime_minutes.' min',
                            default => (string) $item->unfiled_count,
                        };
                    @endphp
                    <tr class="deduction-row" style="cursor:pointer;"
                        data-id="{{ $item->id }}"
                        data-employee="{{ $item->name }}"
                        data-empno="{{ $item->emp_no }}"
                        data-dept="{{ $item->department }}"
                        data-period="{{ $period }}"
                        data-unfiled="{{ $item->unfiled_count }}"
                        data-tardiness-minutes="{{ $item->tardiness_minutes }}"
                        data-undertime-minutes="{{ $item->undertime_minutes }}"
                        data-suggested="{{ $item->suggested_deduction }}"
                        data-remarks="{{ e($item->remarks ?? '') }}"
                    >
                        <td class="text-center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="row-select" value="{{ $item->id }}">
                        </td>
                        <td>
                            <div style="font-weight:600;font-size:0.9rem;">{{ $item->name }}</div>
                            <div style="font-size:0.75rem;color:#94a3b8;">{{ $item->emp_no }}</div>
                        </td>
                        <td style="font-size:0.85rem;">{{ $item->department ?? '—' }}</td>
                        <td style="font-size:0.85rem;white-space:nowrap;">{{ $period }}</td>
                        <td class="text-center">{{ $issueValue }}</td>
                        <td class="text-center" style="font-weight:700;color:#15803d;">{{ number_format($item->suggested_deduction, 3) }}</td>
                        <td class="action-cell">
                            <div style="display:flex;gap:6px;flex-wrap:nowrap;">
                                <button class="hris-btn hris-btn-success hris-btn-sm deduct-btn" data-id="{{ $item->id }}">
                                    <i class="fa-solid fa-check"></i> Deduct
                                </button>
                                <button class="hris-btn hris-btn-danger hris-btn-sm dismiss-btn" data-id="{{ $item->id }}">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <div style="text-align:center;padding:48px 24px;color:#94a3b8;">
                                <i class="fa-regular fa-circle-check" style="font-size:2.5rem;color:#d1d5db;margin-bottom:12px;display:block;"></i>
                                <div style="font-size:1rem;font-weight:600;color:#6b7280;margin-bottom:4px;">No pending attendance deductions</div>
                                <div style="font-size:0.85rem;">Nothing forwarded by Timekeeper/HR Manager awaits review.</div>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($items->total() > 0)
    <div class="paginate-bar paginate-bar--bottom" style="padding:10px 16px;">
        <span class="paginate-summary">Showing {{ $items->firstItem() }}–{{ $items->lastItem() }} of {{ $items->total() }}</span>
        {{ $items->appends(request()->query())->links() }}
    </div>
    @endif
</div>

@endsection

@section('modals')

{{-- Detail modal --}}
<dialog id="detail-modal" class="employee-modal" style="max-width:520px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title">
            <i class="fa-solid fa-scale-balanced" style="color:#16a34a;margin-right:6px;"></i>Attendance Deficiency
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body" id="detail-body" style="line-height:1.7;font-size:0.9rem;"></div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Close</button></form>
        <button class="hris-btn hris-btn-danger" id="detail-dismiss-btn"><i class="fa-solid fa-xmark"></i> Dismiss</button>
        <button class="hris-btn hris-btn-success" id="detail-deduct-btn"><i class="fa-solid fa-check"></i> Deduct VL</button>
    </div>
</dialog>

{{-- Deduct confirmation modal --}}
<dialog id="deduct-modal" class="employee-modal" style="max-width:420px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title" style="color:#15803d;">
            <i class="fa-solid fa-circle-check" style="margin-right:6px;"></i>Deduct Vacation Leave?
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:0.87rem;color:#166534;">
            <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
            This is a fixed, non-editable amount computed from the stored deficiency counts.
        </div>
        <div id="deduct-summary" style="margin-bottom:16px;font-size:0.87rem;color:#374151;line-height:1.6;"></div>
    </div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Cancel</button></form>
        <button class="hris-btn hris-btn-success" id="deduct-confirm-btn">
            <i class="fa-solid fa-check"></i> Yes, Deduct
        </button>
    </div>
</dialog>

{{-- Dismiss confirmation modal --}}
<dialog id="dismiss-modal" class="employee-modal" style="max-width:420px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title" style="color:#dc2626;">
            <i class="fa-solid fa-circle-xmark" style="margin-right:6px;"></i>Dismiss Item?
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:0.87rem;color:#991b1b;">
            <i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>
            No VL will be deducted. This item will be sent back to the Timekeeper/HR Manager for further review and adjustment, if necessary (e.g. an already-excused absence).
        </div>
        <div id="dismiss-summary" style="margin-bottom:16px;font-size:0.87rem;color:#374151;line-height:1.6;"></div>
        <div>
            <label style="font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px;">
                Remarks <span style="color:#ef4444;">*</span>
            </label>
            <textarea id="dismiss-remarks" rows="3"
                placeholder="Required -explain why this item is being dismissed..."
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.875rem;resize:vertical;font-family:inherit;"></textarea>
            <p id="dismiss-remarks-error" style="color:#ef4444;font-size:0.8rem;margin:4px 0 0;display:none;">Remarks are required.</p>
        </div>
    </div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Cancel</button></form>
        <button class="hris-btn hris-btn-danger" id="dismiss-confirm-btn">
            <i class="fa-solid fa-xmark"></i> Yes, Dismiss
        </button>
    </div>
</dialog>

{{-- Bulk deduct confirmation modal --}}
<dialog id="bulk-deduct-modal" class="employee-modal" style="max-width:400px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title" style="color:#15803d;">
            <i class="fa-solid fa-check-double" style="margin-right:6px;"></i>Bulk Deduct?
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px 14px;margin-bottom:12px;font-size:0.87rem;color:#166534;">
            <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
            <span id="bulk-deduct-count-label"></span> -each employee's computed VL amount will be deducted. An employee with insufficient balance will be reported as a failure and skipped.
        </div>
    </div>
    <div class="modal-actions" style="margin-top:4px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Cancel</button></form>
        <button class="hris-btn hris-btn-success" id="bulk-deduct-confirm-btn">
            <i class="fa-solid fa-check-double"></i> Yes, Deduct All
        </button>
    </div>
</dialog>

{{-- Bulk dismiss confirmation modal --}}
<dialog id="bulk-dismiss-modal" class="employee-modal" style="max-width:420px;width:100%;">
    <div class="dialog-header">
        <h3 class="dialog-title" style="color:#dc2626;">
            <i class="fa-solid fa-xmark" style="margin-right:6px;"></i>Bulk Dismiss?
        </h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:0.87rem;color:#991b1b;">
            <i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>
            <span id="bulk-dismiss-count-label"></span> -no VL will be deducted for any of these.
        </div>
        <div>
            <label style="font-weight:600;font-size:0.85rem;display:block;margin-bottom:6px;">
                Remarks <span style="color:#ef4444;">*</span>
            </label>
            <textarea id="bulk-dismiss-remarks" rows="3"
                placeholder="Required -applies to all selected dismissals..."
                style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.875rem;resize:vertical;font-family:inherit;"></textarea>
            <p id="bulk-dismiss-remarks-error" style="color:#ef4444;font-size:0.8rem;margin:4px 0 0;display:none;">Remarks are required.</p>
        </div>
    </div>
    <div class="modal-actions" style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
        <form method="dialog"><button class="hris-btn hris-btn-secondary" type="submit">Cancel</button></form>
        <button class="hris-btn hris-btn-danger" id="bulk-dismiss-confirm-btn">
            <i class="fa-solid fa-xmark"></i> Yes, Dismiss All
        </button>
    </div>
</dialog>

@endsection

@section('page_scripts_after')
<script>
(function($){
    var pendingItemId = null;

    // ── Helpers ────────────────────────────────────────────────────────
    function escapeHtml(str) {
        if (!str && str !== 0) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    function summaryHtml(data) {
        return '<strong>' + escapeHtml(data.employee) + '</strong>' +
               (data.empno ? ' (' + escapeHtml(data.empno) + ')' : '') +
               (data.period ? '<br><span style="color:#64748b;font-size:0.82rem;">' + escapeHtml(data.period) + '</span>' : '') +
               (data.suggested ? '<br>Suggested VL Deduction: <strong style="color:#15803d;">' + escapeHtml(data.suggested) + '</strong>' : '');
    }

    function dRow(label, value) {
        return '<tr><td style="padding:6px 10px 6px 0;color:#64748b;white-space:nowrap;vertical-align:top;font-size:0.82rem;width:44%;">'+label+'</td>'+
               '<td style="padding:6px 0;font-weight:500;">'+value+'</td></tr>';
    }

    function showToast(msg, type) {
        var c = type === 'success'
            ? { accent:'#16a34a', iconBg:'#dcfce7', iconColor:'#16a34a', icon:'fa-check', title:'Success' }
            : { accent:'#dc2626', iconBg:'#fee2e2', iconColor:'#dc2626', icon:'fa-xmark', title:'Something went wrong' };

        var $backdrop = $('<div>').css({
            position:'fixed', top:0, left:0, right:0, bottom:0, zIndex:9998,
            background:'rgba(15,23,42,0.35)', backdropFilter:'blur(1px)',
            display:'flex', alignItems:'center', justifyContent:'center',
            opacity:0, transition:'opacity 0.2s',
        }).appendTo('body');

        var $card = $('<div>').css({
            background:'#fff', borderRadius:'16px', padding:'28px 32px', maxWidth:'380px', width:'calc(100% - 40px)',
            boxShadow:'0 20px 50px rgba(15,23,42,0.25)', textAlign:'center',
            transform:'scale(0.9) translateY(8px)', transition:'transform 0.25s ease-out, opacity 0.2s', opacity:0,
        }).appendTo($backdrop);

        $('<div>').css({
            width:'56px', height:'56px', borderRadius:'50%', background:c.iconBg, color:c.iconColor,
            display:'flex', alignItems:'center', justifyContent:'center', margin:'0 auto 14px', fontSize:'1.4rem',
        }).html('<i class="fa-solid '+c.icon+'"></i>').appendTo($card);

        $('<div>').css({ fontWeight:700, fontSize:'1.05rem', color:'#111827', marginBottom:'6px' }).text(c.title).appendTo($card);
        $('<div>').css({ fontSize:'0.9rem', color:'#4b5563', lineHeight:1.5 }).text(msg).appendTo($card);
        $('<div>').css({ height:'3px', borderRadius:'2px', background:c.accent, marginTop:'20px', opacity:0.35 }).appendTo($card);

        requestAnimationFrame(function(){
            $backdrop.css('opacity', 1);
            $card.css({ opacity:1, transform:'scale(1) translateY(0)' });
        });

        setTimeout(function(){
            $backdrop.css('opacity', 0);
            $card.css({ opacity:0, transform:'scale(0.95) translateY(8px)' });
            setTimeout(function(){ $backdrop.remove(); }, 250);
        }, 2600);
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
    $(document).on('click', '.deduction-row', function(e){
        if ($(e.target).closest('.action-cell, td:first-child').length) return;
        var $r = $(this);
        pendingItemId = $r.data('id');

        $('#detail-body').html(
            '<table style="width:100%;border-collapse:collapse;">' +
            dRow('Employee',            escapeHtml($r.data('employee')) + ' (' + escapeHtml($r.data('empno')) + ')') +
            dRow('Department',          escapeHtml($r.data('dept') || '—')) +
            dRow('Period',              escapeHtml($r.data('period'))) +
            dRow('Unfiled Leave',       escapeHtml($r.data('unfiled')) + ' day(s)') +
            dRow('Tardiness',           escapeHtml($r.data('tardiness-minutes')) + ' min') +
            dRow('Undertime',           escapeHtml($r.data('undertime-minutes')) + ' min') +
            dRow('Suggested VL Deduction', '<strong style="color:#15803d;">' + escapeHtml($r.data('suggested')) + '</strong>') +
            dRow('Source Remarks',      $r.data('remarks') ? escapeHtml($r.data('remarks')) : '<span style="color:#d1d5db;">—</span>') +
            '</table>'
        );
        $('#detail-deduct-btn, #detail-dismiss-btn').data('row', {
            employee: $r.data('employee'), empno: $r.data('empno'), period: $r.data('period'), suggested: $r.data('suggested'),
        });
        document.getElementById('detail-modal').showModal();
    });

    $(document).on('click', '#detail-deduct-btn', function(){
        var row = $(this).data('row');
        document.getElementById('detail-modal').close();
        openDeductModal(pendingItemId, row);
    });

    $(document).on('click', '#detail-dismiss-btn', function(){
        var row = $(this).data('row');
        document.getElementById('detail-modal').close();
        openDismissModal(pendingItemId, row);
    });

    // ── Inline action buttons ──────────────────────────────────────────
    $(document).on('click', '.deduct-btn', function(){
        var $tr = $(this).closest('tr');
        openDeductModal($(this).data('id'), {
            employee: $tr.data('employee'), empno: $tr.data('empno'), period: $tr.data('period'), suggested: $tr.data('suggested'),
        });
    });

    $(document).on('click', '.dismiss-btn', function(){
        var $tr = $(this).closest('tr');
        openDismissModal($(this).data('id'), {
            employee: $tr.data('employee'), empno: $tr.data('empno'), period: $tr.data('period'), suggested: $tr.data('suggested'),
        });
    });

    // ── Deduct modal ──────────────────────────────────────────────────
    function openDeductModal(id, row) {
        pendingItemId = id;
        $('#deduct-summary').html(summaryHtml(row));
        document.getElementById('deduct-modal').showModal();
    }

    $(document).on('click', '#deduct-confirm-btn', function(){
        document.getElementById('deduct-modal').close();
        submitDeduct(pendingItemId);
    });

    function submitDeduct(id) {
        var $btn = $('#deduct-confirm-btn').prop('disabled', true);
        $.post('{{ url('/api/leave-manager/attendance-deductions') }}/' + id + '/deduct', { _token: '{{ csrf_token() }}' })
            .done(function(resp){
                if (resp && resp.success) {
                    showToast(resp.message || 'VL deducted.', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1400);
                } else {
                    showToast(resp && resp.error ? resp.error : 'Failed to deduct.', 'error');
                }
            })
            .fail(function(xhr){
                var msg = 'Failed to deduct VL.';
                try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                showToast(msg, 'error');
            })
            .always(function(){ $btn.prop('disabled', false); });
    }

    // ── Dismiss modal ───────────────────────────────────────────────────
    function openDismissModal(id, row) {
        pendingItemId = id;
        $('#dismiss-summary').html(summaryHtml(row));
        $('#dismiss-remarks').val('');
        $('#dismiss-remarks-error').hide();
        $('#dismiss-remarks').css('border-color', '#d1d5db');
        document.getElementById('dismiss-modal').showModal();
        setTimeout(function(){ document.getElementById('dismiss-remarks').focus(); }, 80);
    }

    $(document).on('click', '#dismiss-confirm-btn', function(){
        var remarks = $('#dismiss-remarks').val().trim();
        if (!remarks) {
            $('#dismiss-remarks-error').show();
            $('#dismiss-remarks').css('border-color', '#ef4444').focus();
            return;
        }
        document.getElementById('dismiss-modal').close();
        submitDismiss(pendingItemId, remarks);
    });

    $(document).on('input', '#dismiss-remarks', function(){
        if ($(this).val().trim()) { $('#dismiss-remarks-error').hide(); $(this).css('border-color', '#d1d5db'); }
    });

    function submitDismiss(id, remarks) {
        $.post('{{ url('/api/leave-manager/attendance-deductions') }}/' + id + '/dismiss', { _token: '{{ csrf_token() }}', remarks: remarks })
            .done(function(resp){
                if (resp && resp.success) {
                    showToast('Item dismissed.', 'success');
                    setTimeout(function(){ window.location.reload(); }, 1400);
                } else {
                    showToast(resp && resp.error ? resp.error : 'Failed to dismiss.', 'error');
                }
            })
            .fail(function(xhr){
                var msg = 'Failed to dismiss item.';
                try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                showToast(msg, 'error');
            });
    }

    // ── Bulk deduct ───────────────────────────────────────────────────
    $(document).on('click', '#bulk-deduct-btn', function(){
        var ids = getSelectedIds();
        if (!ids.length) return;
        $('#bulk-deduct-count-label').text('Deducting for ' + ids.length + ' employee' + (ids.length > 1 ? 's' : ''));
        document.getElementById('bulk-deduct-modal').showModal();
    });

    $(document).on('click', '#bulk-deduct-confirm-btn', function(){
        var ids = getSelectedIds();
        document.getElementById('bulk-deduct-modal').close();
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: '{{ route('api.leave-manager.attendance-deductions.bulk-deduct') }}',
            method: 'POST',
            data: { item_ids: ids, _token: '{{ csrf_token() }}' },
            dataType: 'json'
        }).done(function(resp){
            if (resp && resp.success) {
                var msg = resp.processed_count + ' employee' + (resp.processed_count !== 1 ? 's' : '') + ' deducted.';
                if (resp.errors && resp.errors.length) msg += ' ' + resp.errors.length + ' failed.';
                showToast(msg, resp.errors && resp.errors.length ? 'error' : 'success');
                setTimeout(function(){ window.location.reload(); }, 1400);
            } else {
                showToast(resp && resp.error ? resp.error : 'Bulk deduct failed.', 'error');
            }
        }).fail(function(xhr){
            var msg = 'Bulk deduct failed.';
            try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
            showToast(msg, 'error');
        }).always(function(){ $btn.prop('disabled', false); });
    });

    // ── Bulk dismiss ────────────────────────────────────────────────────
    $(document).on('click', '#bulk-dismiss-btn', function(){
        var ids = getSelectedIds();
        if (!ids.length) return;
        $('#bulk-dismiss-count-label').text('Dismissing ' + ids.length + ' item' + (ids.length > 1 ? 's' : ''));
        $('#bulk-dismiss-remarks').val('');
        $('#bulk-dismiss-remarks-error').hide();
        $('#bulk-dismiss-remarks').css('border-color', '#d1d5db');
        document.getElementById('bulk-dismiss-modal').showModal();
        setTimeout(function(){ document.getElementById('bulk-dismiss-remarks').focus(); }, 80);
    });

    $(document).on('click', '#bulk-dismiss-confirm-btn', function(){
        var remarks = $('#bulk-dismiss-remarks').val().trim();
        if (!remarks) {
            $('#bulk-dismiss-remarks-error').show();
            $('#bulk-dismiss-remarks').css('border-color', '#ef4444').focus();
            return;
        }
        var ids = getSelectedIds();
        document.getElementById('bulk-dismiss-modal').close();
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: '{{ route('api.leave-manager.attendance-deductions.bulk-dismiss') }}',
            method: 'POST',
            data: { item_ids: ids, remarks: remarks, _token: '{{ csrf_token() }}' },
            dataType: 'json'
        }).done(function(resp){
            if (resp && resp.success) {
                var msg = resp.processed_count + ' item' + (resp.processed_count !== 1 ? 's' : '') + ' dismissed.';
                if (resp.errors && resp.errors.length) msg += ' ' + resp.errors.length + ' failed.';
                showToast(msg, 'success');
                setTimeout(function(){ window.location.reload(); }, 1400);
            } else {
                showToast(resp && resp.error ? resp.error : 'Bulk dismiss failed.', 'error');
            }
        }).fail(function(xhr){
            var msg = 'Bulk dismiss failed.';
            try { var j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
            showToast(msg, 'error');
        }).always(function(){ $btn.prop('disabled', false); });
    });
})(jQuery);
</script>
@endsection
