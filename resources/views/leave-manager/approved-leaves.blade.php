@extends('dashboards.layout', [
    'title'    => 'Approved Leaves',
    'subtitle' => 'All currently approved leave requests',
])

@section('content')
<section class="card">
    <header>
        <h2>Approved Leaves</h2>
    </header>

    <div class="card-body">
        <p class="muted">Leave requests that are fully approved and on record. Use the filters below to narrow by month, leave type, or employee.</p>

        <div class="filter-bar">
            <div class="filter-field">
                <label for="filter-month" class="small mb-1">Month</label>
                @php
                    $monthOptions = [];
                    for ($i = 0; $i < 12; $i++) {
                        $m     = date('Y-m', strtotime("-{$i} months"));
                        $label = date('F Y', strtotime($m . '-01'));
                        $monthOptions[$m] = $label;
                    }
                @endphp
                <select id="filter-month" class="form-control form-control-sm">
                    @foreach($monthOptions as $val => $lbl)
                        <option value="{{ $val }}" @if($val === $currentMonth) selected @endif>{{ $lbl }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter-field">
                <label for="filter-type" class="small mb-1">Leave Type</label>
                <select id="filter-type" class="form-control form-control-sm" style="min-width:140px">
                    <option value="">All types</option>
                    @foreach(['Vacation Leave','Sick Leave','Wellness Leave','Special Privilege Leave','CTO','Solo Parent Leave'] as $lt)
                        <option value="{{ $lt }}" @if(request('type') === $lt) selected @endif>{{ $lt }}</option>
                    @endforeach
                </select>
            </div>

            <div class="filter-field" style="flex:1">
                <div class="filter-emp-row">
                    <label for="alEmployeeSearch" class="filter-label-emp mb-0">Employee</label>
                    <input type="text" id="alEmployeeSearch" class="form-control form-control-lg filter-input-emp" placeholder="Type name or EmpNo to search" autocomplete="off">
                    <input type="hidden" id="alEmployee" value="">
                    <div id="alEmployee_suggestions" class="list-group" style="position:absolute;z-index:1050;top:100%;left:0;right:0;display:none;max-height:240px;overflow:auto"></div>
                </div>
            </div>
        </div>

        {{-- Top pagination bar --}}
        @if($leaves->total() > 0)
        <div class="paginate-bar paginate-bar--top">
            <span class="paginate-summary">
                Showing {{ $leaves->firstItem() }}–{{ $leaves->lastItem() }} of {{ $leaves->total() }} records
            </span>
            {{ $leaves->appends(request()->query())->links() }}
        </div>
        @endif

        <div class="table-responsive">
            <table id="approved-leaves-table" class="hris-table" style="width:100%">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Leave Type</th>
                        <th>Period</th>
                        <th>Days</th>
                        <th>Filed On</th>
                        <th>Cancellation</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($leaves as $leave)
                        @php
                            $empName  = $leave->user
                                ? trim(($leave->user->last_name ?? '') . ', ' . ($leave->user->first_name ?? ''))
                                : '-';
                            $deptName = ($leave->user && isset($departments[$leave->user->Dept_id]))
                                ? $departments[$leave->user->Dept_id]
                                : '-';
                            $period   = ($leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->format('M d, Y') : '-')
                                      . ' – '
                                      . ($leave->end_date   ? \Carbon\Carbon::parse($leave->end_date)->format('M d, Y')   : '-');
                        @endphp
                        <tr
                            data-id="{{ $leave->id }}"
                            data-employee="{{ $empName }}"
                            data-leave-type="{{ strtoupper($leave->leave_type ?? '') }}"
                            data-period="{{ $period }}"
                            data-days="{{ $leave->total_days ?? '-' }}"
                            data-filed="{{ $leave->date_filed ? \Carbon\Carbon::parse($leave->date_filed)->format('M d, Y') : '-' }}"
                            data-reason="{{ e($leave->reason ?? '') }}"
                            data-cancellation="{{ e($leave->cancellation_status ?? '') }}"
                        >
                            <td>{{ $empName }}</td>
                            <td>{{ $deptName }}</td>
                            <td class="text-center">{{ strtoupper($leave->leave_type ?? '') }}</td>
                            <td class="text-center" style="white-space:nowrap">{{ $period }}</td>
                            <td class="text-center">{{ $leave->total_days ?? '-' }}</td>
                            <td class="text-center">
                                {{ $leave->date_filed ? \Carbon\Carbon::parse($leave->date_filed)->format('M d, Y') : '-' }}
                            </td>
                            <td class="text-center">
                                @if($leave->reschedule_status === 'Rescheduled')
                                    <span style="font-size:0.82rem;background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;padding:2px 8px;border-radius:4px;font-weight:600">Rescheduled</span>
                                @elseif($leave->reschedule_status === 'Pending Reschedule')
                                    <span style="font-size:0.82rem;color:#92400e;font-weight:600">Reschedule Pending</span>
                                @elseif($leave->cancellation_status)
                                    <span style="font-size:0.82rem;color:#b45309;font-weight:600">{{ $leave->cancellation_status }}</span>
                                @else
                                    <span style="color:#94a3b8;font-size:0.82rem">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <button type="button" class="hris-btn hris-btn-sm hris-btn-secondary view-leave-btn">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($leaves->total() > 0)
            <div class="paginate-bar paginate-bar--bottom">
                <span class="paginate-summary">
                    Showing {{ $leaves->firstItem() }}–{{ $leaves->lastItem() }} of {{ $leaves->total() }} records
                </span>
                {{ $leaves->appends(request()->query())->links() }}
            </div>
            @endif
        </div>
    </div>
</section>
@endsection

@section('page_scripts_after')
<script>
$(function () {
    // ── DataTable ──────────────────────────────────────────────────────
    $('#approved-leaves-table').DataTable({
        paging:       false,
        autoWidth:    false,
        searching:    true,
        order:        [[5, 'desc']],
        columnDefs: [
            { orderable: false, targets: [6, 7] },
            { width: '7%',  targets: 4 },
            { width: '10%', targets: 5 },
            { width: '12%', targets: 6 },
            { width: '8%',  targets: 7 },
        ],
        dom: 'rt',
        language: { emptyTable: 'No approved leave records found for this period.' },
    });

    // ── View details ───────────────────────────────────────────────────
    $(document).on('click', '.view-leave-btn', function () {
        var row     = $(this).closest('tr');
        var id      = row.data('id');
        var emp     = row.data('employee');
        var type    = row.data('leave-type');
        var period  = row.data('period');
        var days    = row.data('days');
        var filed   = row.data('filed');
        var reason  = row.data('reason') || '—';
        var cancel  = row.data('cancellation') || '—';

        if (typeof Swal === 'undefined') {
            alert('Leave #' + id + '\nEmployee: ' + emp + '\nType: ' + type + '\nPeriod: ' + period + '\nDays: ' + days);
            return;
        }

        Swal.fire({
            title: 'Leave Request #' + id,
            html:
                '<table style="width:100%;text-align:left;border-collapse:collapse;font-size:0.92rem">' +
                '<tr><td style="padding:5px 8px;color:#64748b;width:40%">Employee</td><td style="padding:5px 8px;font-weight:600">' + emp + '</td></tr>' +
                '<tr style="background:#f8fafc"><td style="padding:5px 8px;color:#64748b">Leave Type</td><td style="padding:5px 8px">' + type + '</td></tr>' +
                '<tr><td style="padding:5px 8px;color:#64748b">Period</td><td style="padding:5px 8px">' + period + '</td></tr>' +
                '<tr style="background:#f8fafc"><td style="padding:5px 8px;color:#64748b">Total Days</td><td style="padding:5px 8px">' + days + '</td></tr>' +
                '<tr><td style="padding:5px 8px;color:#64748b">Filed On</td><td style="padding:5px 8px">' + filed + '</td></tr>' +
                '<tr style="background:#f8fafc"><td style="padding:5px 8px;color:#64748b">Reason</td><td style="padding:5px 8px">' + reason + '</td></tr>' +
                '<tr><td style="padding:5px 8px;color:#64748b">Cancellation</td><td style="padding:5px 8px">' + cancel + '</td></tr>' +
                '</table>',
            confirmButtonText: 'Close',
            confirmButtonColor: '#64748b',
            width: 480,
        });
    });

    // ── Filter navigation ──────────────────────────────────────────────
    function buildUrl() {
        var month = $('#filter-month').val() || '';
        var type  = $('#filter-type').val()  || '';
        var emp   = $('#alEmployee').val()   || '';
        var parts = [];
        if (month) parts.push('month=' + encodeURIComponent(month));
        if (type)  parts.push('type='  + encodeURIComponent(type));
        if (emp)   parts.push('emp='   + encodeURIComponent(emp));
        return '{{ route('leave-manager.approved-leaves') }}' + (parts.length ? '?' + parts.join('&') : '');
    }

    $(document).on('change', '#filter-month, #filter-type', function () {
        window.location.href = buildUrl();
    });

    // ── Employee autocomplete ──────────────────────────────────────────
    var alTimer = null, alIdx = -1;

    $('#alEmployeeSearch').on('input', function () {
        var q = $(this).val();
        $('#alEmployee').val('');
        if (alTimer) clearTimeout(alTimer);
        if (!q || q.length < 2) { alIdx = -1; $('#alEmployee_suggestions').hide().empty(); return; }
        alTimer = setTimeout(function () {
            $.getJSON('{{ route('api.employee.search') }}', { q: q }, function (rows) {
                var $box = $('#alEmployee_suggestions');
                $box.empty();
                if (!rows || !rows.length) { $box.hide(); return; }
                rows.forEach(function (r) {
                    var label = (r.FullName || r.EmpNo) + (r.Position ? ' — ' + r.Position : '') + ' (' + r.EmpNo + ')';
                    var $it = $('<a href="#" class="list-group-item list-group-item-action">' + label + '</a>');
                    $it.data('empno', r.EmpNo).data('label', label);
                    $it.on('click', function (e) {
                        e.preventDefault();
                        $('#alEmployee').val($(this).data('empno'));
                        $('#alEmployeeSearch').val($(this).data('label'));
                        $box.hide();
                        window.location.href = buildUrl();
                    });
                    $box.append($it);
                });
                $box.show(); alIdx = -1;
            });
        }, 200);
    });

    $('#alEmployeeSearch').on('keydown', function (e) {
        var $box = $('#alEmployee_suggestions'), $items = $box.children('.list-group-item');
        if (!$items.length) return;
        if      (e.key === 'ArrowDown')  { e.preventDefault(); alIdx = Math.min(alIdx + 1, $items.length - 1); $items.removeClass('active').eq(alIdx).addClass('active')[0].scrollIntoView({ block: 'nearest' }); }
        else if (e.key === 'ArrowUp')    { e.preventDefault(); alIdx = Math.max(alIdx - 1, 0); $items.removeClass('active').eq(alIdx).addClass('active')[0].scrollIntoView({ block: 'nearest' }); }
        else if (e.key === 'Enter')      { e.preventDefault(); if (alIdx >= 0) $items.eq(alIdx).trigger('click'); }
        else if (e.key === 'Escape')     { $box.hide(); }
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('#alEmployee_suggestions, #alEmployeeSearch').length) {
            $('#alEmployee_suggestions').hide();
        }
    });
});
</script>
@endsection
