@extends('dashboards.layout', [
    'title' => 'Pending Requests',
    'subtitle' => 'Requests awaiting your approval',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('tiles')
    <article class="kpi-card accent-leave tab-card active" data-tab="leave">
        <div>
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid fa-calendar-days"></i></div>
                <div class="kpi-title">Leave</div>
            </div>
            <div class="kpi-meta">Pending applications</div>
        </div>
        <div class="tile-count" id="leave-total">-</div>
    </article>

    <article class="kpi-card accent-eta tab-card" data-tab="eta">
        <div>
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid fa-plane-departure"></i></div>
                <div class="kpi-title">ETA</div>
            </div>
            <div class="kpi-meta">Employee Travel Authorization</div>
        </div>
        <div class="tile-count" id="eta-total">-</div>
    </article>

    <article class="kpi-card accent-locator tab-card" data-tab="locator">
        <div>
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></div>
                <div class="kpi-title">Locator</div>
            </div>
            <div class="kpi-meta">Locator / Travel</div>
        </div>
        <div class="tile-count" id="locator-total">-</div>
    </article>
@endsection

@section('modals')
<dialog id="pendingModal" class="employee-modal">
    <div class="dialog-header">
        <h3 class="dialog-title" id="pending-modal-title">Details</h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div id="pending-modal-body"></div>
    </div>
    <form method="dialog" class="modal-actions">
        <button class="hris-btn hris-btn-secondary" type="submit">Close</button>
    </form>
</dialog>
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
                                    <th>Reason / Purpose</th>
                                    <th>Period</th>
                                    <th>Total Days</th>
                                    <th>Filed At</th>
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
                                    <th>Purpose Details</th>
                                    <th>Filed At</th>
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
                                    <th>Departure</th>
                                    <th>Arrival</th>
                                    <th>Purpose of Travel</th>
                                    <th>Filed At</th>
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

@section('page_scripts')
<script>
// ── Constants injected from PHP ───────────────────────────────────────────────
var APPROVER_PREFIX  = @json($approverPrefix);
var CURRENT_MONTH    = {{ $month }};
var CURRENT_YEAR     = {{ $year }};
var LEAVE_DATA_URL   = @json($leaveDataUrl);
var ETA_DATA_URL     = @json($etaDataUrl);
var LOCATOR_DATA_URL = @json($locatorDataUrl);

// ── CSRF helper ───────────────────────────────────────────────────────────────
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
}

// ── Row caches (current DataTables page, keyed by id) ─────────────────────────
var leaveRows   = {};
var etaRows     = {};
var locatorRows = {};

// ── DataTables instances (set after DOM ready) ────────────────────────────────
var leaveTable, etaTable, locatorTable;

document.addEventListener('DOMContentLoaded', function () {

    // Tab switching
    document.querySelectorAll('.tab-card').forEach(function (card) {
        card.addEventListener('click', function () {
            document.querySelectorAll('.tab-card').forEach(function (c) { c.classList.remove('active'); });
            card.classList.add('active');
            var tab = card.getAttribute('data-tab');
            document.querySelectorAll('.tab-pane').forEach(function (p) {
                p.style.display = (p.getAttribute('data-pane') === tab) ? '' : 'none';
            });
            var el = document.getElementById('tab-content');
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (tab === 'eta')     etaTable.columns.adjust().draw(false);
            if (tab === 'locator') locatorTable.columns.adjust().draw(false);
        });
    });

    // ── Leave DataTable ───────────────────────────────────────────────────────
    if ($.fn.DataTable.isDataTable('#leave-table')) {
        $('#leave-table').DataTable().clear().destroy();
    }
    leaveTable = $('#leave-table').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: LEAVE_DATA_URL,
            data: function (d) { d.month = CURRENT_MONTH; d.year = CURRENT_YEAR; },
        },
        columns: [
            { data: 'employee',   title: 'Employee' },
            { data: 'leave_type', title: 'Leave Type', render: function(data, type, row) {
                var label = data || '';
                if (row.rescheduled_from_id) {
                    label += ' <span style="display:inline-block;background:#dbeafe;color:#1e40af;border:1px solid #93c5fd;padding:1px 6px;border-radius:4px;font-size:0.7rem;font-weight:600;white-space:nowrap">Reschedule</span>';
                }
                return label;
            }},
            { data: 'reason',     title: 'Reason / Purpose', orderable: false },
            { data: 'period',     title: 'Period', orderable: false, render: function(data, type, row) {
                var label = data || '';
                if (row.rescheduled_from_id && row.original_dates_replaced) {
                    label += '<span style="display:block;font-size:0.72rem;color:#64748b;">&#8635; was ' + row.original_dates_replaced + '</span>';
                }
                return label;
            }},
            { data: 'total_days', title: 'Total Days', orderable: false },
            { data: 'filed_at',   title: 'Filed At' },
            {
                data: null, title: 'Action', orderable: false, searchable: false,
                render: function (data, type, row) {
                    var btns = '<div class="action-btns">'
                        + '<button class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openPendingLeaveModal(' + row.id + ')"><i class="fa fa-eye"></i> View</button>';

                    if (row.status === 'pending') {
                        if (!row.printing_allowed) {
                            btns += '<button class="hris-btn hris-btn-secondary hris-btn-sm" disabled title="Printing enabled after Allow Printing."><i class="fa fa-print"></i> Print</button>'
                                  + '<button class="hris-btn hris-btn-warning hris-btn-sm" onclick="allowPrinting(' + row.id + ')"><i class="fa fa-unlock"></i> Allow Printing</button>';
                        } else {
                            btns += '<button class="hris-btn hris-btn-primary hris-btn-sm" onclick="printLeave(' + row.id + ')"><i class="fa fa-print"></i> Print</button>'
                                  + '<button class="hris-btn hris-btn-primary hris-btn-sm" onclick="confirmApprove(' + row.id + ')"><i class="fa fa-check"></i> Approve</button>'
                                  + '<button class="hris-btn hris-btn-danger hris-btn-sm" onclick="promptReject(' + row.id + ')"><i class="fa fa-times"></i> Reject</button>';
                        }
                    } else if (row.status === 'approved') {
                        if (row.printing_allowed) {
                            btns += '<button class="hris-btn hris-btn-primary hris-btn-sm" onclick="printLeave(' + row.id + ')"><i class="fa fa-print"></i> Print</button>';
                        } else {
                            btns += '<button class="hris-btn hris-btn-secondary hris-btn-sm" disabled title="Printing not allowed until approved."><i class="fa fa-print"></i> Print</button>';
                        }
                    }

                    btns += '</div>';
                    return btns;
                },
            },
        ],
        drawCallback: function () {
            var api = this.api();
            document.getElementById('leave-total').textContent = api.page.info().recordsTotal;
            leaveRows = {};
            api.rows().every(function () { var d = this.data(); leaveRows[d.id] = d; });
        },
        language: { emptyTable: 'No pending leave requests.' },
    });

    // ── ETA DataTable ─────────────────────────────────────────────────────────
    if ($.fn.DataTable.isDataTable('#eta-table')) {
        $('#eta-table').DataTable().clear().destroy();
    }
    etaTable = $('#eta-table').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: ETA_DATA_URL,
            data: function (d) { d.month = CURRENT_MONTH; d.year = CURRENT_YEAR; },
        },
        columns: [
            { data: 'employee',    title: 'Employee', render: function(data, type, row) {
                var label = data || '';
                if (row.is_cancellation_request) {
                    label += ' <span style="display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;padding:1px 6px;border-radius:4px;font-size:0.7rem;font-weight:600;white-space:nowrap;margin-left:4px;">Approved &mdash; Cancellation Requested</span>';
                }
                return label;
            }},
            { data: 'departure',   title: 'Departure' },
            { data: 'arrival',     title: 'Arrival' },
            { data: 'destination', title: 'Destination', orderable: false },
            { data: 'purpose',     title: 'Purpose Details', orderable: false },
            { data: 'filed_at',    title: 'Filed At' },
            {
                data: null, title: 'Action', orderable: false, searchable: false,
                render: function (data, type, row) {
                    var btns = '<div class="action-btns">'
                        + '<button class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openPendingEtaModal(' + row.id + ')"><i class="fa fa-eye"></i> View</button>';
                    if (row.is_cancellation_request) {
                        btns += '<button class="hris-btn hris-btn-primary hris-btn-sm" onclick="confirmApproveEtaCancellation(' + row.id + ')"><i class="fa fa-check"></i> Approve Cancellation</button>'
                              + '<button class="hris-btn hris-btn-danger hris-btn-sm" onclick="promptRejectEtaCancellation(' + row.id + ')"><i class="fa fa-times"></i> Reject Cancellation</button>';
                    } else {
                        btns += '<button class="hris-btn hris-btn-primary hris-btn-sm" onclick="confirmApproveEta(' + row.id + ')"><i class="fa fa-check"></i> Approve</button>'
                              + '<button class="hris-btn hris-btn-danger hris-btn-sm" onclick="promptRejectEta(' + row.id + ')"><i class="fa fa-times"></i> Reject</button>';
                    }
                    btns += '</div>';
                    return btns;
                },
            },
        ],
        drawCallback: function () {
            var api = this.api();
            document.getElementById('eta-total').textContent = api.page.info().recordsTotal;
            etaRows = {};
            api.rows().every(function () { var d = this.data(); etaRows[d.id] = d; });
        },
        language: { emptyTable: 'No pending ETA requests.' },
    });

    // ── Locator DataTable ─────────────────────────────────────────────────────
    if ($.fn.DataTable.isDataTable('#locator-table')) {
        $('#locator-table').DataTable().clear().destroy();
    }
    locatorTable = $('#locator-table').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: LOCATOR_DATA_URL,
            data: function (d) { d.month = CURRENT_MONTH; d.year = CURRENT_YEAR; },
        },
        columns: [
            { data: 'employee',         title: 'Employee' },
            { data: 'application_type', title: 'Type', orderable: false },
            { data: 'travel_date',      title: 'Travel Date' },
            { data: 'location',         title: 'Location', orderable: false },
            { data: 'departure',        title: 'Departure', orderable: false },
            { data: 'arrival',          title: 'Arrival', orderable: false },
            { data: 'detail',           title: 'Purpose of Travel', orderable: false },
            { data: 'filed_at',         title: 'Filed At' },
            {
                data: null, title: 'Action', orderable: false, searchable: false,
                render: function (data, type, row) {
                    return '<div class="action-btns">'
                        + '<button class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openPendingLocatorModal(' + row.id + ')"><i class="fa fa-eye"></i> View</button>'
                        + '<button class="hris-btn hris-btn-primary hris-btn-sm" onclick="confirmApproveLocator(' + row.id + ')"><i class="fa fa-check"></i> Approve</button>'
                        + '<button class="hris-btn hris-btn-danger hris-btn-sm" onclick="promptRejectLocator(' + row.id + ')"><i class="fa fa-times"></i> Reject</button>'
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
        language: { emptyTable: 'No pending locator requests.' },
    });

}); // end DOMContentLoaded

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openPendingLeaveModal(id) {
    var r = leaveRows[id];
    if (!r) return;
    document.getElementById('pending-modal-title').textContent = 'Pending Leave Details';
    document.getElementById('pending-modal-body').innerHTML =
        '<table style="width:100%;border-collapse:collapse"><tbody>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.employee + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Leave Type</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.leave_type + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Reason</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.reason + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Period</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.period + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Total Days</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.total_days + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Filed At</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.filed_at + '</td></tr>'
        + '</tbody></table>';
    document.getElementById('pendingModal').showModal();
}

function openPendingEtaModal(id) {
    var r = etaRows[id];
    if (!r) return;
    document.getElementById('pending-modal-title').textContent = r.is_cancellation_request ? 'ETA Cancellation Request' : 'Pending ETA Details';
    var html = '<table style="width:100%;border-collapse:collapse"><tbody>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.employee + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Departure</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.departure + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Arrival</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.arrival + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Destination</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.destination + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Purpose</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.purpose + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Filed At</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.filed_at + '</td></tr>';
    if (r.is_cancellation_request) {
        html += '<tr><td style="padding:8px;border:1px solid #f1f5f9;background:#fffbeb"><strong>This ETA was already approved</strong></td><td style="padding:8px;border:1px solid #f1f5f9;background:#fffbeb">by ' + (r.approved_by_name || '-') + ' on ' + (r.approved_at || '-') + '</td></tr>'
              + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Cancellation Reason</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + (r.cancellation_reason || '-') + '</td></tr>';
    }
    html += '</tbody></table>';
    document.getElementById('pending-modal-body').innerHTML = html;
    document.getElementById('pendingModal').showModal();
}

function openPendingLocatorModal(id) {
    var r = locatorRows[id];
    if (!r) return;
    document.getElementById('pending-modal-title').textContent = 'Pending Locator Details';
    document.getElementById('pending-modal-body').innerHTML =
        '<table style="width:100%;border-collapse:collapse"><tbody>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.employee + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Type</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.application_type + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Travel Date</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.travel_date + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Location</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.location + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Departure</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.departure + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Arrival</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.arrival + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Purpose</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.detail + '</td></tr>'
        + '<tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Filed At</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + r.filed_at + '</td></tr>'
        + '</tbody></table>';
    document.getElementById('pendingModal').showModal();
}

// ── Print confirmation ────────────────────────────────────────────────────────
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

function printLeave(id) {
    var row = leaveRows[id];
    var url = '/dashboard/employee/leave/' + id + '/print';
    confirmLeavePrint(url, row ? (row.last_printed_at || null) : null, row ? (row.last_printed_by_name || null) : null);
}

// ── Leave actions ─────────────────────────────────────────────────────────────
function confirmApprove(id) {
    var token = csrfToken();
    var url   = '/' + APPROVER_PREFIX + '/leave/' + id + '/approve';
    if (window.Swal) {
        Swal.fire({
            title: 'Approve application?',
            text: 'Are you sure you want to approve this application?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Approve',
            confirmButtonColor: '#16a34a',
        }).then(function (result) {
            if (!result.isConfirmed) return;
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: new URLSearchParams({ _token: token }),
            }).then(function (r) { return r.json(); }).then(function (data) {
                Swal.fire({ icon: 'success', text: data.message || 'Leave approved.' })
                    .then(function () { leaveTable.ajax.reload(null, false); });
            }).catch(function () {
                Swal.fire({ icon: 'error', text: 'Failed to approve leave.' });
            });
        });
    } else {
        if (confirm('Approve this application?')) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ _token: token }) })
                .then(function () { leaveTable.ajax.reload(null, false); });
        }
    }
}

function promptReject(id) {
    var token = csrfToken();
    var url   = '/' + APPROVER_PREFIX + '/leave/' + id + '/reject';
    if (window.Swal) {
        Swal.fire({
            icon: 'warning',
            title: 'Reject request',
            input: 'textarea',
            inputLabel: 'Rejection reason',
            inputPlaceholder: 'Provide reason for rejection',
            showCancelButton: true,
            confirmButtonText: 'Reject',
            preConfirm: function (value) {
                if (!value) Swal.showValidationMessage('Rejection reason is required');
                return value;
            },
        }).then(function (result) {
            if (!result.isConfirmed) return;
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({ rejection_notes: result.value, _token: token }),
            }).then(function (r) { return r.json(); }).then(function (data) {
                Swal.fire({ icon: 'success', text: data.message || 'Leave request rejected.' })
                    .then(function () { leaveTable.ajax.reload(null, false); });
            }).catch(function () {
                Swal.fire({ icon: 'error', text: 'Failed to reject request.' });
            });
        });
    } else {
        var reason = prompt('Rejection reason:');
        if (reason) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ rejection_notes: reason, _token: token }) })
                .then(function () { leaveTable.ajax.reload(null, false); });
        }
    }
}

function allowPrinting(id) {
    var token = csrfToken();
    var url   = '/' + APPROVER_PREFIX + '/leave/' + id + '/allow-printing';
    if (window.Swal) {
        Swal.fire({
            title: 'Allow printing?',
            text: 'This will enable printing for the applicant and show the Approve button.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Allow Printing',
        }).then(function (result) {
            if (!result.isConfirmed) return;
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: new URLSearchParams({ _token: token }),
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (data && data.success) {
                    Swal.fire({ icon: 'success', text: 'Printing allowed.' })
                        .then(function () { leaveTable.ajax.reload(null, false); });
                } else {
                    Swal.fire({ icon: 'error', text: (data && data.message) || 'Failed to allow printing.' });
                }
            }).catch(function () {
                Swal.fire({ icon: 'error', text: 'Failed to allow printing.' });
            });
        });
    } else {
        if (confirm('Allow printing?')) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token }, body: new URLSearchParams({ _token: token }) })
                .then(function () { leaveTable.ajax.reload(null, false); });
        }
    }
}

// ── ETA actions ───────────────────────────────────────────────────────────────
function confirmApproveEta(id) {
    var token = csrfToken();
    var url   = '/' + APPROVER_PREFIX + '/eta/' + id + '/approve';
    if (window.Swal) {
        Swal.fire({ title: 'Approve ETA?', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: new URLSearchParams({ _token: token }),
                }).then(function (res) {
                    if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || 'HTTP ' + res.status); });
                    return res.json();
                }).then(function (data) {
                    Swal.fire({ icon: 'success', text: data.message || 'Approved.' })
                        .then(function () { etaTable.ajax.reload(null, false); });
                }).catch(function (err) {
                    Swal.fire({ icon: 'error', title: 'Approval Failed', text: err.message || 'Failed to approve ETA.' });
                });
            });
    } else {
        if (confirm('Approve this ETA?')) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ _token: token }) })
                .then(function () { etaTable.ajax.reload(null, false); });
        }
    }
}

function promptRejectEta(id) {
    var token = csrfToken();
    var url   = '/' + APPROVER_PREFIX + '/eta/' + id + '/reject';
    if (window.Swal) {
        Swal.fire({
            icon: 'warning', title: 'Reject ETA request', input: 'textarea', inputLabel: 'Rejection reason',
            showCancelButton: true, confirmButtonText: 'Reject',
            preConfirm: function (v) { if (!v) Swal.showValidationMessage('Rejection reason is required'); return v; },
        }).then(function (result) {
            if (!result.isConfirmed) return;
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({ rejection_notes: result.value, _token: token }),
            }).then(function (r) { return r.json(); }).then(function (data) {
                Swal.fire({ icon: 'success', text: data.message || 'Rejected.' })
                    .then(function () { etaTable.ajax.reload(null, false); });
            }).catch(function () {
                Swal.fire({ icon: 'error', text: 'Failed to reject request.' });
            });
        });
    } else {
        var reason = prompt('Rejection reason:');
        if (reason) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ rejection_notes: reason, _token: token }) })
                .then(function () { etaTable.ajax.reload(null, false); });
        }
    }
}

function confirmApproveEtaCancellation(id) {
    var token = csrfToken();
    var url   = '/' + APPROVER_PREFIX + '/eta/' + id + '/approve-cancellation';
    if (window.Swal) {
        Swal.fire({ title: 'Approve ETA Cancellation?', text: 'This will cancel the employee\'s already-approved ETA.', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve Cancellation' })
            .then(function (r) {
                if (!r.isConfirmed) return;
                fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: new URLSearchParams({ _token: token }),
                }).then(function (res) {
                    if (!res.ok) return res.json().then(function (d) { throw new Error(d.message || 'HTTP ' + res.status); });
                    return res.json();
                }).then(function (data) {
                    Swal.fire({ icon: 'success', text: data.message || 'Cancellation approved.' })
                        .then(function () { etaTable.ajax.reload(null, false); });
                }).catch(function (err) {
                    Swal.fire({ icon: 'error', title: 'Failed', text: err.message || 'Failed to approve cancellation.' });
                });
            });
    } else {
        if (confirm('Approve this ETA cancellation?')) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ _token: token }) })
                .then(function () { etaTable.ajax.reload(null, false); });
        }
    }
}

function promptRejectEtaCancellation(id) {
    var token = csrfToken();
    var url   = '/' + APPROVER_PREFIX + '/eta/' + id + '/reject-cancellation';
    if (window.Swal) {
        Swal.fire({
            icon: 'warning', title: 'Reject Cancellation Request', input: 'textarea', inputLabel: 'Remarks (why the ETA should remain approved)',
            showCancelButton: true, confirmButtonText: 'Reject Cancellation',
            preConfirm: function (v) { if (!v) Swal.showValidationMessage('Remarks are required'); return v; },
        }).then(function (result) {
            if (!result.isConfirmed) return;
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({ remarks: result.value, _token: token }),
            }).then(function (r) { return r.json(); }).then(function (data) {
                Swal.fire({ icon: 'success', text: data.message || 'Cancellation request rejected.' })
                    .then(function () { etaTable.ajax.reload(null, false); });
            }).catch(function () {
                Swal.fire({ icon: 'error', text: 'Failed to reject cancellation request.' });
            });
        });
    } else {
        var remarks = prompt('Remarks:');
        if (remarks) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ remarks: remarks, _token: token }) })
                .then(function () { etaTable.ajax.reload(null, false); });
        }
    }
}

// ── Locator actions ───────────────────────────────────────────────────────────
function confirmApproveLocator(id) {
    var token = csrfToken();
    var url   = '/' + APPROVER_PREFIX + '/locator/' + id + '/approve';
    var r     = locatorRows[id] || {};
    if (window.Swal) {
        var html = '<p><strong>Employee:</strong> ' + (r.employee || '') + '</p>'
                 + '<p><strong>Travel Date:</strong> ' + (r.travel_date || '') + '</p>'
                 + '<p><strong>Purpose of Travel:</strong> ' + (r.detail || '') + '</p>';
        Swal.fire({ title: 'Approve Locator Request?', html: html, icon: 'question', showCancelButton: true, confirmButtonText: 'Approve' })
            .then(function (res) {
                if (!res.isConfirmed) return;
                fetch(url, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: new URLSearchParams({ _token: token }),
                }).then(function (re) { return re.json(); }).then(function (data) {
                    Swal.fire({ icon: 'success', text: data.message || 'Locator approved.' })
                        .then(function () { locatorTable.ajax.reload(null, false); });
                }).catch(function () {
                    Swal.fire({ icon: 'error', text: 'Failed to approve.' });
                });
            });
    } else {
        if (confirm('Approve this Locator?')) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ _token: token }) })
                .then(function () { locatorTable.ajax.reload(null, false); });
        }
    }
}

function promptRejectLocator(id) {
    var token = csrfToken();
    var url   = '/' + APPROVER_PREFIX + '/locator/' + id + '/reject';
    if (window.Swal) {
        Swal.fire({
            icon: 'warning', title: 'Reject Locator request', input: 'textarea', inputLabel: 'Rejection reason',
            showCancelButton: true, confirmButtonText: 'Reject',
            preConfirm: function (v) { if (!v) Swal.showValidationMessage('Rejection reason is required'); return v; },
        }).then(function (result) {
            if (!result.isConfirmed) return;
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: new URLSearchParams({ rejection_notes: result.value, _token: token }),
            }).then(function (re) { return re.json(); }).then(function (data) {
                Swal.fire({ icon: 'success', text: data.message || 'Rejected.' })
                    .then(function () { locatorTable.ajax.reload(null, false); });
            }).catch(function () {
                Swal.fire({ icon: 'error', text: 'Failed to reject.' });
            });
        });
    } else {
        var reason = prompt('Rejection reason:');
        if (reason) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ rejection_notes: reason, _token: token }) })
                .then(function () { locatorTable.ajax.reload(null, false); });
        }
    }
}

// ── Flash messages ────────────────────────────────────────────────────────────
@if(session('success'))
try {
    if (window.Swal) Swal.fire({ icon: 'success', title: 'Success', text: {!! json_encode(session('success')) !!} });
    else alert({!! json_encode(session('success')) !!});
} catch (e) {}
@endif

@if(session('error'))
try {
    if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: {!! json_encode(session('error')) !!} });
    else alert({!! json_encode(session('error')) !!});
} catch (e) {}
@endif

@if($errors->any())
try {
    var _errs = {!! json_encode($errors->all()) !!}.join('\n');
    if (window.Swal) Swal.fire({ icon: 'error', title: 'Validation error', text: _errs });
    else alert(_errs);
} catch (e) {}
@endif
</script>
@endsection
