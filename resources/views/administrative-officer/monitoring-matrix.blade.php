@extends('dashboards.layout', [
    'title' => 'Monitoring Matrix',
    'subtitle' => 'Department Management',
])

@php
    $th = 'padding:0.55rem 0.6rem;border:1px solid #cbd5e1;text-align:center;font-size:0.75rem;font-weight:700;line-height:1.35;vertical-align:middle;';
    $td = 'padding:0.5rem 0.65rem;border:1px solid #e2e8f0;text-align:center;vertical-align:middle;';
@endphp

@section('content')

{{-- Filter / export bar --}}
<div class="tile" style="margin-bottom:1rem;">
    <form method="GET" action="{{ route('admin-officer.monitoring-matrix') }}"
          style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">

        <div style="display:flex;flex-direction:column;gap:0.3rem;">
            <label for="mat-month" style="font-size:0.82rem;font-weight:600;color:#374151;">Month</label>
            <select id="mat-month" name="month"
                    style="padding:0.5rem 0.7rem;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;background:#fff;">
                @foreach(range(1, 12) as $m)
                    <option value="{{ $m }}" @selected($m === $month)>
                        {{ \Carbon\Carbon::createFromDate(null, $m, 1)->format('F') }}
                    </option>
                @endforeach
            </select>
        </div>

        <div style="display:flex;flex-direction:column;gap:0.3rem;">
            <label for="mat-year" style="font-size:0.82rem;font-weight:600;color:#374151;">Year</label>
            <select id="mat-year" name="year"
                    style="padding:0.5rem 0.7rem;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;background:#fff;">
                @foreach(range((int) date('Y') - 2, (int) date('Y') + 1) as $y)
                    <option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit"
                style="padding:0.52rem 1.1rem;background:#374151;color:#fff;border:none;border-radius:6px;
                       font-size:0.9rem;font-weight:600;cursor:pointer;">
            View
        </button>

        <button type="button" id="matrix-export-btn"
                onclick="exportMonitoringMatrixExcel()"
                style="padding:0.52rem 1.1rem;background:#1d4ed8;color:#fff;border-radius:6px;
                       font-size:0.9rem;font-weight:600;cursor:pointer;border:none;display:inline-flex;align-items:center;gap:0.4rem;">
            <i class="fas fa-file-excel" aria-hidden="true"></i> Download Excel
        </button>
    </form>
</div>

{{-- Preview table --}}
<div class="tile" style="padding:0;overflow:hidden;">
    <div style="padding:1rem 1.25rem 0.75rem;border-bottom:1px solid #e5e7eb;">
        <div style="font-weight:700;font-size:0.95rem;text-transform:uppercase;letter-spacing:.03em;">
            {{ $dept ? $dept->Dept_name : 'Your Department' }}
        </div>
        <div style="font-size:0.82rem;color:#6b7280;margin-top:2px;">
            CGC Employees' Attendance, Leave &amp; Locator Monitoring Matrix
            &mdash; For the Month of {{ \Carbon\Carbon::createFromDate($year, $month, 1)->format('F Y') }}
        </div>
    </div>

    <div style="padding:0.5rem 1rem 0;display:flex;align-items:center;gap:0.5rem;">
        <label for="matrix-search" style="font-size:0.8rem;font-weight:600;color:#374151;white-space:nowrap;">Search:</label>
        <input id="matrix-search" type="search" placeholder="Name or position…"
               style="padding:0.35rem 0.6rem;border:1px solid #d1d5db;border-radius:5px;font-size:0.82rem;width:220px;">

        <label for="matrix-type-filter" style="font-size:0.8rem;font-weight:600;color:#374151;white-space:nowrap;margin-left:0.5rem;">Employee Type:</label>
        <select id="matrix-type-filter"
                style="padding:0.35rem 0.6rem;border:1px solid #d1d5db;border-radius:5px;font-size:0.82rem;background:#fff;">
            <option value="">All Types</option>
            <option value="permanent">Permanent</option>
            <option value="elected officials">Elected Officials</option>
            <option value="co-terminus">Co-Terminus</option>
            <option value="casual">Casual</option>
            <option value="job orders">Job Orders</option>
            <option value="contractual">Contractual</option>
        </select>

        <span style="font-size:0.78rem;color:#9ca3af;margin-left:0.25rem;">Click column headers to sort.</span>
    </div>

    <div style="padding:0.5rem 1rem 0.75rem;overflow-x:auto;">
        <table id="monitoring-matrix-table" style="width:100%;font-size:0.82rem;border-collapse:collapse;">
            <thead>
                <tr style="background:#bdd7ee;">
                    <th style="{{ $th }}width:36px;">#</th>
                    <th style="{{ $th }}text-align:left;min-width:180px;">NAME</th>
                    <th style="{{ $th }}min-width:110px;">POSITION</th>
                    <th style="{{ $th }}">NO. OF<br>UNDER-<br>TIME</th>
                    <th style="{{ $th }}">NO. OF<br>TARDI-<br>NESS</th>
                    <th style="{{ $th }}">NO. OF<br>UNFILED<br>LEAVE</th>
                    <th style="{{ $th }}">NO. OF DAYS<br>ABSENT W/<br>OFFICIAL<br>LEAVE</th>
                    <th style="{{ $th }}">NO. OF DAYS<br>ABSENT W/<br>UN-OFFICIAL<br>EXIT</th>
                    <th style="{{ $th }}">NO. OF MINS<br>TARDINESS/<br>UNDERTIME</th>
                    <th style="{{ $th }}">NO. OF MINS<br>ON LOCATOR<br>(PERSONAL)</th>
                    <th style="{{ $th }}text-align:left;min-width:260px;">REMARKS</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i => $row)
                    <tr data-type="{{ strtolower($row['employee_type'] ?? '') }}">
                        <td style="{{ $td }}color:#6b7280;">{{ $i + 1 }}</td>
                        <td style="{{ $td }}text-align:left;font-weight:600;">{{ $row['name'] }}</td>
                        <td style="{{ $td }}">{{ $row['position'] ?: '-' }}</td>
                        @if ($row['is_exempt'])
                            <td style="{{ $td }}color:#92400e;font-weight:600;">EXEMPT</td>
                        @else
                            <td style="{{ $td }}{{ $row['undertime_count'] > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#6b7280;' }}">
                                {{ $row['undertime_count'] ?: 0 }}
                            </td>
                        @endif
                        @if ($row['is_exempt'])
                            <td style="{{ $td }}color:#92400e;font-weight:600;">EXEMPT</td>
                        @else
                            <td style="{{ $td }}{{ $row['tardiness_count'] > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#6b7280;' }}">
                                {{ $row['tardiness_count'] ?: 0 }}
                            </td>
                        @endif
                        @if ($row['is_exempt'])
                            <td style="{{ $td }}color:#92400e;font-weight:600;">EXEMPT</td>
                        @else
                            <td style="{{ $td }}{{ $row['unfiled_count'] > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#6b7280;' }}">
                                {{ $row['unfiled_count'] ?: 0 }}
                            </td>
                        @endif
                        <td style="{{ $td }}{{ $row['official_leave_count'] > 0 ? 'color:#2563eb;font-weight:600;' : 'color:#6b7280;' }}">
                            {{ $row['official_leave_count'] ?: 0 }}
                        </td>
                        <td style="{{ $td }}{{ $row['unofficial_exit_count'] > 0 ? 'color:#d97706;font-weight:600;' : 'color:#6b7280;' }}">
                            {{ $row['unofficial_exit_count'] ?: 0 }}
                        </td>
                        @if ($row['is_exempt'])
                            <td style="{{ $td }}color:#92400e;font-weight:600;">EXEMPT</td>
                        @else
                            <td style="{{ $td }}{{ $row['total_minutes'] > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#6b7280;' }}">
                                {{ $row['total_minutes'] ? $row['total_minutes'].' MINS' : 0 }}
                            </td>
                        @endif
                        <td style="{{ $td }}{{ $row['personal_locator_minutes'] > 0 ? 'color:#d97706;font-weight:600;' : 'color:#6b7280;' }}">
                            {{ $row['personal_locator_minutes'] ? $row['personal_locator_minutes'].' MINS' : 0 }}
                        </td>
                        <td style="{{ $td }}text-align:left;font-size:0.78rem;color:#374151;">
                            {{ $row['remarks'] ?: '-' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

@endsection

@section('page_scripts')
<script>
// If DataTables has been initialized on this table by any script, destroy it immediately.
// Suppress the warning popup that DataTables shows on column count mismatches.
(function destroyDataTablesIfPresent() {
    function tryDestroy() {
        if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) return;
        jQuery.fn.DataTable.ext.errMode = 'none'; // suppress alert popups
        var $t = jQuery('#monitoring-matrix-table');
        if ($t.length && jQuery.fn.DataTable.isDataTable($t)) {
            $t.DataTable().destroy(true);
        }
    }
    // Run immediately (catches eager inits) and again after DOM is ready.
    tryDestroy();
    document.addEventListener('DOMContentLoaded', tryDestroy);
    // Belt-and-suspenders: catch any late init after our DOMContentLoaded handler.
    setTimeout(tryDestroy, 0);
}());

document.addEventListener('DOMContentLoaded', function () {
    var table = document.getElementById('monitoring-matrix-table');
    if (!table) return;

    // ── Search + Employee Type filter (combined, AND logic) ──────────────
    var searchInput = document.getElementById('matrix-search');
    var typeFilter = document.getElementById('matrix-type-filter');

    function applyFilters() {
        var q = searchInput ? searchInput.value.toLowerCase().trim() : '';
        var type = typeFilter ? typeFilter.value : '';
        var rows = table.tBodies[0].rows;
        var visible = 0;
        for (var i = 0; i < rows.length; i++) {
            var name = (rows[i].cells[1] ? rows[i].cells[1].textContent : '').toLowerCase();
            var pos  = (rows[i].cells[2] ? rows[i].cells[2].textContent : '').toLowerCase();
            var matchesSearch = !q || name.indexOf(q) > -1 || pos.indexOf(q) > -1;
            var matchesType = !type || rows[i].getAttribute('data-type') === type;
            var show = matchesSearch && matchesType;
            rows[i].style.display = show ? '' : 'none';
            if (show) rows[i].cells[0].textContent = ++visible;
        }
    }

    if (searchInput) searchInput.addEventListener('input', applyFilters);
    if (typeFilter) typeFilter.addEventListener('change', applyFilters);

    // ── Sortable columns (click header) ─────────────────────────────────
    var sortState = { col: 1, dir: 1 };
    var headers = table.tHead.rows[0].cells;
    var noSort = [0, 10]; // # and Remarks

    for (var h = 0; h < headers.length; h++) {
        if (noSort.indexOf(h) === -1) {
            headers[h].style.cursor = 'pointer';
            headers[h].title = 'Click to sort';
            (function (col) {
                headers[col].addEventListener('click', function () {
                    var dir = (sortState.col === col) ? -sortState.dir : 1;
                    sortState = { col: col, dir: dir };
                    var tbody = table.tBodies[0];
                    var rows = Array.from(tbody.rows);
                    rows.sort(function (a, b) {
                        var av = a.cells[col] ? a.cells[col].textContent.trim() : '';
                        var bv = b.cells[col] ? b.cells[col].textContent.trim() : '';
                        var an = parseFloat(av), bn = parseFloat(bv);
                        if (!isNaN(an) && !isNaN(bn)) return (an - bn) * dir;
                        return av.localeCompare(bv) * dir;
                    });
                    rows.forEach(function (r, i) {
                        r.cells[0].textContent = i + 1;
                        tbody.appendChild(r);
                    });
                });
            }(h));
        }
    }
});

function exportMonitoringMatrixExcel() {
    var typeFilter = document.getElementById('matrix-type-filter');
    startExport('{{ route('export-jobs.create') }}', {
        type: 'monitoring_matrix',
        params: { month: {{ $month }}, year: {{ $year }}, employee_type: typeFilter ? typeFilter.value : '' },
    }, 'Building monitoring matrix&hellip;');
}
</script>
@endsection
