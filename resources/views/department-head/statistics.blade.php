@extends('dashboards.layout', [
    'title' => 'Department Statistics',
    'subtitle' => 'Employee ETA / Locator Usage',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('content')
<div class="top">
    <div>
        <h1>Employee ETA / Locator Usage</h1>
    </div>
</div>

<section>
    <div class="card">
        @php
            $prev = (new DateTime())->setDate($year, $month, 1)->modify('-1 month');
            $next = (new DateTime())->setDate($year, $month, 1)->modify('+1 month');
        @endphp
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <div style="display:flex;gap:10px;align-items:center;">
                <button class="month-nav" id="prevMonthBtn" data-month="{{ $prev->format('n') }}" data-year="{{ $prev->format('Y') }}">&laquo; Prev</button>
                <div class="font-weight-bold month-label">{{ date('F', mktime(0,0,0,$month,1,$year)) }} {{ $year }}</div>
                <button class="month-nav" id="nextMonthBtn" data-month="{{ $next->format('n') }}" data-year="{{ $next->format('Y') }}">Next &raquo;</button>
            </div>
            <div>
                <button class="month-nav" id="monthToday">This Month</button>
            </div>
        </div>

        <div style="overflow:auto;">
            <table id="stats-table" class="stats-table leave-table hris-table" style="width:100%">
                <thead>
                    <tr>
                        <th>Employee Number</th>
                        <th>Employee Name</th>
                        <th>Department</th>
                        <th class="text-center">Leave</th>
                        <th class="text-center">ETA Usage</th>
                        <th class="text-center">Locator Usage</th>
                        <th class="text-center">Total Usage</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div style="margin-top:12px;color:#64748b;font-size:0.95rem;">Showing ETA, Locator and Leave usage based on approved applications</div>
    </div>
</section>

<!-- Modals using native dialog -->
<dialog id="etaUsageModal" class="employee-modal" style="max-width:900px;width:90%;">
    <div class="dialog-header">
        <h3 class="dialog-title">ETA Usage Details</h3>
        <button class="dialog-close" aria-label="Close" onclick="document.getElementById('etaUsageModal').close()">✕</button>
    </div>
    <div class="dialog-body">
        <div style="overflow:auto;">
            <table class="stats-table dialog-table leave-table" style="min-width:600px;">
                <thead><tr><th>Travel Date</th><th>Business Type</th><th>Destination</th><th>Travel Detail</th></tr></thead>
                <tbody id="etaModalBody"><tr><td colspan="4" class="text-center text-muted">No records.</td></tr></tbody>
            </table>
        </div>
    </div>
</dialog>

<dialog id="locatorUsageModal" class="employee-modal" style="max-width:1000px;width:95%;">
    <div class="dialog-header">
        <h3 class="dialog-title">Locator Usage Details</h3>
        <button class="dialog-close" aria-label="Close" onclick="document.getElementById('locatorUsageModal').close()">✕</button>
    </div>
    <div class="dialog-body">
        <div style="overflow:auto;">
            <table class="stats-table dialog-table leave-table" style="min-width:800px;">
                <thead><tr><th>Travel Date</th><th>Intended Departure</th><th>Intended Arrival</th><th>Destination</th><th>Business Type</th><th>Travel Detail</th><th>Arrival Time</th></tr></thead>
                <tbody id="locatorModalBody"><tr><td colspan="7" class="text-center text-muted">No records.</td></tr></tbody>
            </table>
        </div>
    </div>
</dialog>

<dialog id="leaveUsageModal" class="employee-modal" style="max-width:900px;width:90%;">
    <div class="dialog-header">
        <h3 class="dialog-title">Leave Usage Details</h3>
        <button class="dialog-close" aria-label="Close" onclick="document.getElementById('leaveUsageModal').close()">✕</button>
    </div>
    <div class="dialog-body">
        <div style="overflow:auto;">
            <table class="stats-table dialog-table leave-table" style="min-width:700px;">
                <thead><tr><th>Start Date</th><th>End Date</th><th>Leave Type</th><th>Days</th><th>Reason</th></tr></thead>
                <tbody id="leaveModalBody"><tr><td colspan="5" class="text-center text-muted">No records.</td></tr></tbody>
            </table>
        </div>
    </div>
</dialog>
@endsection

@section('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var currentMonth = {{ $month }};
    var currentYear  = {{ $year }};
    var apiUrl       = @json($apiUrl);
    var detailsUrl   = @json($detailsUrl);

    function formatBadge(count, type) {
        var cls = 'badge-usage';
        if (type === 'eta')     cls += count > 0 ? ' info' : '';
        if (type === 'locator') cls += count > 0 ? ' warning' : '';
        if (type === 'total')   cls += count >= 5 ? ' danger' : (count >= 3 ? ' warning' : ' success');
        return '<span class="' + cls + '">' + count + '</span>';
    }

    function formatDateStr(dateStr) {
        if (!dateStr) return '';
        try {
            var s = String(dateStr).trim();
            if (s.indexOf(' ') !== -1 && s.indexOf('T') === -1 && s.indexOf('-') !== -1) s = s.replace(' ', 'T');
            var d = new Date(s);
            if (isNaN(d)) return dateStr;
            return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        } catch (e) { return dateStr; }
    }

    function formatTimeStr(timeStr) {
        if (!timeStr) return '';
        try {
            var s = String(timeStr).trim();
            if (/^\d{1,2}:\d{2}(:\d{2})?$/.test(s)) s = '1970-01-01T' + s;
            else if (s.indexOf(' ') !== -1 && s.indexOf('T') === -1) s = s.replace(' ', 'T');
            var d = new Date(s);
            if (isNaN(d)) return timeStr;
            return d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', hour12: true });
        } catch (e) { return timeStr; }
    }

    function updateNavButtons(month, year) {
        var cur  = new Date(year, month - 1, 1);
        var prev = new Date(cur); prev.setMonth(cur.getMonth() - 1);
        var next = new Date(cur); next.setMonth(cur.getMonth() + 1);
        var prevBtn = document.getElementById('prevMonthBtn');
        var nextBtn = document.getElementById('nextMonthBtn');
        if (prevBtn) { prevBtn.dataset.month = prev.getMonth() + 1; prevBtn.dataset.year = prev.getFullYear(); }
        if (nextBtn) { nextBtn.dataset.month = next.getMonth() + 1; nextBtn.dataset.year = next.getFullYear(); }
    }

    function setMonth(month, year) {
        currentMonth = month;
        currentYear  = year;
        var label = document.querySelector('.month-label');
        if (label) {
            label.textContent = new Date(year, month - 1).toLocaleString(undefined, { month: 'long' }) + ' ' + year;
        }
        updateNavButtons(month, year);
        statsTable.ajax.reload(null, false);
    }

    document.getElementById('prevMonthBtn').addEventListener('click', function () {
        setMonth(parseInt(this.dataset.month, 10), parseInt(this.dataset.year, 10));
    });
    document.getElementById('nextMonthBtn').addEventListener('click', function () {
        setMonth(parseInt(this.dataset.month, 10), parseInt(this.dataset.year, 10));
    });
    document.getElementById('monthToday').addEventListener('click', function () {
        var now = new Date();
        setMonth(now.getMonth() + 1, now.getFullYear());
    });

    // ── DataTable init ───────────────────────────────────────────────────
    if ($.fn.DataTable.isDataTable('#stats-table')) {
        $('#stats-table').DataTable().clear().destroy();
    }
    var statsTable = $('#stats-table').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: apiUrl,
            data: function (d) {
                d.month = currentMonth;
                d.year  = currentYear;
            },
        },
        columns: [
            { data: 'EmpNo', title: 'Employee Number' },
            {
                data: null, title: 'Employee Name', orderable: false,
                render: function (data, type, row) {
                    return [row.Lname, row.Fname, row.Mname, row.Extension].filter(Boolean).join(', ');
                },
            },
            { data: 'Dept', title: 'Department' },
            {
                data: 'leave_count', title: 'Leave', orderable: false, className: 'text-center',
                render: function (data, type, row) {
                    if (type !== 'display') return data;
                    var cnt = parseInt(data) || 0;
                    return '<a href="#" class="usage-link" data-emp="' + row.EmpNo + '" data-type="Leave" data-month="' + currentMonth + '" data-year="' + currentYear + '">' + formatBadge(cnt, 'total') + '</a>';
                },
            },
            {
                data: 'eta_count', title: 'ETA Usage', orderable: false, className: 'text-center',
                render: function (data, type, row) {
                    if (type !== 'display') return data;
                    var cnt = parseInt(data) || 0;
                    return '<a href="#" class="usage-link" data-emp="' + row.EmpNo + '" data-type="ETA" data-month="' + currentMonth + '" data-year="' + currentYear + '">' + formatBadge(cnt, 'eta') + '</a>';
                },
            },
            {
                data: 'locator_count', title: 'Locator Usage', orderable: false, className: 'text-center',
                render: function (data, type, row) {
                    if (type !== 'display') return data;
                    var cnt = parseInt(data) || 0;
                    return '<a href="#" class="usage-link" data-emp="' + row.EmpNo + '" data-type="Locator" data-month="' + currentMonth + '" data-year="' + currentYear + '">' + formatBadge(cnt, 'locator') + '</a>';
                },
            },
            {
                data: 'total_usage', title: 'Total Usage', orderable: false, className: 'text-center',
                render: function (data, type, row) {
                    if (type !== 'display') return data;
                    return formatBadge(parseInt(data) || 0, 'total');
                },
            },
        ],
        language: { emptyTable: 'No ETA / Locator usage records found.' },
    });

    function showDetailError(type, message) {
        var msg = message || 'No data available. Please try again later.';
        var errorHtml = '<tr><td colspan="10" class="text-center" style="color:#dc2626;padding:20px 12px;">' +
            '<span style="display:inline-flex;align-items:center;gap:6px;font-size:0.95rem;">' +
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16"><path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14zm0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16z"/><path d="M7.002 11a1 1 0 1 1 2 0 1 1 0 0 1-2 0zM7.1 4.995a.905.905 0 1 1 1.8 0l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 4.995z"/></svg>' +
            msg + '</span></td></tr>';
        var dlgId, bodyId;
        if (type === 'ETA')         { dlgId = 'etaUsageModal';     bodyId = 'etaModalBody';     }
        else if (type === 'Locator') { dlgId = 'locatorUsageModal'; bodyId = 'locatorModalBody'; }
        else                         { dlgId = 'leaveUsageModal';   bodyId = 'leaveModalBody';   }
        var body = document.getElementById(bodyId);
        if (body) body.innerHTML = errorHtml;
        var dlg = document.getElementById(dlgId);
        if (dlg && dlg.showModal) dlg.showModal();
    }

    // ── Usage detail modal (event delegation) ───────────────────────────
    document.addEventListener('click', function (e) {
        var usage = e.target.closest('.usage-link');
        if (!usage) return;
        e.preventDefault();
        var empNo = usage.dataset.emp;
        var type  = usage.dataset.type;
        var month = usage.dataset.month || currentMonth;
        var year  = usage.dataset.year  || currentYear;
        var url   = new URL(detailsUrl, window.location.origin);
        url.searchParams.set('empNo', empNo);
        url.searchParams.set('type', type);
        url.searchParams.set('month', month);
        url.searchParams.set('year', year);
        fetch(url.toString(), { credentials: 'include' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (resp) {
                if (!resp || !resp.success) { showDetailError(type, resp && resp.message ? resp.message : null); return; }
                if (type === 'ETA') {
                    var body = document.getElementById('etaModalBody');
                    body.innerHTML = '';
                    if (!resp.data.length) {
                        body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No records for selected month.</td></tr>';
                    } else {
                        resp.data.forEach(function (r) {
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td>' + formatDateStr(r.travel_date) + '</td><td>' + (r.business_type || '') + '</td><td>' + (r.destination || '') + '</td><td>' + (r.travel_detail || '') + '</td>';
                            body.appendChild(tr);
                        });
                    }
                    var dlg = document.getElementById('etaUsageModal');
                    if (dlg && dlg.showModal) dlg.showModal();
                } else if (type === 'Locator') {
                    var body = document.getElementById('locatorModalBody');
                    body.innerHTML = '';
                    if (!resp.data.length) {
                        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No records for selected month.</td></tr>';
                    } else {
                        resp.data.forEach(function (r) {
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td>' + formatDateStr(r.travel_date) + '</td><td>' + formatTimeStr(r.intended_departure) + '</td><td>' + formatTimeStr(r.intended_arrival) + '</td><td>' + (r.destination || '') + '</td><td>' + (r.business_type || '') + '</td><td>' + (r.travel_detail || '') + '</td><td>' + (formatTimeStr(r.Arrival_Time) || formatTimeStr(r.arrival_date) || '') + '</td>';
                            body.appendChild(tr);
                        });
                    }
                    var dlg = document.getElementById('locatorUsageModal');
                    if (dlg && dlg.showModal) dlg.showModal();
                } else if (type === 'Leave') {
                    var body = document.getElementById('leaveModalBody');
                    body.innerHTML = '';
                    if (!resp.data.length) {
                        body.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No records for selected month.</td></tr>';
                    } else {
                        resp.data.forEach(function (r) {
                            var tr = document.createElement('tr');
                            tr.innerHTML = '<td>' + formatDateStr(r.start_date) + '</td><td>' + formatDateStr(r.end_date) + '</td><td>' + (r.leave_type || '') + '</td><td>' + (r.total_days || '') + '</td><td>' + (r.reason || '') + '</td>';
                            body.appendChild(tr);
                        });
                    }
                    var dlg = document.getElementById('leaveUsageModal');
                    if (dlg && dlg.showModal) dlg.showModal();
                }
            })
            .catch(function (err) { console.error('Details fetch failed', err); showDetailError(type, 'No data available. Please try again later.'); });
    });
});
</script>
@endsection
