@extends('dashboards.layout', [
    'title'    => 'Approved Leaves',
    'subtitle' => 'All currently approved leave requests',
])

@section('content')
<section class="card">
    <header class="ll-page-header">
        <div class="ll-page-header-icon"><i class="fas fa-calendar-check"></i></div>
        <div>
            <h2>Approved Leaves</h2>
            <p class="ll-page-subtitle">All currently approved leave requests</p>
        </div>
    </header>

    <div class="card-body">
        <p class="ll-edit-hint"><i class="fas fa-circle-info fa-fw"></i> Use the filters below to narrow by year, month, leave type, or employee.</p>

        <div class="ll-filter-bar">
            <div class="ll-field">
                <label for="filter-year">Year</label>
                <select id="filter-year" class="ll-select">
                    <option value="all" @selected($currentYear === null)>All years</option>
                    @foreach($years as $y)
                        <option value="{{ $y }}" @selected($y == $currentYear)>{{ $y }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ll-field">
                <label for="filter-month">Month</label>
                <select id="filter-month" class="ll-select">
                    <option value="all" @selected($currentMonth === null)>All months</option>
                    @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn)
                        <option value="{{ $mi + 1 }}" @selected($mi + 1 == $currentMonth)>{{ $mn }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ll-field">
                <label for="filter-type">Leave Type</label>
                <select id="filter-type" class="ll-select">
                    <option value="">All types</option>
                    @foreach(['Vacation Leave','Sick Leave','Wellness Leave','Special Privilege Leave','CTO','Solo Parent Leave'] as $lt)
                        <option value="{{ $lt }}" @if(request('type') === $lt) selected @endif>{{ $lt }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ll-field ll-field--grow">
                <label for="alEmployeeSearch">Employee</label>
                <div class="ll-input-icon-wrap">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" id="alEmployeeSearch" class="ll-input" placeholder="Type name or EmpNo to search" autocomplete="off">
                    <input type="hidden" id="alEmployee" value="">
                    <div id="alEmployee_suggestions" class="ll-suggestions"></div>
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

        <div class="hris-table-card">
            <div class="hris-table-wrapper">
                <table id="approved-leaves-table" class="hris-table ll-approved-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Leave Type</th>
                            <th>Period</th>
                            <th class="text-center">Days</th>
                            <th>Filed On</th>
                            <th>Cancellation</th>
                            <th class="text-center">Actions</th>
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
                                $period   = $leave->formattedPeriod();
                                $leaveDatesBreakdown = $leave->leaveDatesBreakdown();
                                // Whole-row status takes priority; a partial (per-date) cancellation
                                // has no whole-row status of its own, so fall back to whichever
                                // stage its pending dates are at.
                                $cancellationDisplay = $leave->cancellation_status ?? $leave->pendingCancellationDates->first()?->cancellation_status;
                            @endphp
                            <tr
                                data-id="{{ $leave->id }}"
                                data-employee="{{ $empName }}"
                                data-leave-type="{{ strtoupper($leave->leave_type ?? '') }}"
                                data-leave-dates="{{ $leaveDatesBreakdown->toJson() }}"
                                data-period="{{ $period }}"
                                data-days="{{ $leave->total_days ?? '-' }}"
                                data-filed="{{ $leave->date_filed ? \Carbon\Carbon::parse($leave->date_filed)->format('M d, Y') : '-' }}"
                                data-reason="{{ e($leave->reason ?? '') }}"
                                data-cancellation="{{ e($cancellationDisplay ?? '') }}"
                            >
                                <td><span class="ll-emp-name">{{ $empName }}</span></td>
                                <td class="muted">{{ $deptName }}</td>
                                <td><span class="ll-badge ll-badge--neutral">{{ strtoupper($leave->leave_type ?? '') }}</span></td>
                                <td>{{ $period }}</td>
                                <td class="text-center"><span class="ll-balance-chip">{{ $leave->total_days ?? '-' }}</span></td>
                                <td>{{ $leave->date_filed ? \Carbon\Carbon::parse($leave->date_filed)->format('M d, Y') : '-' }}</td>
                                <td>
                                    @if($leave->reschedule_status === 'Rescheduled')
                                        <span class="ll-badge ll-badge--neutral">Rescheduled</span>
                                    @elseif($leave->reschedule_status === 'Pending Reschedule')
                                        <span class="ll-badge ll-badge--warning">Reschedule Pending</span>
                                    @elseif($cancellationDisplay)
                                        <span class="ll-badge ll-badge--warning">{{ $cancellationDisplay }}</span>
                                    @else
                                        <span class="muted">-</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <button type="button" class="hris-btn-secondary hris-btn-sm view-leave-btn">
                                        <i class="fas fa-eye fa-fw"></i> View
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

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
        ],
        dom: 'rt',
        language: { emptyTable: 'No approved leave records found for this period.' },
    });

    // ── View details ───────────────────────────────────────────────────
    // Renders either the flat comma-joined leave_type string, or - when a multi-date
    // filing assigned more than one distinct type across its dates - a small per-date
    // table (Date | Type | Days) so it's clear which date got which type.
    function leaveTypeCellHtml(flatType, dates) {
        var types = (dates || []).map(function (d) { return d.leave_type; })
            .filter(function (v, i, arr) { return v && arr.indexOf(v) === i; });
        if (!dates || !dates.length || types.length <= 1) {
            return flatType || '-';
        }
        var rows = dates.map(function (d) {
            return '<tr><td style="padding:2px 6px">' + d.label + '</td><td style="padding:2px 6px">' + d.leave_type + '</td><td style="padding:2px 6px;text-align:right">' + d.days + '</td></tr>';
        }).join('');
        return '<table style="width:100%;border-collapse:collapse;font-size:0.85rem">'
            + '<thead><tr><th style="text-align:left;padding:2px 6px">Date</th><th style="text-align:left;padding:2px 6px">Type</th><th style="text-align:right;padding:2px 6px">Days</th></tr></thead>'
            + '<tbody>' + rows + '</tbody></table>';
    }

    $(document).on('click', '.view-leave-btn', function () {
        var row     = $(this).closest('tr');
        var id      = row.data('id');
        var emp     = row.data('employee');
        var type    = row.data('leave-type');
        var dates   = row.data('leave-dates');
        var period  = row.data('period');
        var days    = row.data('days');
        var filed   = row.data('filed');
        var reason  = row.data('reason') || '-';
        var cancel  = row.data('cancellation') || '-';

        Swal.fire({
            title: 'Leave Request #' + id,
            html:
                '<table style="width:100%;text-align:left;border-collapse:collapse;font-size:0.92rem">' +
                '<tr><td style="padding:5px 8px;color:#64748b;width:40%">Employee</td><td style="padding:5px 8px;font-weight:600">' + emp + '</td></tr>' +
                '<tr style="background:#f8fafc"><td style="padding:5px 8px;color:#64748b">Leave Type</td><td style="padding:5px 8px">' + leaveTypeCellHtml(type, dates) + '</td></tr>' +
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
        var year  = $('#filter-year').val()  || '';
        var month = $('#filter-month').val() || '';
        var type  = $('#filter-type').val()  || '';
        var emp   = $('#alEmployee').val()   || '';
        var parts = [];
        parts.push('year='  + encodeURIComponent(year));
        parts.push('month=' + encodeURIComponent(month));
        if (type) parts.push('type=' + encodeURIComponent(type));
        if (emp)  parts.push('emp='  + encodeURIComponent(emp));
        return '{{ route('leave-manager.approved-leaves') }}' + (parts.length ? '?' + parts.join('&') : '');
    }

    $(document).on('change', '#filter-year, #filter-month, #filter-type', function () {
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
                    var label = (r.FullName || r.EmpNo) + (r.Position ? ' - ' + r.Position : '') + ' (' + r.EmpNo + ')';
                    var $it = $('<div class="ll-suggestion-item">').text(label);
                    $it.data('empno', r.EmpNo).data('label', label);
                    $it.on('click', function () {
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
        var $box = $('#alEmployee_suggestions'), $items = $box.children('.ll-suggestion-item');
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
