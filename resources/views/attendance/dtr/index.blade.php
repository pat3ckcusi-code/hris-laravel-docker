@extends('dashboards.layout', [
    'title'    => ($isAdmin || $isOfficer) ? 'Daily Time Records' : 'My Daily Time Records',
    'subtitle' => ($isAdmin || $isOfficer)
        ? 'View and export biometric attendance records.'
        : 'View and export your biometric attendance records.',
])

@section('page_head')
{{-- Flatpickr core + monthSelect plugin --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/style.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/plugins/monthSelect/index.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
/* ── Flatpickr theming ── */
.flatpickr-input[readonly] {
    background: #ffffff;
    cursor: pointer;
}
.flatpickr-input[readonly]:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59,130,246,.1);
}

/* ── DataTables override: keep hris-table styles, neutralise DT defaults ── */
table.hris-table.dataTable {
    border-collapse: collapse !important;
    border-spacing: 0 !important;
    margin: 0 !important;
    width: 100% !important;
}
table.hris-table.dataTable.no-footer { border-bottom: none !important; }
table.hris-table.dataTable thead th  { box-sizing: border-box; }

#dtr-list-card .dataTables_wrapper { padding: 0; }

#dtr-list-card .dataTables_length {
    padding: 0.6rem 1.25rem;
    font-size: 0.8rem;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
}
#dtr-list-card .dataTables_length select {
    padding: 0.2rem 0.4rem;
    border: 1px solid #cbd5e1;
    border-radius: 0.25rem;
    font-size: 0.8rem;
}
#dtr-list-card .dataTables_info {
    padding: 0.6rem 1.25rem;
    font-size: 0.8rem;
    color: #64748b;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: inline-block;
    width: auto;
}
#dtr-list-card .dataTables_paginate {
    padding: 0.6rem 1.25rem;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    text-align: right;
    display: inline-block;
    float: right;
}
#dtr-list-card .dataTables_paginate .paginate_button {
    border-radius: 0.375rem;
    padding: 0.3rem 0.65rem;
    font-size: 0.8rem;
    border: 1px solid #cbd5e1 !important;
    color: #475569 !important;
    background: #fff !important;
    margin: 0 2px;
    cursor: pointer;
}
#dtr-list-card .dataTables_paginate .paginate_button.current {
    background: #3b82f6 !important;
    border-color: #3b82f6 !important;
    color: #fff !important;
}
#dtr-list-card .dataTables_paginate .paginate_button:hover:not(.disabled):not(.current) {
    background: #f0f9ff !important;
    border-color: #3b82f6 !important;
    color: #3b82f6 !important;
}
#dtr-list-card .dataTables_paginate .paginate_button.disabled {
    color: #cbd5e1 !important;
    cursor: not-allowed;
}
#dtr-list-card .dataTables_processing {
    background: rgba(255,255,255,0.9);
    border-radius: 0.375rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    color: #3b82f6;
    font-size: 0.875rem;
    padding: 0.75rem 1.5rem;
    top: 50%;
}
#dtr-list-card .dataTables_wrapper::after {
    content: '';
    display: table;
    clear: both;
}

/* ── Late / undertime row visual cues ── */
tr.dtr-row-late,
tr.dtr-row-undertime              { background: #fff5f5 !important; }
tr.dtr-row-late td:nth-child(6)       { color: #dc2626; font-weight: 600; }
tr.dtr-row-undertime td:nth-child(7)  { color: #dc2626; font-weight: 600; }
/* Per-cell classes set by createdRow - only the slot that caused the penalty turns red */
td.dtr-cell-late, td.dtr-cell-undertime { color: #dc2626; font-weight: 600; }
</style>
@endsection

@section('content')

    {{-- ════════════════════════════════════════════════════════════════════════
         FILTER + TABLE CARD
         ════════════════════════════════════════════════════════════════════════ --}}
    <div class="hris-table-card" id="dtr-list-card">

        {{-- ── Sticky filter bar ── --}}
        <div class="hris-table-filters hris-filters-sticky">
            <div class="hris-filter-left" style="flex-wrap:wrap;gap:.6rem;align-items:flex-end;">

                @if ($isAdmin || $isOfficer)
                    {{-- Employee type narrows the employee dropdown client-side --}}
                    <div class="hris-filter-group">
                        <label class="hris-filter-label">Employee Type</label>
                        <select id="list-type-filter" class="hris-filter-select"
                                onchange="filterEmpByType('list')">
                            <option value="">All Types</option>
                            <option value="permanent">Permanent</option>
                            <option value="co-terminus">Co-Terminus</option>
                            <option value="casual">Casual</option>
                            <option value="job order">Job Order</option>
                            <option value="contractual">Contractual</option>
                        </select>
                    </div>

                    <div class="hris-filter-group">
                        <label class="hris-filter-label">
                            Employee <span style="color:#dc2626;">*</span>
                        </label>
                        <select id="list-emp-select" class="hris-filter-select" style="min-width:220px;">
                            <option value="">- Select Employee -</option>
                            @foreach ($employees as $emp)
                                <option value="{{ $emp->id }}"
                                        data-type="{{ strtolower($emp->employee_type ?? '') }}">
                                    {{ trim(($emp->last_name ?? '') . ', ' . ($emp->first_name ?? '')) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div class="hris-filter-group">
                    <label class="hris-filter-label">DTR Type</label>
                    <select id="list-dtr-type" class="hris-filter-select" onchange="togglePeriod('list')">
                        <option value="monthly">Monthly</option>
                        <option value="semi-monthly">Semi-Monthly</option>
                    </select>
                </div>

                <div class="hris-filter-group">
                    <label class="hris-filter-label">Month &amp; Year</label>
                    {{-- dateFormat:'Y-m' keeps .value as YYYY-MM; altFormat shows 'June 2026' --}}
                    <input type="text" id="list-month-fp" class="hris-filter-select"
                           placeholder="Select month…" readonly style="min-width:150px;">
                </div>

                <div id="list-period-wrap" class="hris-filter-group" style="display:none;">
                    <label class="hris-filter-label">Period</label>
                    <select id="list-period" class="hris-filter-select">
                        <option value="1">1st – 15th</option>
                        <option value="2">16th – End</option>
                    </select>
                </div>

                <div class="hris-filter-group" style="align-self:flex-end;">
                    <button type="button" onclick="loadDtrRecords()" class="hris-btn hris-btn-primary">
                        Load Records
                    </button>
                </div>

            </div>
        </div>

        {{-- ── Placeholder (shown before any load) ── --}}
        <div id="dtr-no-selection" class="hris-empty-state">
            <div class="hris-empty-state-icon">📋</div>
            @if ($isAdmin || $isOfficer)
                <p class="hris-empty-state-title">No records loaded</p>
                <p class="hris-empty-state-text">
                    Select an employee type and employee, choose a period, then click <strong>Load Records</strong>.
                </p>
            @else
                <p class="hris-empty-state-title">No records loaded</p>
                <p class="hris-empty-state-text">
                    Choose a period and click <strong>Load Records</strong> to view your attendance.
                </p>
            @endif
        </div>

        {{-- ── DataTables result (hidden until first load) ── --}}
        <div id="dtr-table-wrap" style="display:none;">
            @if ($isAdmin || $isOfficer)
                <p id="dtr-viewing-label"
                   style="padding:.6rem 1.25rem;margin:0;font-size:.82rem;
                          color:#64748b;background:#f0f9ff;border-bottom:1px solid #e2e8f0;">
                    Viewing: <strong id="dtr-viewing-name"></strong>
                </p>
            @endif
            <div class="hris-table-wrapper">
                <table id="dtr-table" class="hris-table" style="width:100%">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th class="text-center">AM In</th>
                            <th class="text-center">AM Out</th>
                            <th class="text-center">PM In</th>
                            <th class="text-center">PM Out</th>
                            <th class="text-center">Late (min)</th>
                            <th class="text-center">Undertime (min)</th>
                            <th class="text-center">Source</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Office Order</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>

    </div>{{-- /.hris-table-card --}}


    {{-- ════════════════════════════════════════════════════════════════════════
         DOWNLOAD FORM 48  (all roles - employee downloads their own)
         ════════════════════════════════════════════════════════════════════════ --}}
    <details style="margin-bottom:1.25rem;background:#f9fafb;
                    border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem;">
        <summary style="cursor:pointer;font-weight:600;font-size:.95rem;">
            ⬇ Download Form 48 (CSC Form 48 DTR)
        </summary>
        <form id="dl-form" onsubmit="return setDlMonth()"
              style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;">

            @if ($isAdmin || $isOfficer)
                {{-- Employee type narrows the employee dropdown client-side --}}
                <div class="hris-filter-group">
                    <label class="hris-filter-label">Employee Type</label>
                    <select id="dl-type-filter" class="hris-filter-select"
                            onchange="filterEmpByType('dl')">
                        <option value="">All Types</option>
                        <option value="permanent">Permanent</option>
                        <option value="co-terminus">Co-Terminus</option>
                        <option value="casual">Casual</option>
                        <option value="job order">Job Order</option>
                        <option value="contractual">Contractual</option>
                    </select>
                </div>

                <div class="hris-filter-group">
                    <label class="hris-filter-label">Employee</label>
                    <select name="employee_id" id="dl-emp-select" class="hris-filter-select"
                            style="min-width:220px;" required>
                        <option value="">Select Employee</option>
                        @foreach ($employees as $emp)
                            <option value="{{ $emp->id }}"
                                    data-type="{{ strtolower($emp->employee_type ?? '') }}">
                                {{ trim(($emp->last_name ?? '') . ', ' . ($emp->first_name ?? '')) }}
                            </option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="hris-filter-group">
                <label class="hris-filter-label">DTR Type</label>
                <select name="dtr_type" id="dl-dtr-type" class="hris-filter-select" onchange="togglePeriod('dl')">
                    <option value="monthly">Monthly</option>
                    <option value="semi-monthly">Semi-Monthly</option>
                </select>
            </div>

            <div class="hris-filter-group">
                <label class="hris-filter-label">Month &amp; Year</label>
                <input type="text" id="dl-month-fp" class="hris-filter-select"
                       placeholder="Select month…" readonly style="min-width:150px;">
            </div>

            <div id="dl-period-wrap" class="hris-filter-group" style="display:none;">
                <label class="hris-filter-label">Period</label>
                <select name="period" id="dl-period" class="hris-filter-select">
                    <option value="1">1st – 15th</option>
                    <option value="2">16th – End</option>
                </select>
            </div>

            <div class="hris-filter-group" style="align-self:flex-end;">
                <button type="submit" class="hris-btn hris-btn-primary">Download Excel</button>
            </div>
        </form>
    </details>


    {{-- ════════════════════════════════════════════════════════════════════════
         DEPARTMENT BULK DOWNLOAD
         Full admins: choose any department + optional employee type filter.
         Administrative officers: department locked to their own; type filter available.
         employee_type is sent to the server and applied as an AND condition.
         ════════════════════════════════════════════════════════════════════════ --}}
    @if ($isAdmin || $isOfficer)
        <details style="margin-bottom:1.25rem;background:#f9fafb;
                        border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem;">
            <summary style="cursor:pointer;font-weight:600;font-size:.95rem;">
                ⬇ Download Department Form 48 (Bulk)
            </summary>
            <div style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;">

                <div class="hris-filter-group">
                    <label class="hris-filter-label">Department</label>
                    @if ($isAdmin)
                        <select id="bulk-dept" class="hris-filter-select" style="min-width:180px;">
                            <option value="">Select Department</option>
                            @foreach ($departments as $dept)
                                <option value="{{ $dept->Dept_id }}">{{ $dept->Dept_name }}</option>
                            @endforeach
                        </select>
                    @elseif ($officerDepts->count() > 1)
                        {{-- Officer heads multiple departments - show a dropdown --}}
                        <select id="bulk-dept" class="hris-filter-select" style="min-width:180px;">
                            @foreach ($officerDepts as $dept)
                                <option value="{{ $dept->Dept_id }}">{{ $dept->Dept_name }}</option>
                            @endforeach
                        </select>
                    @else
                        {{-- Officer heads a single department - fixed display --}}
                        <input type="hidden" id="bulk-dept" value="{{ $officerDeptId }}">
                        <span style="display:inline-block;padding:.4rem .6rem;font-size:.875rem;
                                     color:#374151;background:#f1f5f9;border:1px solid #cbd5e1;
                                     border-radius:.375rem;min-width:180px;">
                            {{ $officerDept?->Dept_name ?? 'Your Department' }}
                        </span>
                    @endif
                </div>

                <div class="hris-filter-group">
                    <label class="hris-filter-label">Employee Type</label>
                    <select id="bulk-emp-type" class="hris-filter-select">
                        <option value="">All Types</option>
                        <option value="permanent">Permanent</option>
                        <option value="co-terminus">Co-Terminus</option>
                        <option value="casual">Casual</option>
                        <option value="job order">Job Order</option>
                        <option value="contractual">Contractual</option>
                    </select>
                </div>

                <div class="hris-filter-group">
                    <label class="hris-filter-label">DTR Type</label>
                    <select id="bulk-dtr-type" class="hris-filter-select" onchange="togglePeriod('bulk')">
                        <option value="monthly">Monthly</option>
                        <option value="semi-monthly">Semi-Monthly</option>
                    </select>
                </div>

                <div class="hris-filter-group">
                    <label class="hris-filter-label">Month &amp; Year</label>
                    <input type="text" id="bulk-month-fp" class="hris-filter-select"
                           placeholder="Select month…" readonly style="min-width:150px;">
                </div>

                <div id="bulk-period-wrap" class="hris-filter-group" style="display:none;">
                    <label class="hris-filter-label">Period</label>
                    <select id="bulk-period" class="hris-filter-select">
                        <option value="1">1st – 15th</option>
                        <option value="2">16th – End</option>
                    </select>
                </div>

                <div class="hris-filter-group" style="align-self:flex-end;display:flex;gap:.5rem;">
                    <button type="button" onclick="bulkDownload('zip')" class="hris-btn hris-btn-primary">
                        Download ZIP
                    </button>
                    <button type="button" onclick="bulkDownload('sheet')" class="hris-btn hris-btn-secondary">
                        Multi-Sheet Workbook
                    </button>
                </div>
            </div>
            <p style="margin-top:.5rem;font-size:.75rem;color:#6b7280;">
                ZIP = one Excel file per employee &nbsp;|&nbsp; Multi-Sheet = all employees in one workbook
                &nbsp;|&nbsp; Leave Employee Type blank to include all types.
            </p>
        </details>
    @endif

@endsection

@if(session('dtr_error'))
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({ icon: 'info', title: 'No Records Found', text: '{{ session('dtr_error') }}', confirmButtonColor: '#3b82f6' });
});
</script>
@endif

@php
$emptyTableMsg = $isAdmin
    ? 'No DTR records found for the selected period. <a href="'
      . route('hr-manager.attendance.import')
      . '" style="color:#3b82f6;text-decoration:underline;">Import attendance logs →</a>'
    : 'No DTR records found for the selected period.';
@endphp

@section('page_scripts')
<script>
// Guard: the layout yields page_scripts twice - prevent double-execution.
if (typeof window.__dtrViewReady === 'undefined') {
    window.__dtrViewReady = true;
    window.dtrTable = null;

    // ── Flatpickr month pickers ──────────────────────────────────────────────
    // monthSelectPlugin produces YYYY-MM in the input value; display shows "June 2026".
    // disableMobile:true stops flatpickr from falling back to the native date input
    // on touch devices, which would re-introduce free-text entry.
    var fpCfg = {
        plugins: [new monthSelectPlugin({ shorthand: false, dateFormat: 'Y-m', altFormat: 'F Y' })],
        defaultDate: new Date(),
        disableMobile: true,
    };
    flatpickr('#list-month-fp', fpCfg);
    flatpickr('#dl-month-fp',   fpCfg);
    @if ($isAdmin || $isOfficer)
    flatpickr('#bulk-month-fp', fpCfg);
    @endif

    // ── Employee-type client-side filter ────────────────────────────────────
    // Cache all options from each employee select at page load; rebuild the
    // select on type-filter change. Rebuilding is more reliable than
    // display:none on <option> (unsupported in some browsers).
    var allEmployees = { list: [], dl: [] };
    ['list', 'dl'].forEach(function (p) {
        var sel = document.getElementById(p + '-emp-select');
        if (!sel) return;
        Array.from(sel.options).forEach(function (o) {
            allEmployees[p].push({
                value: o.value,
                text:  o.text,
                type:  (o.dataset.type || '').toLowerCase(),
            });
        });
    });

    function filterEmpByType(prefix) {
        var type   = (document.getElementById(prefix + '-type-filter')?.value || '').toLowerCase();
        var select = document.getElementById(prefix + '-emp-select');
        if (!select) return;

        var prev = select.value;
        // Rebuild options matching the chosen type (empty = all).
        var placeholder = prefix === 'dl' ? 'Select Employee' : '- Select Employee -';
        select.innerHTML = '<option value="">' + placeholder + '</option>';

        allEmployees[prefix].forEach(function (e) {
            if (!e.value) return;
            if (type && e.type !== type) return;
            var opt = document.createElement('option');
            opt.value        = e.value;
            opt.text         = e.text;
            opt.dataset.type = e.type;
            if (e.value === prev) opt.selected = true;
            select.appendChild(opt);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    // Show/hide the period selector for semi-monthly mode.
    function togglePeriod(prefix) {
        var type = document.getElementById(prefix + '-dtr-type');
        var wrap = document.getElementById(prefix + '-period-wrap');
        if (type && wrap) {
            wrap.style.display = type.value === 'semi-monthly' ? '' : 'none';
        }
    }

    // Read YYYY-MM from a flatpickr-bound input (the actual value, not display text).
    function fpMonth(id) {
        return document.getElementById(id).value;   // dateFormat:'Y-m' → "2026-06"
    }

    // Submit handler - converts form fields to a background export job.
    function setDlMonth() {
        var month = fpMonth('dl-month-fp');
        if (!month) {
            Swal.fire({
                icon: 'error',
                title: 'Missing Field',
                text: 'Please select a month before downloading.',
                confirmButtonColor: '#3b82f6',
            });
            return false; // cancel form submission
        }

        var dtrType = document.getElementById('dl-dtr-type').value;
        var period  = (document.getElementById('dl-period') || {}).value || 1;
        var params  = { month: month, dtr_type: dtrType, period: parseInt(period) };

        @if ($isAdmin || $isOfficer)
        var empId = document.getElementById('dl-emp-select').value;
        if (!empId) {
            Swal.fire({ icon: 'error', title: 'Missing Field', text: 'Please select an employee.', confirmButtonColor: '#3b82f6' });
            return false;
        }
        params.target_user_id = parseInt(empId);
        @else
        params.target_user_id = {{ auth()->id() }};
        @endif

        startExport(
            '{{ route('export-jobs.create') }}',
            { type: 'form48', params: params },
            'Building Form 48&hellip;'
        );
        return false; // always prevent native form submission
    }

    // ── "Load Records" button ────────────────────────────────────────────────
    function loadDtrRecords() {
        @if ($isAdmin || $isOfficer)
        var empSelect = document.getElementById('list-emp-select');
        var empId     = empSelect.value;
        var empText   = empSelect.options[empSelect.selectedIndex].text;
        if (!empId) {
            Swal.fire({
                icon: 'error',
                title: 'Missing Field',
                text: 'Please select an employee first.',
                confirmButtonColor: '#3b82f6',
            });
            return;
        }
        @endif

        var month = fpMonth('list-month-fp');
        if (!month) {
            Swal.fire({
                icon: 'error',
                title: 'Missing Field',
                text: 'Please select a month.',
                confirmButtonColor: '#3b82f6',
            });
            return;
        }

        document.getElementById('dtr-no-selection').style.display = 'none';
        document.getElementById('dtr-table-wrap').style.display   = '';

        @if ($isAdmin || $isOfficer)
        document.getElementById('dtr-viewing-name').textContent = empText;
        @endif

        if (window.dtrTable) {
            window.dtrTable.destroy();
            window.dtrTable = null;
        }

        window.dtrTable = $('#dtr-table').DataTable({
            processing: true,
            serverSide: true,
            searching:  false,
            ordering:   false,
            pageLength: 31,
            lengthMenu: [[15, 31, 62, -1], ['15', '31', '62', 'All']],
            ajax: {
                url:  '{{ route('attendance.dtr.data') }}',
                type: 'GET',
                data: function (d) {
                    d.dtr_type = document.getElementById('list-dtr-type').value;
                    d.month    = fpMonth('list-month-fp');
                    d.period   = document.getElementById('list-period').value;
                    @if ($isAdmin || $isOfficer)
                    d.employee_id = document.getElementById('list-emp-select').value;
                    @endif
                },
                dataSrc: function (json) {
                    // Exempt employees keep no DTR - inform the user instead of showing an empty table.
                    if (json.exempt) {
                        Swal.fire({
                            icon: 'info',
                            title: 'Exempt from biometric/DTR',
                            text: 'This employee is exempt from biometric attendance and has no DTR records.',
                            confirmButtonColor: '#3b82f6',
                        });
                    }
                    return json.data;
                }
            },
            columns: [
                { data: 'date',              orderable: false },
                { data: 'time_in_am',        orderable: false, className: 'text-center' },
                { data: 'time_out_am',       orderable: false, className: 'text-center' },
                { data: 'time_in_pm',        orderable: false, className: 'text-center' },
                { data: 'time_out_pm',       orderable: false, className: 'text-center' },
                { data: 'late_minutes',      orderable: false, className: 'text-center' },
                { data: 'undertime_minutes', orderable: false, className: 'text-center' },
                { data: 'source_badge',        orderable: false, className: 'text-center' },
                { data: 'status_badge',        orderable: false, className: 'text-center' },
                { data: 'office_order_badge',  orderable: false, className: 'text-center', title: 'Office Order' },
            ],
            createdRow: function (row, data) {
                if (data.is_late)           $(row).addClass('dtr-row-late');
                if (data.is_undertime)      $(row).addClass('dtr-row-undertime');
                if (data.is_am_in_late)     $('td:eq(1)', row).addClass('dtr-cell-late');
                if (data.is_pm_in_late)     $('td:eq(3)', row).addClass('dtr-cell-late');
                if (data.is_pm_out_undertime) $('td:eq(4)', row).addClass('dtr-cell-undertime');
            },
            language: {
                processing:  'Loading…',
                emptyTable:  @json($emptyTableMsg),
                zeroRecords: 'No DTR records found for the selected period.',
                lengthMenu:  'Show _MENU_ entries',
                info:        'Showing _START_–_END_ of _TOTAL_ records',
                infoEmpty:   'No records to display',
                paginate: { first: '«', last: '»', previous: '‹', next: '›' },
            },
        });
    }

    // ── Bulk department download (admin and administrative officer) ───────────
    function bulkDownload(exportType) {
        var dept    = document.getElementById('bulk-dept').value;
        var empType = document.getElementById('bulk-emp-type').value;
        var dtrType = document.getElementById('bulk-dtr-type').value;
        var month   = fpMonth('bulk-month-fp');
        var period  = document.getElementById('bulk-period').value;

        if (!dept) {
            Swal.fire({ icon: 'error', title: 'Missing Field', text: 'Please select a department.', confirmButtonColor: '#3b82f6' });
            return;
        }
        if (!month) {
            Swal.fire({ icon: 'error', title: 'Missing Field', text: 'Please select a month.', confirmButtonColor: '#3b82f6' });
            return;
        }

        var jobParams = {
            dept_id:  parseInt(dept),
            dtr_type: dtrType,
            month:    month,
            period:   parseInt(period),
        };
        if (empType) jobParams.employee_type = empType;

        var jobType = (exportType === 'zip') ? 'form48_dept_zip' : 'form48_dept';
        var label   = (exportType === 'zip') ? 'Building ZIP archive&hellip;' : 'Building multi-sheet workbook&hellip;';

        startExport('{{ route('export-jobs.create') }}', { type: jobType, params: jobParams }, label);
    }
}
</script>
@endsection
