@extends('dashboards.layout', [
        'title' => 'Employee Cancellation Requests',
        'subtitle' => 'Review pending employee leave cancellation requests'
])

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
                                <option value="{{ $val }}" @if($val === request('month', date('Y-m'))) selected @endif>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="filter-field" style="flex:1">                        
                        <div style="display: flex; align-items: center; gap: 12px; position:relative; width:100%;">
                            <label for="claEmployeeSearch" class="filter-label-emp mb-0" style="font-size:1.18rem;font-weight:600; white-space:nowrap;">Employee</label>
                            <input type="text" id="claEmployeeSearch" class="form-control form-control-lg filter-input-emp" style="font-size:1.15rem; flex:1; min-width:0;" placeholder="Type name or EmpNo to search" autocomplete="off">
                            <input type="hidden" id="claEmployee" name="claEmployee" value="">
                            <div id="claEmployee_suggestions" class="list-group" style="position:absolute;z-index:1050;top:100%;left:0;right:0;display:none;max-height:240px;overflow:auto"></div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="employee-requests-table" class="leave-table">
                        <thead>
                            <tr>
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
                                    <td>{{ $requestItem->cancellation_reason ?? '-' }}</td>
                                    <td class="text-center">{{ ucfirst($requestItem->status ?? '-') }}</td>
                                    <td class="text-center">{{ $requestItem->cancellation_requested_at ? \Carbon\Carbon::parse($requestItem->cancellation_requested_at)->format('M d, Y H:i') : '-' }}</td>
                                    <td>
                                        <button class="btn btn-sm btn-success approve-cancellation-btn" data-id="{{ $requestItem->id }}">Approve</button>
                                        <button class="btn btn-sm btn-secondary reject-cancellation-btn" data-id="{{ $requestItem->id }}" style="margin-left:8px">Reject</button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
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
                    <div style="margin-top:10px">{{ $requests->appends(request()->query())->links() }}</div>
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
                if (count <= 0) {
                    badge.hide();
                } else {
                    badge.show();
                }
            });
    }

    $(function(){
        if ($.fn.dataTable && !$.fn.dataTable.isDataTable('#employee-requests-table')) {
            const $table = $('#employee-requests-table');
            try {
                $table.DataTable({
                    paging: false,
                    pageLength: 25,
                    lengthChange: true,
                    lengthMenu: [10, 25, 50, 100],
                    autoWidth: false,
                    order: [[1, 'asc']],
                    columnDefs: [
                        { orderable: false, targets: [4, 7] },
                        { width: '10%', targets: 0 },
                        { width: '8%', targets: 5 },
                        { width: '14%', targets: 6 }
                    ],
                    dom: 'rt<"bottom"ip>',
                    language: {
                        emptyTable: 'No employee cancellation requests found.'
                    }
                });
            } catch (e) {
                // DataTable init failed; suppress debug output in production
            }
        }

        pollPendingCancellationBadge();
        setInterval(pollPendingCancellationBadge, 20000);
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
                    const label = (r.FullName || r.EmpNo) + (r.Position ? (' — ' + r.Position) : '') + ' (' + r.EmpNo + ')';
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
    $(document).on('change', '#filter-month', function(){ applyFilters(); });

    function doApproveCancellation(id) {
        $.post(`{{ url('/api/leave') }}/${id}/approve-cancellation`, { _token: '{{ csrf_token() }}' })
            .done(function(resp){
                    if (resp && resp.success) {
                    if (typeof Swal !== 'undefined') Swal.fire('Approved', 'Cancellation approved and credits refunded.', 'success').then(()=>window.location.reload());
                    else { window.location.reload(); }
                } else {
                    const msg = resp && resp.error ? resp.error : 'Failed to approve';
                    if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error');
                    else {}
                }
            })
            .fail(function(xhr){
                let msg = 'Failed to approve cancellation.';
                try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error');
                else {}
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
                    else {}
                }
            })
            .fail(function(xhr){
                let msg = 'Failed to reject cancellation.';
                try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error');
                else {}
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
