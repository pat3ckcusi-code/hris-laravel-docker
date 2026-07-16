@extends('dashboards.layout', [
    'title' => 'Attendance Adjustment Summary',
    'subtitle' => 'Review unfiled leave, tardiness, and undertime, then forward the filtered report to the Leave Manager.',
])

@section('page_head')
{{-- Flatpickr core + monthSelect plugin --}}
<link rel="stylesheet" href="{{ asset('vendor/flatpickr/flatpickr.min.css') }}">
<link rel="stylesheet" href="{{ asset('vendor/flatpickr/plugins/monthSelect/style.css') }}">
<script src="{{ asset('vendor/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ asset('vendor/flatpickr/plugins/monthSelect/index.js') }}"></script>
<style>
.aas-stat-strip { display:flex; flex-wrap:wrap; gap:1rem; margin-bottom:1rem; }
.aas-stat-card {
    flex:1; min-width:11rem; background:#fff; border-radius:.75rem;
    box-shadow:0 1px 3px rgba(0,0,0,.1); padding:1rem 1.25rem;
    display:flex; align-items:center; gap:.85rem;
}
.aas-stat-icon {
    width:2.5rem; height:2.5rem; border-radius:.6rem; flex:0 0 auto;
    display:inline-flex; align-items:center; justify-content:center; font-size:1rem;
}
.aas-stat-value { font-size:1.35rem; font-weight:700; color:#0f172a; line-height:1.1; }
.aas-stat-label { font-size:.75rem; color:#64748b; margin-top:.15rem; }
.aas-status-badge {
    display:inline-flex; align-items:center; padding:.25rem .6rem; border-radius:9999px;
    font-size:.72rem; font-weight:700; white-space:nowrap;
}
.aas-status-submitted { background:#dcfce7; color:#166534; }
.aas-status-pending { background:#f1f5f9; color:#64748b; }
.aas-count-zero { color:#94a3b8; }
.aas-count-flag { color:#991b1b; font-weight:700; }

.flatpickr-input[readonly] {
    background: #ffffff;
    cursor: pointer;
}
.flatpickr-input[readonly]:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.1);
}

/* Fixed layout + wrapping (instead of the shared table's nowrap headers)
   keeps the table within the card width so the page doesn't need the
   horizontal scrollbar .hris-table-wrapper falls back to - same technique
   as #plantilla-table in payroll.css. With table-layout:fixed and no
   explicit per-column widths, the browser divides the full width equally
   among whichever columns are currently visible, so this keeps working as
   Tardiness/Undertime columns are shown or hidden by the Attendance Issue
   filter. */
#adjustmentSummaryTable {
    table-layout: fixed;
}
#adjustmentSummaryTable th,
#adjustmentSummaryTable td {
    white-space: normal;
    word-break: break-word;
}

/* Dialog modal - department-head.css defines these but isn't loaded on
   attendance pages, so they're duplicated here for the View Details modal. */
.employee-modal {
    border:0; border-radius:14px; width:min(620px, calc(100vw - 24px));
    max-height:calc(100vh - 32px); padding:14px;
    box-shadow:0 24px 70px rgba(15,23,42,.22);
    position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); margin:0; z-index:10000;
}
.employee-modal::backdrop { background:rgba(15,23,42,.5); }
.dialog-header {
    padding:18px 20px;
    background:linear-gradient(90deg, rgba(250,245,240,1) 0%, rgba(255,250,240,1) 100%);
    border-top-left-radius:12px; border-top-right-radius:12px; text-align:center; position:relative;
}
.dialog-title { margin:0; font-size:1.125rem; font-weight:700; color:#0f172a; }
.dialog-close { position:absolute; right:12px; top:12px; background:transparent; border:0; font-size:1.1rem; cursor:pointer; color:#475569; }
.dialog-body { padding:14px 18px; }
.modal-actions { display:flex; gap:8px; justify-content:flex-end; }
</style>
@endsection

@section('modals')
<dialog id="adjustmentDetailModal" class="employee-modal">
    <div class="dialog-header">
        <h3 class="dialog-title" id="adjustment-modal-title">Attendance Details</h3>
        <form method="dialog">
            <button type="submit" class="dialog-close" aria-label="Close">&#x2715;</button>
        </form>
    </div>
    <div class="dialog-body">
        <div id="adjustment-modal-body"></div>
    </div>
    <form method="dialog" class="modal-actions">
        <button class="hris-btn hris-btn-secondary" type="submit">Close</button>
    </form>
</dialog>
@endsection

@section('content')

<div class="aas-stat-strip">
    <div class="aas-stat-card">
        <span class="aas-stat-icon" style="background:#eff6ff;color:#1d4ed8;"><i class="fas fa-users"></i></span>
        <div>
            <div class="aas-stat-value" id="stat-total">-</div>
            <div class="aas-stat-label">Total Employees</div>
        </div>
    </div>
    <div class="aas-stat-card">
        <span class="aas-stat-icon" style="background:#fef3c7;color:#92400e;"><i class="fas fa-calendar-xmark"></i></span>
        <div>
            <div class="aas-stat-value" id="stat-unfiled">-</div>
            <div class="aas-stat-label">Unfiled Leave</div>
        </div>
    </div>
    <div class="aas-stat-card">
        <span class="aas-stat-icon" style="background:#fee2e2;color:#991b1b;"><i class="fas fa-clock"></i></span>
        <div>
            <div class="aas-stat-value" id="stat-tardiness">-</div>
            <div class="aas-stat-label">Tardiness</div>
        </div>
    </div>
    <div class="aas-stat-card">
        <span class="aas-stat-icon" style="background:#f5f3ff;color:#5b21b6;"><i class="fas fa-hourglass-half"></i></span>
        <div>
            <div class="aas-stat-value" id="stat-undertime">-</div>
            <div class="aas-stat-label">Undertime</div>
        </div>
    </div>
</div>

<div class="hris-table-card">
    <div class="hris-table-filters hris-filters-sticky">
        <div class="hris-filter-left" style="flex-wrap:wrap;">
            <div class="hris-filter-group">
                <label class="hris-filter-label" for="filterDepartment">Department</label>
                <select id="filterDepartment" class="hris-filter-select">
                    <option value="">All Departments</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept->Dept_id }}">{{ $dept->Dept_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="hris-filter-group">
                <label class="hris-filter-label" for="filterEmployeeType">Employee Type</label>
                <select id="filterEmployeeType" class="hris-filter-select">
                    <option value="">All Employee Types</option>
                    <option value="Permanent">Permanent</option>
                    <option value="Elected Officials">Elected Officials</option>
                    <option value="Co-Terminus">Co-Terminus</option>
                    <option value="Casual">Casual</option>
                    <option value="Job Orders">Job Orders</option>
                    <option value="Contractual">Contractual</option>
                </select>
            </div>
            <div class="hris-filter-group">
                <label class="hris-filter-label" for="filterIssue">Attendance Issue</label>
                <select id="filterIssue" class="hris-filter-select">
                    <option value="unfiled" selected>Unfiled Leave</option>
                    <option value="tardiness">Tardiness</option>
                    <option value="undertime">Undertime</option>
                </select>
            </div>
            <div class="hris-filter-group">
                <label class="hris-filter-label" for="filterPeriod">Period</label>
                {{-- dateFormat:'Y-m' keeps .value as YYYY-MM; altFormat shows 'June 2026' --}}
                <input type="text" id="filterPeriod" class="hris-filter-select" readonly
                       style="min-width:150px;" value="{{ sprintf('%04d-%02d', $year, $month) }}">
            </div>
            <div class="hris-filter-group">
                <label class="hris-filter-label" for="filterMinCount" title="Only show employees whose count for the selected issue exceeds this number">Minimum Count</label>
                <input type="number" id="filterMinCount" class="hris-filter-select" min="0" step="1" value="7" style="width:5.5rem;">
            </div>
            <div class="hris-filter-group">
                <label class="hris-filter-label" for="filterSearch">Search</label>
                <input type="text" id="filterSearch" class="hris-filter-select" placeholder="Employee No., Name, Department, Position">
            </div>
        </div>
    </div>

    <div style="padding:0 1.25rem 1rem;display:flex;gap:.5rem;flex-wrap:wrap;">
        <button type="button" id="submitToLeaveManagerBtn" class="hris-btn hris-btn-primary">
            <i class="fas fa-paper-plane"></i> Submit to Leave Manager
        </button>
        <button type="button" id="exportExcelBtn" class="hris-btn hris-btn-secondary">
            <i class="fas fa-file-excel"></i> Export to Excel
        </button>
        <button type="button" id="exportPdfBtn" class="hris-btn hris-btn-secondary">
            <i class="fas fa-file-pdf"></i> Export to PDF
        </button>
        <button type="button" id="printBtn" class="hris-btn hris-btn-secondary">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <div class="hris-table-wrapper">
        <table id="adjustmentSummaryTable" class="hris-table" style="width:100%">
            <thead>
                <tr>
                    <th style="text-align:left;">Employee No.</th>
                    <th style="text-align:left;">Name</th>
                    <th style="text-align:left;">Department</th>
                    <th style="text-align:left;">Position</th>
                    <th>Employee Type</th>
                    <th>Unfiled Leave</th>
                    <th>Tardiness (Count)</th>
                    <th>Tardiness (Minutes)</th>
                    <th>Undertime (Count)</th>
                    <th>Undertime (Minutes)</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

@endsection

@section('page_scripts')
<script>
var DATA_URL = @json(route('attendance.adjustment-summary.data'));
var SUBMIT_URL = @json(route('attendance.adjustment-summary.submit'));
var PRINT_URL = @json(route('attendance.adjustment-summary.print'));
var PDF_URL = @json(route('attendance.adjustment-summary.pdf'));
var EXPORT_JOBS_URL = @json(route('export-jobs.create'));

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
}

function currentFilters() {
    return {
        department_id: document.getElementById('filterDepartment').value,
        employee_type: document.getElementById('filterEmployeeType').value,
        issue: document.getElementById('filterIssue').value,
        month: document.getElementById('filterPeriod').value.split('-')[1] || '',
        year: document.getElementById('filterPeriod').value.split('-')[0] || '',
        min_count: document.getElementById('filterMinCount').value,
    };
}

var currentPageRows = {};
var table;

// Which count columns belong to which Attendance Issue - only the selected
// issue's columns are shown, so the table doesn't display counts unrelated
// to the deficiency currently being reviewed.
var ISSUE_COLUMN_GROUPS = { unfiled: [5], tardiness: [6, 7], undertime: [8, 9] };
var ISSUE_COLUMN_INDEXES = [].concat(ISSUE_COLUMN_GROUPS.unfiled, ISSUE_COLUMN_GROUPS.tardiness, ISSUE_COLUMN_GROUPS.undertime);

function isIssueColumnVisible(index, issue) {
    return (ISSUE_COLUMN_GROUPS[issue] || []).indexOf(index) !== -1;
}

function applyIssueColumnVisibility(issue) {
    ISSUE_COLUMN_INDEXES.forEach(function (idx) {
        table.column(idx).visible(isIssueColumnVisible(idx, issue));
    });
    table.columns.adjust();
}

document.addEventListener('DOMContentLoaded', function () {
    var initialIssue = document.getElementById('filterIssue').value;

    table = $('#adjustmentSummaryTable').DataTable({
        serverSide: true,
        processing: true,
        autoWidth: false,
        ajax: {
            url: DATA_URL,
            data: function (d) {
                var f = currentFilters();
                d.department_id = f.department_id;
                d.employee_type = f.employee_type;
                d.issue = f.issue;
                d.month = f.month;
                d.year = f.year;
                d.min_count = f.min_count;
            },
            dataSrc: function (json) {
                document.getElementById('stat-total').textContent = json.summary.total_employees;
                document.getElementById('stat-unfiled').textContent = json.summary.unfiled_leave;
                document.getElementById('stat-tardiness').textContent = json.summary.tardiness;
                document.getElementById('stat-undertime').textContent = json.summary.undertime;
                return json.data;
            },
        },
        columns: [
            { data: 'emp_no', title: 'Employee No.' },
            { data: 'name', title: 'Name' },
            { data: 'department', title: 'Department' },
            { data: 'position', title: 'Position' },
            { data: 'employee_type', title: 'Employee Type' },
            { data: 'unfiled_count', title: 'Unfiled Leave', visible: isIssueColumnVisible(5, initialIssue), render: function (d) {
                return d > 0 ? '<span class="aas-count-flag">' + d + '</span>' : '<span class="aas-count-zero">0</span>';
            }},
            { data: 'tardiness_count', title: 'Tardiness (Count)', visible: isIssueColumnVisible(6, initialIssue), render: function (d) {
                return d > 0 ? '<span class="aas-count-flag">' + d + '</span>' : '<span class="aas-count-zero">0</span>';
            }},
            { data: 'tardiness_minutes', title: 'Tardiness (Minutes)', visible: isIssueColumnVisible(7, initialIssue) },
            { data: 'undertime_count', title: 'Undertime (Count)', visible: isIssueColumnVisible(8, initialIssue), render: function (d) {
                return d > 0 ? '<span class="aas-count-flag">' + d + '</span>' : '<span class="aas-count-zero">0</span>';
            }},
            { data: 'undertime_minutes', title: 'Undertime (Minutes)', visible: isIssueColumnVisible(9, initialIssue) },
            { data: 'status', title: 'Status', render: function (d) {
                var cls = d === 'Submitted' ? 'aas-status-submitted' : 'aas-status-pending';
                return '<span class="aas-status-badge ' + cls + '">' + d + '</span>';
            }},
            {
                data: null, title: 'Action', orderable: false, searchable: false,
                render: function (data, type, row) {
                    return '<button class="hris-btn hris-btn-secondary hris-btn-sm" onclick="openDetailModal(' + row.user_id + ')"><i class="fa fa-eye"></i> View Details</button>';
                },
            },
        ],
        drawCallback: function () {
            currentPageRows = {};
            this.api().rows().every(function () {
                var d = this.data();
                currentPageRows[d.user_id] = d;
            });
        },
        language: { emptyTable: 'No attendance deficiencies found for the selected filters.' },
    });

    ['filterDepartment', 'filterEmployeeType', 'filterIssue'].forEach(function (id) {
        document.getElementById(id).addEventListener('change', function () {
            if (id === 'filterIssue') {
                applyIssueColumnVisibility(this.value);
            }
            table.ajax.reload();
        });
    });

    // monthSelectPlugin keeps .value as YYYY-MM (dateFormat) while displaying
    // "June 2026" (altFormat); disableMobile stops it falling back to the
    // native month input on touch devices.
    flatpickr('#filterPeriod', {
        plugins: [new monthSelectPlugin({ shorthand: false, dateFormat: 'Y-m', altFormat: 'F Y' })],
        defaultDate: document.getElementById('filterPeriod').value,
        disableMobile: true,
        onChange: function () { table.ajax.reload(); },
    });

    var searchTimer;
    document.getElementById('filterSearch').addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function () { table.search(document.getElementById('filterSearch').value).draw(); }, 300);
    });

    var minCountTimer;
    document.getElementById('filterMinCount').addEventListener('input', function () {
        clearTimeout(minCountTimer);
        minCountTimer = setTimeout(function () { table.ajax.reload(); }, 300);
    });

    document.getElementById('submitToLeaveManagerBtn').addEventListener('click', function () {
        Swal.fire({
            title: 'Submit to Leave Manager?',
            text: 'This forwards the currently filtered attendance deficiencies for future Vacation Leave deduction. No deduction happens now.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Submit',
        }).then(function (result) {
            if (!result.isConfirmed) return;
            var f = currentFilters();
            var token = csrfToken();
            fetch(SUBMIT_URL, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: new URLSearchParams(Object.assign({ _token: token, search: document.getElementById('filterSearch').value }, f)),
            }).then(function (r) { return r.json(); }).then(function (data) {
                Swal.fire({ icon: 'success', title: 'Submitted', text: data.message }).then(function () { table.ajax.reload(null, false); });
            }).catch(function () {
                Swal.fire({ icon: 'error', text: 'Submission failed.' });
            });
        });
    });

    document.getElementById('exportExcelBtn').addEventListener('click', function () {
        var f = currentFilters();
        f.search = document.getElementById('filterSearch').value;
        window.startExport(EXPORT_JOBS_URL, { type: 'adjustment_summary', params: f }, 'Generating Attendance Adjustment Summary…');
    });

    document.getElementById('exportPdfBtn').addEventListener('click', function () {
        var f = currentFilters();
        f.search = document.getElementById('filterSearch').value;
        window.open(PDF_URL + '?' + new URLSearchParams(f).toString(), '_blank');
    });

    document.getElementById('printBtn').addEventListener('click', function () {
        var f = currentFilters();
        f.search = document.getElementById('filterSearch').value;
        window.open(PRINT_URL + '?' + new URLSearchParams(f).toString(), '_blank');
    });
});

function openDetailModal(userId) {
    var r = currentPageRows[userId];
    if (!r) return;
    var issue = document.getElementById('filterIssue').value;

    var rows = [
        ['Employee No.', r.emp_no || '-'],
        ['Department', r.department || '-'],
        ['Position', r.position || '-'],
    ];
    if (isIssueColumnVisible(5, issue)) {
        rows.push(['Unfiled Leave', r.unfiled_count]);
    }
    if (isIssueColumnVisible(6, issue) || isIssueColumnVisible(7, issue)) {
        rows.push(['Tardiness', r.tardiness_count + ' day(s), ' + r.tardiness_minutes + ' min']);
    }
    if (isIssueColumnVisible(8, issue) || isIssueColumnVisible(9, issue)) {
        rows.push(['Undertime', r.undertime_count + ' day(s), ' + r.undertime_minutes + ' min']);
    }
    rows.push(['Status', r.status]);
    rows.push(['Remarks', r.remarks || '-']);

    document.getElementById('adjustment-modal-title').textContent = r.name + ' - Attendance Details';
    document.getElementById('adjustment-modal-body').innerHTML =
        '<table style="width:100%;border-collapse:collapse"><tbody>'
        + rows.map(function (row) {
            return '<tr><td style="padding:8px;border:1px solid #f1f5f9;vertical-align:top"><strong>' + row[0] + '</strong></td><td style="padding:8px;border:1px solid #f1f5f9">' + row[1] + '</td></tr>';
        }).join('')
        + '</tbody></table>';
    document.getElementById('adjustmentDetailModal').showModal();
}

@if(session('status'))
try {
    if (window.Swal) Swal.fire({ icon: 'success', title: 'Success', text: {!! json_encode(session('status')) !!} });
} catch (e) {}
@endif
</script>
@endsection
