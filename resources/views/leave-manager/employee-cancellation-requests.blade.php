@extends('dashboards.layout', [
        'title' => 'Employee Cancellation Requests',
        'subtitle' => 'Review pending employee leave cancellation requests'
])

@section('page_head')
<style>
/* Bulk action toolbar */
.bulk-toolbar {
    display: none;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    margin-bottom: 12px;
    flex-wrap: wrap;
}
.bulk-toolbar.visible { display: flex; }
.bulk-selected-label { font-weight: 600; color: #1d4ed8; font-size: 0.9rem; }
.bulk-clear-btn { background: none; border: none; color: #6b7280; cursor: pointer; font-size: 0.85rem; text-decoration: underline; padding: 0; }
</style>
@endsection

@section('content')
<section class="card">
        <header>
                <h2>Employee Cancellation Requests</h2>
        </header>

        <div class="card-body">
                <p class="muted">Pending cancellation requests submitted by employees. Approve to cancel the leave and refund credits, or reject to keep the approved leave intact.</p>

                <div class="filter-bar">
                    <div class="filter-field">
                        <label for="filter-month" class="small mb-1">Filter by month</label>
                        @php
                            $monthOptions = [];
                            for ($i = 0; $i < 12; $i++) {
                                $m = date('Y-m', strtotime("-{$i} months"));
                                $label = date('F Y', strtotime($m . '-01'));
                                $monthOptions[$m] = $label;
                            }
                        @endphp
                        <select id="filter-month" name="month" class="form-control form-control-sm">
                            @foreach($monthOptions as $val => $lbl)
                                <option value="{{ $val }}" @if($val === $currentMonth) selected @endif>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="filter-field" style="flex:1">
                        <div class="filter-emp-row">
                            <label for="claEmployeeSearch" class="filter-label-emp mb-0">Employee</label>
                            <input type="text" id="claEmployeeSearch" class="form-control form-control-lg filter-input-emp" placeholder="Type name or EmpNo to search" autocomplete="off">
                            <input type="hidden" id="claEmployee" name="claEmployee" value="">
                            <div id="claEmployee_suggestions" class="list-group" style="position:absolute;z-index:1050;top:100%;left:0;right:0;display:none;max-height:240px;overflow:auto"></div>
                        </div>
                    </div>
                </div>

                {{-- Bulk action toolbar (visible when ≥1 row selected) --}}
                <div class="bulk-toolbar" id="bulk-toolbar">
                    <span class="bulk-selected-label" id="bulk-count-label">0 selected</span>
                    <button type="button" class="hris-btn hris-btn-sm hris-btn-success" id="bulk-approve-btn">
                        <i class="fas fa-check"></i> Bulk Approve
                    </button>
                    <button type="button" class="hris-btn hris-btn-sm hris-btn-danger" id="bulk-reject-btn">
                        <i class="fas fa-times"></i> Bulk Reject
                    </button>
                    <button type="button" class="bulk-clear-btn" id="bulk-clear-btn">Clear selection</button>
                </div>

                {{-- Top pagination bar --}}
                @if($requests->total() > 0)
                <div class="paginate-bar paginate-bar--top">
                    <span class="paginate-summary">
                        Showing {{ $requests->firstItem() }}–{{ $requests->lastItem() }} of {{ $requests->total() }} requests
                    </span>
                    {{ $requests->appends(request()->query())->links() }}
                </div>
                @endif

                <div class="table-responsive">
                    <table id="employee-requests-table" class="hris-table">
                        <thead>
                            <tr>
                                <th style="width:36px"><input type="checkbox" id="select-all-cb" title="Select all visible rows"></th>
                                <th>Request ID</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Leave Type</th>
                                <th>Reason</th>
                                <th>Original Status</th>
                                <th>Requested At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($requests as $requestItem)
                                <tr>
                                    <td class="text-center"><input type="checkbox" class="row-select" value="{{ $requestItem->id }}"></td>
                                    <td class="text-center">{{ $requestItem->id }}</td>
                                    <td>
                                        @if($requestItem->user)
                                            {{ trim(($requestItem->user->last_name ?? '') . ', ' . ($requestItem->user->first_name ?? '')) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if($requestItem->user && !empty($departments[$requestItem->user->Dept_id] ?? ''))
                                            {{ $departments[$requestItem->user->Dept_id] }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="text-center">{{ strtoupper($requestItem->leave_type ?? '') }}</td>
                                    <td>
                                        @if(($requestItem->cancellation_reason ?? '') === 'Reported to work')
                                            <span style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:2px 8px;border-radius:4px;font-size:0.8rem;font-weight:600">Reported to work ✓</span>
                                        @else
                                            {{ $requestItem->cancellation_reason ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="text-center">{{ ucfirst($requestItem->status ?? '-') }}</td>
                                    <td class="text-center">{{ $requestItem->cancellation_requested_at ? \Carbon\Carbon::parse($requestItem->cancellation_requested_at)->format('M d, Y H:i') : '-' }}</td>
                                    <td>
                                        <button class="hris-btn hris-btn-primary hris-btn-sm approve-cancellation-btn" data-id="{{ $requestItem->id }}">Approve</button>
                                        <button class="hris-btn hris-btn-secondary hris-btn-sm reject-cancellation-btn" data-id="{{ $requestItem->id }}">Reject</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td></td>
                                    <td class="text-center">-</td>
                                    <td>-</td>
                                    <td class="text-center">-</td>
                                    <td class="text-center">-</td>
                                    <td>-</td>
                                    <td class="text-center">-</td>
                                    <td class="text-center">-</td>
                                    <td class="text-center">No employee cancellation requests found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if($requests->total() > 0)
                    <div class="paginate-bar paginate-bar--bottom">
                        <span class="paginate-summary">
                            Showing {{ $requests->firstItem() }}–{{ $requests->lastItem() }} of {{ $requests->total() }} requests
                        </span>
                        {{ $requests->appends(request()->query())->links() }}
                    </div>
                    @endif
                </div>
        </div>
</section>
@endsection

@section('page_scripts_after')
<script>
(function($){
    function pollPendingCancellationBadge() {
        const badge = $('.sidebar-badge[data-badge-key="pending_employee_cancellation_requests"]');
        if (!badge.length) return;
        $.getJSON('{{ route('api.leave-manager.pending-cancellation-count') }}')
            .done(function(resp){
                const count = parseInt(resp.count || 0, 10);
                badge.text(count);
                if (count <= 0) { badge.hide(); } else { badge.show(); }
            });
    }

    var dt = null;
    var dtConfig = {
        paging: false,
        pageLength: 25,
        lengthChange: true,
        lengthMenu: [10, 25, 50, 100],
        autoWidth: false,
        order: [[2, 'asc']],
        columnDefs: [
            { orderable: false, targets: [0, 5, 8] },
            { width: '36px', targets: 0 },
            { width: '10%', targets: 1 },
            { width: '8%', targets: 6 },
            { width: '14%', targets: 7 }
        ],
        dom: 'rt<"bottom"ip>',
        language: { emptyTable: 'No employee cancellation requests found.' }
    };

    function initDt() {
        if ($.fn.dataTable && !$.fn.dataTable.isDataTable('#employee-requests-table')) {
            try { dt = $('#employee-requests-table').DataTable(dtConfig); } catch(e) {}
        }
    }

    // ── Checkbox / selection management ───────────────────────────────
    function getSelectedIds() {
        return $('.row-select:checked').map(function(){ return parseInt($(this).val(), 10); }).get();
    }

    function updateBulkToolbar() {
        const count = getSelectedIds().length;
        if (count > 0) {
            $('#bulk-count-label').text(count + ' selected');
            $('#bulk-toolbar').addClass('visible');
        } else {
            $('#bulk-toolbar').removeClass('visible');
        }
    }

    $(document).on('change', '#select-all-cb', function(){
        const checked = $(this).is(':checked');
        $('.row-select').prop('checked', checked);
        updateBulkToolbar();
    });

    $(document).on('change', '.row-select', function(){
        const total = $('.row-select').length;
        const checked = $('.row-select:checked').length;
        $('#select-all-cb').prop('indeterminate', checked > 0 && checked < total);
        $('#select-all-cb').prop('checked', checked === total && total > 0);
        updateBulkToolbar();
    });

    $(document).on('click', '#bulk-clear-btn', function(){
        $('.row-select, #select-all-cb').prop('checked', false);
        $('#select-all-cb').prop('indeterminate', false);
        updateBulkToolbar();
    });

    // ── Bulk Approve ───────────────────────────────────────────────────
    $(document).on('click', '#bulk-approve-btn', function(){
        const ids = getSelectedIds();
        if (!ids.length) return;
        if (typeof Swal === 'undefined') {
            if (!confirm('Approve ' + ids.length + ' cancellation request(s)?')) return;
            doBulkApprove(ids);
            return;
        }
        Swal.fire({
            title: 'Approve ' + ids.length + ' request(s)?',
            text: 'Each selected leave will be cancelled and credits refunded.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, approve all',
            confirmButtonColor: '#16a34a'
        }).then(function(res){
            if (!res.isConfirmed) return;
            doBulkApprove(ids);
        });
    });

    function doBulkApprove(ids) {
        $.ajax({
            url: '{{ route('api.leave.bulk-approve-cancellations') }}',
            method: 'POST',
            data: { leave_ids: ids, _token: '{{ csrf_token() }}' },
            dataType: 'json'
        }).done(function(resp){
            if (resp && resp.success) {
                const msg = resp.processed + ' request(s) approved.' + (resp.errors && resp.errors.length ? ' ' + resp.errors.length + ' failed.' : '');
                if (typeof Swal !== 'undefined') Swal.fire('Done', msg, resp.errors && resp.errors.length ? 'warning' : 'success').then(()=>window.location.reload());
                else { alert(msg); window.location.reload(); }
            } else {
                const msg = (resp && resp.error) ? resp.error : 'Bulk approve failed.';
                if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error'); else alert(msg);
            }
        }).fail(function(xhr){
            let msg = 'Bulk approve failed.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
            if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error'); else alert(msg);
        });
    }

    // ── Bulk Reject ────────────────────────────────────────────────────
    $(document).on('click', '#bulk-reject-btn', function(){
        const ids = getSelectedIds();
        if (!ids.length) return;
        if (typeof Swal === 'undefined') {
            const remarks = prompt('Remarks for rejection (required):'); if (!remarks) return;
            doBulkReject(ids, remarks);
            return;
        }
        Swal.fire({
            title: 'Reject ' + ids.length + ' request(s)?',
            html: '<p>Enter remarks for all rejections:</p><input type="text" id="swal-bulk-remarks" class="swal2-input" placeholder="Manager remarks (required)">',
            showCancelButton: true,
            confirmButtonText: 'Reject all',
            confirmButtonColor: '#ef4444',
            preConfirm: function(){
                const val = document.getElementById('swal-bulk-remarks').value;
                if (!val || !val.trim()) { Swal.showValidationMessage('Remarks are required'); return false; }
                return val.trim();
            }
        }).then(function(res){
            if (!res.isConfirmed) return;
            doBulkReject(ids, res.value);
        });
    });

    function doBulkReject(ids, remarks) {
        $.ajax({
            url: '{{ route('api.leave.bulk-reject-cancellations') }}',
            method: 'POST',
            data: { leave_ids: ids, remarks: remarks, _token: '{{ csrf_token() }}' },
            dataType: 'json'
        }).done(function(resp){
            if (resp && resp.success) {
                const msg = resp.processed + ' request(s) rejected.' + (resp.errors && resp.errors.length ? ' ' + resp.errors.length + ' failed.' : '');
                if (typeof Swal !== 'undefined') Swal.fire('Done', msg, resp.errors && resp.errors.length ? 'warning' : 'success').then(()=>window.location.reload());
                else { alert(msg); window.location.reload(); }
            } else {
                const msg = (resp && resp.error) ? resp.error : 'Bulk reject failed.';
                if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error'); else alert(msg);
            }
        }).fail(function(xhr){
            let msg = 'Bulk reject failed.';
            try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
            if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error'); else alert(msg);
        });
    }

    // ── DataTable and filters ──────────────────────────────────────────
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function fetchRows(month, emp) {
        var params = { month: month };
        if (emp) params.emp = emp;

        var url = new URL(window.location.href);
        url.searchParams.set('month', month);
        if (emp) url.searchParams.set('emp', emp); else url.searchParams.delete('emp');
        window.history.replaceState({}, '', url.toString());

        $.ajax({
            url: '{{ route('leave-manager.employee-cancellation-requests') }}',
            data: params,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            success: function(resp) {
                if (dt) { dt.destroy(); dt = null; }
                var tbody = $('#employee-requests-table tbody');
                tbody.empty();
                // Reset selection
                $('#select-all-cb').prop('checked', false).prop('indeterminate', false);
                $('#bulk-toolbar').removeClass('visible');

                if (resp.rows && resp.rows.length) {
                    resp.rows.forEach(function(row) {
                        tbody.append(
                            '<tr>' +
                            '<td class="text-center"><input type="checkbox" class="row-select" value="' + escapeHtml(row.id) + '"></td>' +
                            '<td class="text-center">' + escapeHtml(row.id) + '</td>' +
                            '<td>' + escapeHtml(row.employee) + '</td>' +
                            '<td class="text-center">' + escapeHtml(row.department) + '</td>' +
                            '<td class="text-center">' + escapeHtml(row.leave_type) + '</td>' +
                            '<td>' + (row.cancellation_reason === 'Reported to work' ? '<span style="background:#dcfce7;color:#166534;border:1px solid #86efac;padding:2px 8px;border-radius:4px;font-size:0.8rem;font-weight:600">Reported to work ✓</span>' : escapeHtml(row.cancellation_reason || '-')) + '</td>' +
                            '<td class="text-center">' + escapeHtml(row.status) + '</td>' +
                            '<td class="text-center">' + escapeHtml(row.requested_at) + '</td>' +
                            '<td>' +
                                '<button class="hris-btn hris-btn-primary hris-btn-sm approve-cancellation-btn" data-id="' + row.id + '">Approve</button> ' +
                                '<button class="hris-btn hris-btn-secondary hris-btn-sm reject-cancellation-btn" data-id="' + row.id + '">Reject</button>' +
                            '</td>' +
                            '</tr>'
                        );
                    });
                }

                initDt();
            },
            error: function() {
                window.location.href = '{{ route('leave-manager.employee-cancellation-requests') }}?month=' + encodeURIComponent(month);
            }
        });
    }

    $(function(){
        initDt();
        pollPendingCancellationBadge();
        setInterval(pollPendingCancellationBadge, 20000);
    });

    $(document).on('change', '#filter-month', function(){
        fetchRows($(this).val(), $('#claEmployee').val() || '');
    });

    var claEmpTimer = null, claSuggestionIndex = -1;
    function resetClaSuggestions() { claSuggestionIndex = -1; $('#claEmployee_suggestions').hide().empty(); }
    $('#claEmployeeSearch').on('input', function(){
        const q = $(this).val(); $('#claEmployee').val(''); if (claEmpTimer) clearTimeout(claEmpTimer);
        if (!q || q.length < 2) { resetClaSuggestions(); return; }
        claEmpTimer = setTimeout(()=>{
            $.getJSON('{{ route('api.employee.search') }}', { q: q }, function(rows){
                const $box = $('#claEmployee_suggestions'); $box.empty(); if (!rows || !rows.length) { $box.hide(); return; }
                rows.forEach(r=>{
                    const label = (r.FullName || r.EmpNo) + (r.Position ? (' - ' + r.Position) : '') + ' (' + r.EmpNo + ')';
                    const $it = $(`<a href="#" class="list-group-item list-group-item-action">${label}</a>`);
                    $it.data('empno', r.EmpNo); $it.data('label', label);
                    $it.on('click', function(e){ e.preventDefault(); $('#claEmployee').val($(this).data('empno')); $('#claEmployeeSearch').val($(this).data('label')); $box.hide(); applyFilters(); });
                    $box.append($it);
                }); $box.show(); claSuggestionIndex = -1;
            });
        }, 200);
    });

    $('#claEmployeeSearch').on('keydown', function(e){
        const $box = $('#claEmployee_suggestions'); const $items = $box.children('.list-group-item'); if (!$items.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); claSuggestionIndex = Math.min(claSuggestionIndex+1, $items.length-1); $items.removeClass('active').eq(claSuggestionIndex).addClass('active')[0].scrollIntoView({block:'nearest'}); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); claSuggestionIndex = Math.max(claSuggestionIndex-1, 0); $items.removeClass('active').eq(claSuggestionIndex).addClass('active')[0].scrollIntoView({block:'nearest'}); }
        else if (e.key === 'Enter') { e.preventDefault(); if (claSuggestionIndex >= 0) $items.eq(claSuggestionIndex).trigger('click'); else { const empVal = $('#claEmployee').val()||''; if (empVal) { applyFilters(); return; } const txt = $('#claEmployeeSearch').val()||''; const m = txt.match(/\((\d+)\)\s*$/); if (m && m[1]) { $('#claEmployee').val(m[1]); applyFilters(); return; } $('#claEmployeeSearch').trigger('input'); } }
        else if (e.key === 'Escape') { $box.hide(); }
    });

    $(document).on('click', function(e){ if (!$(e.target).closest('#claEmployee_suggestions, #claEmployeeSearch').length) $('#claEmployee_suggestions').hide(); });

    function applyFilters(){ const month = $('#filter-month').val()||''; const emp = $('#claEmployee').val()||''; const params = []; if (month) params.push('month='+encodeURIComponent(month)); if (emp) params.push('emp='+encodeURIComponent(emp)); const url = '{{ route('leave-manager.employee-cancellation-requests') }}' + (params.length ? ('?'+params.join('&')) : ''); window.location.href = url; }

    // ── Single row approve/reject ──────────────────────────────────────
    function doApproveCancellation(id) {
        $.post(`{{ url('/api/leave') }}/${id}/approve-cancellation`, { _token: '{{ csrf_token() }}' })
            .done(function(resp){
                if (resp && resp.success) {
                    if (typeof Swal !== 'undefined') Swal.fire('Approved', 'Cancellation approved and credits refunded.', 'success').then(()=>window.location.reload());
                    else { window.location.reload(); }
                } else {
                    const msg = resp && resp.error ? resp.error : 'Failed to approve';
                    if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error');
                }
            })
            .fail(function(xhr){
                let msg = 'Failed to approve cancellation.';
                try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error');
            });
    }

    function doRejectCancellation(id, remarks) {
        $.post(`{{ url('/api/leave') }}/${id}/reject-cancellation`, { remarks: remarks, _token: '{{ csrf_token() }}' })
            .done(function(resp){
                if (resp && resp.success) {
                    if (typeof Swal !== 'undefined') Swal.fire('Rejected', 'Cancellation request rejected.', 'success').then(()=>window.location.reload());
                    else { window.location.reload(); }
                } else {
                    const msg = resp && resp.error ? resp.error : 'Failed to reject';
                    if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error');
                }
            })
            .fail(function(xhr){
                let msg = 'Failed to reject cancellation.';
                try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error');
            });
    }

    $(document).on('click', '.approve-cancellation-btn', function(){
        const leaveId = $(this).data('id');
        if (!leaveId) return;
        if (typeof Swal === 'undefined' || typeof Swal.fire !== 'function') { if (!confirm('Approve cancellation request?')) return; doApproveCancellation(leaveId); return; }
        Swal.fire({ title: 'Approve cancellation?', text: 'This will cancel the approved leave and refund credits.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, approve', confirmButtonColor: '#16a34a' })
            .then(function(res){ if (!res.isConfirmed) return; doApproveCancellation(leaveId); });
    });

    $(document).on('click', '.reject-cancellation-btn', function(){
        const leaveId = $(this).data('id');
        if (!leaveId) return;
        if (typeof Swal === 'undefined' || typeof Swal.fire !== 'function') {
            const remarks = prompt('Enter remarks for rejection:'); if (!remarks) return; doRejectCancellation(leaveId, remarks);
            return;
        }
        Swal.fire({ title: 'Reject cancellation?', input: 'text', inputPlaceholder: 'Manager remarks (required)', showCancelButton: true, confirmButtonText: 'Reject', inputValidator: (v) => { if (!v || !v.trim()) return 'Remarks required'; } })
            .then(function(res){ if (!res.isConfirmed) return; doRejectCancellation(leaveId, res.value); });
    });
})(jQuery);
</script>
@endsection
