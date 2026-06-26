@extends('dashboards.layout', [
    'title' => 'Approved Requests',
    'subtitle' => 'Previously approved requests',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('tiles')
    <article class="kpi-card accent-leave tab-card active" data-tab="leave">
        <div>
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid fa-calendar-check"></i></div>
                <div class="kpi-title">Leave</div>
            </div>
            <div class="kpi-meta">Approved leave applications</div>
        </div>
        <div class="tile-count" id="leave-total">-</div>
    </article>

    <article class="kpi-card accent-eta tab-card" data-tab="eta">
        <div>
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid fa-plane-departure"></i></div>
                <div class="kpi-title">ETA</div>
            </div>
            <div class="kpi-meta">Approved ETA requests</div>
        </div>
        <div class="tile-count" id="eta-total">-</div>
    </article>

    <article class="kpi-card accent-locator tab-card" data-tab="locator">
        <div>
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></div>
                <div class="kpi-title">Locator</div>
            </div>
            <div class="kpi-meta">Approved locator requests</div>
        </div>
        <div class="tile-count" id="locator-total">-</div>
    </article>
@endsection

@section('content')
    @if (!$dept)
        <div class="muted">No department found for your account. Ensure your employee number is set in the Departments table.</div>
    @else
        @php
            $prevDate = (new DateTime())->setDate($year, $month, 1)->modify('-1 month');
            $nextDate = (new DateTime())->setDate($year, $month, 1)->modify('+1 month');
        @endphp
        <div class="hris-table-filters" style="margin-bottom:14px;">
            <div class="hris-filter-left" style="align-items:center;">
                <button class="month-nav" onclick="window.location='?month={{ $prevDate->format('n') }}&year={{ $prevDate->format('Y') }}'">&laquo; Prev</button>
                <div class="font-weight-bold">{{ date('F', mktime(0,0,0,$month,1,$year)) }} {{ $year }}</div>
                <button class="month-nav" onclick="window.location='?month={{ $nextDate->format('n') }}&year={{ $nextDate->format('Y') }}'">Next &raquo;</button>
            </div>
            <div>
                <button class="month-nav" onclick="window.location='?month={{ date('n') }}&year={{ date('Y') }}'">This Month</button>
            </div>
        </div>

        <div id="tab-content">
            <div class="tab-pane" data-pane="leave">
                <div class="hris-table-card">
                    <div class="hris-table-wrapper">
                        <table id="leave-table" class="hris-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Leave Type</th>
                                    <th>Period</th>
                                    <th>Total Days</th>
                                    <th>Approved At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane" data-pane="eta" style="display:none">
                <div class="hris-table-card">
                    <div class="hris-table-wrapper">
                        <table id="eta-table" class="hris-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Departure</th>
                                    <th>Arrival</th>
                                    <th>Destination</th>
                                    <th>Approved At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane" data-pane="locator" style="display:none">
                <div class="hris-table-card">
                    <div class="hris-table-wrapper">
                        <table id="locator-table" class="hris-table" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Type</th>
                                    <th>Travel Date</th>
                                    <th>Location</th>
                                    <th>Approved At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif
@endsection

@section('modals')
<dialog id="approvedModal" class="employee-modal">
    <div class="dialog-header">
        <h3 class="dialog-title" id="approved-modal-title">Details</h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div id="approved-modal-body"></div>
    </div>
    <form method="dialog" class="modal-actions">
        <button class="hris-btn hris-btn-secondary" type="submit">Close</button>
        <button class="hris-btn hris-btn-primary" type="button" id="approved-modal-print">
            <i class="fa fa-print"></i> Print
        </button>
    </form>
</dialog>
@endsection

@section('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const currentMonth = {{ $month }};
    const currentYear  = {{ $year }};
    const leaveDataUrl   = @json($leaveDataUrl);
    const etaDataUrl     = @json($etaDataUrl);
    const locatorDataUrl = @json($locatorDataUrl);

    // Tab switching
    document.querySelectorAll('.tab-card').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('.tab-card').forEach(function (c) { c.classList.remove('active'); });
            card.classList.add('active');
            var tab = card.getAttribute('data-tab');
            document.querySelectorAll('.tab-pane').forEach(function (p) {
                p.style.display = (p.getAttribute('data-pane') === tab) ? '' : 'none';
            });
            if (tab === 'eta')     etaTable.columns.adjust().draw(false);
            if (tab === 'locator') locatorTable.columns.adjust().draw(false);
        });
    });

    // Row caches (current page only, for modal population)
    var leaveRows   = {};
    var etaRows     = {};
    var locatorRows = {};

    // ── Leave DataTable ──────────────────────────────────────────────────
    if ($.fn.DataTable.isDataTable('#leave-table')) {
        $('#leave-table').DataTable().clear().destroy();
    }
    var leaveTable = $('#leave-table').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: leaveDataUrl,
            data: function (d) {
                d.month = currentMonth;
                d.year  = currentYear;
            },
        },
        columns: [
            { data: 'employee',    title: 'Employee' },
            { data: 'leave_type',  title: 'Leave Type' },
            { data: 'period',      title: 'Period', orderable: false },
            { data: 'total_days',  title: 'Total Days', orderable: false },
            { data: 'approved_at', title: 'Approved At' },
            {
                data: null, title: 'Action', orderable: false, searchable: false,
                render: function (data, type, row) {
                    return '<div class="action-btns">'
                        + '<button class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openLeaveModal(' + row.id + ')"><i class="fa fa-eye"></i> View</button>'
                        + '<button class="hris-btn hris-btn-primary hris-btn-sm" onclick="printLeave(' + row.id + ')"><i class="fa fa-print"></i> Print</button>'
                        + '</div>';
                },
            },
        ],
        drawCallback: function () {
            var api = this.api();
            document.getElementById('leave-total').textContent = api.page.info().recordsTotal;
            leaveRows = {};
            api.rows().every(function () { var d = this.data(); leaveRows[d.id] = d; });
        },
        language: { emptyTable: 'No approved leave requests.' },
    });

    // ── ETA DataTable ────────────────────────────────────────────────────
    if ($.fn.DataTable.isDataTable('#eta-table')) {
        $('#eta-table').DataTable().clear().destroy();
    }
    var etaTable = $('#eta-table').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: etaDataUrl,
            data: function (d) {
                d.month = currentMonth;
                d.year  = currentYear;
            },
        },
        columns: [
            { data: 'employee',    title: 'Employee' },
            { data: 'departure',   title: 'Departure' },
            { data: 'arrival',     title: 'Arrival' },
            { data: 'destination', title: 'Destination', orderable: false },
            { data: 'approved_at', title: 'Approved At' },
            {
                data: null, title: 'Action', orderable: false, searchable: false,
                render: function (data, type, row) {
                    return '<div class="action-btns">'
                        + '<button class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openEtaModal(' + row.id + ')"><i class="fa fa-eye"></i> View</button>'
                        + '<a class="hris-btn hris-btn-primary hris-btn-sm" href="{{ url('dashboard/employee/eta-locator') }}/' + row.id + '/print" target="_blank"><i class="fa fa-print"></i> Print</a>'
                        + '</div>';
                },
            },
        ],
        drawCallback: function () {
            var api = this.api();
            document.getElementById('eta-total').textContent = api.page.info().recordsTotal;
            etaRows = {};
            api.rows().every(function () { var d = this.data(); etaRows[d.id] = d; });
        },
        language: { emptyTable: 'No approved ETA requests.' },
    });

    // ── Locator DataTable ────────────────────────────────────────────────
    if ($.fn.DataTable.isDataTable('#locator-table')) {
        $('#locator-table').DataTable().clear().destroy();
    }
    var locatorTable = $('#locator-table').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: locatorDataUrl,
            data: function (d) {
                d.month = currentMonth;
                d.year  = currentYear;
            },
        },
        columns: [
            { data: 'employee',         title: 'Employee' },
            { data: 'application_type', title: 'Type', orderable: false },
            { data: 'travel_date',      title: 'Travel Date' },
            { data: 'location',         title: 'Location', orderable: false },
            { data: 'approved_at',      title: 'Approved At' },
            {
                data: null, title: 'Action', orderable: false, searchable: false,
                render: function (data, type, row) {
                    return '<div class="action-btns">'
                        + '<button class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openLocatorModal(' + row.id + ')"><i class="fa fa-eye"></i> View</button>'
                        + '<a class="hris-btn hris-btn-primary hris-btn-sm" href="{{ url('dashboard/employee/locator') }}/' + row.id + '/print" target="_blank"><i class="fa fa-print"></i> Print</a>'
                        + '</div>';
                },
            },
        ],
        drawCallback: function () {
            var api = this.api();
            document.getElementById('locator-total').textContent = api.page.info().recordsTotal;
            locatorRows = {};
            api.rows().every(function () { var d = this.data(); locatorRows[d.id] = d; });
        },
        language: { emptyTable: 'No approved locator requests.' },
    });

    // ── Modal helpers ────────────────────────────────────────────────────
    window.openLeaveModal = function (id) {
        var r = leaveRows[id];
        if (!r) return;
        document.getElementById('approved-modal-title').textContent = 'Approved Leave Details';
        document.getElementById('approved-modal-body').innerHTML =
            '<table style="width:100%;border-collapse:collapse"><tbody>'
            + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.employee + '</td></tr>'
            + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Leave Type</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.leave_type + '</td></tr>'
            + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Period</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.period + '</td></tr>'
            + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Total Days</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.total_days + '</td></tr>'
            + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Approved At</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.approved_at + '</td></tr>'
            + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Vacation Leave Balance</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.vl + '</td></tr>'
            + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Sick Leave Balance</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.sl + '</td></tr>'
            + '</tbody></table>';
        document.getElementById('approved-modal-print').onclick = function () { printLeave(id); };
        document.getElementById('approvedModal').showModal();
    };

    window.openEtaModal = function (id) {
        var r = etaRows[id];
        if (!r) return;
        document.getElementById('approved-modal-title').textContent = 'Approved ETA Details';
        document.getElementById('approved-modal-body').innerHTML =
            '<table style="width:100%;border-collapse:collapse"><tbody>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Employee</strong></td><td style="padding:6px;border:1px solid #eee">' + r.employee + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Departure</strong></td><td style="padding:6px;border:1px solid #eee">' + r.departure + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Arrival</strong></td><td style="padding:6px;border:1px solid #eee">' + r.arrival + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Destination</strong></td><td style="padding:6px;border:1px solid #eee">' + r.destination + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Purpose</strong></td><td style="padding:6px;border:1px solid #eee">' + r.purpose + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Approved At</strong></td><td style="padding:6px;border:1px solid #eee">' + r.approved_at + '</td></tr>'
            + '</tbody></table>';
        document.getElementById('approved-modal-print').onclick = function () {
            window.open('{{ url('dashboard/employee/eta-locator') }}/' + id + '/print', '_blank');
        };
        document.getElementById('approvedModal').showModal();
    };

    window.openLocatorModal = function (id) {
        var r = locatorRows[id];
        if (!r) return;
        document.getElementById('approved-modal-title').textContent = 'Approved Locator Details';
        document.getElementById('approved-modal-body').innerHTML =
            '<table style="width:100%;border-collapse:collapse"><tbody>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Employee</strong></td><td style="padding:6px;border:1px solid #eee">' + r.employee + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Type</strong></td><td style="padding:6px;border:1px solid #eee">' + r.application_type + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Travel Date</strong></td><td style="padding:6px;border:1px solid #eee">' + r.travel_date + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Location</strong></td><td style="padding:6px;border:1px solid #eee">' + r.location + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Purpose</strong></td><td style="padding:6px;border:1px solid #eee">' + r.purpose + '</td></tr>'
            + '<tr><td style="padding:6px;border:1px solid #eee"><strong>Approved At</strong></td><td style="padding:6px;border:1px solid #eee">' + r.approved_at + '</td></tr>'
            + '</tbody></table>';
        document.getElementById('approved-modal-print').onclick = function () {
            window.open('{{ url('dashboard/employee/locator') }}/' + id + '/print', '_blank');
        };
        document.getElementById('approvedModal').showModal();
    };

    function confirmLeavePrint(url, printedAt, printedBy) {
        if (!printedAt) {
            window.open(url, '_blank');
            return;
        }
        var msg = 'This leave form was already printed on <strong>' + printedAt + '</strong>';
        if (printedBy) msg += ' by <strong>' + printedBy + '</strong>';
        msg += '.<br>Do you still want to print a copy?';
        Swal.fire({
            title: 'Already Printed',
            html: msg,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, print anyway',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#2563eb',
        }).then(function (result) {
            if (result.isConfirmed) window.open(url, '_blank');
        });
    }

    window.printLeave = function (id) {
        var row = leaveRows[id];
        var url = '{{ url('dashboard/employee/leave') }}/' + id + '/print';
        confirmLeavePrint(url, row ? (row.last_printed_at || null) : null, row ? (row.last_printed_by_name || null) : null);
    };
});
</script>
@endsection
