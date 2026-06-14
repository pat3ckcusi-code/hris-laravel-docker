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
                onclick="startExport('{{ route('export-jobs.create') }}', { type: 'monitoring_matrix', params: { month: {{ $month }}, year: {{ $year }} } }, 'Building monitoring matrix&hellip;')"
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

    <div style="padding:0.75rem 1rem;overflow-x:auto;">
        <table id="monitoring-matrix-table" class="display" style="width:100%;font-size:0.82rem;">
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
                @forelse($rows as $i => $row)
                    <tr>
                        <td style="{{ $td }}color:#6b7280;">{{ $i + 1 }}</td>
                        <td style="{{ $td }}text-align:left;font-weight:600;">{{ $row['name'] }}</td>
                        <td style="{{ $td }}">{{ $row['position'] ?: '—' }}</td>
                        <td style="{{ $td }}{{ $row['undertime_count'] > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#6b7280;' }}">
                            {{ $row['undertime_count'] ?: 0 }}
                        </td>
                        <td style="{{ $td }}{{ $row['tardiness_count'] > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#6b7280;' }}">
                            {{ $row['tardiness_count'] ?: 0 }}
                        </td>
                        <td style="{{ $td }}{{ $row['unfiled_count'] > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#6b7280;' }}">
                            {{ $row['unfiled_count'] ?: 0 }}
                        </td>
                        <td style="{{ $td }}{{ $row['official_leave_count'] > 0 ? 'color:#2563eb;font-weight:600;' : 'color:#6b7280;' }}">
                            {{ $row['official_leave_count'] ?: 0 }}
                        </td>
                        <td style="{{ $td }}{{ $row['unofficial_exit_count'] > 0 ? 'color:#d97706;font-weight:600;' : 'color:#6b7280;' }}">
                            {{ $row['unofficial_exit_count'] ?: 0 }}
                        </td>
                        <td style="{{ $td }}{{ $row['total_minutes'] > 0 ? 'color:#dc2626;font-weight:700;' : 'color:#6b7280;' }}">
                            {{ $row['total_minutes'] ? $row['total_minutes'].' MINS' : 0 }}
                        </td>
                        <td style="{{ $td }}{{ $row['personal_locator_minutes'] > 0 ? 'color:#d97706;font-weight:600;' : 'color:#6b7280;' }}">
                            {{ $row['personal_locator_minutes'] ? $row['personal_locator_minutes'].' MINS' : 0 }}
                        </td>
                        <td style="{{ $td }}text-align:left;font-size:0.78rem;color:#374151;">
                            {{ $row['remarks'] ?: '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11"
                            style="padding:2rem;text-align:center;color:#9ca3af;font-size:0.9rem;">
                            No employees found for this department and period.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection

@section('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (!window.jQuery || !$.fn.DataTable) return;

    $('#monitoring-matrix-table').DataTable({
        pageLength: 10,
        lengthMenu: [10, 25, 50, 100],
        ordering: true,
        order: [[1, 'asc']],   // default sort by name
        columnDefs: [
            { orderable: false, targets: [0, 10] },  // # and Remarks not sortable
            { className: 'dt-center', targets: '_all' },
            { className: 'dt-body-left', targets: [1, 10] },
        ],
        language: {
            search: 'Search employee:',
            lengthMenu: 'Show _MENU_ employees',
            info: 'Showing _START_ to _END_ of _TOTAL_ employees',
            infoEmpty: 'No employees found',
            zeroRecords: 'No matching employees found',
            paginate: {
                first: '«', last: '»', previous: '‹', next: '›',
            },
        },
        // Re-apply row number after pagination/search
        drawCallback: function () {
            var api = this.api();
            var start = api.page.info().start;
            api.column(0, { page: 'current' }).nodes().each(function (cell, i) {
                cell.innerHTML = '<span style="color:#6b7280">' + (start + i + 1) + '</span>';
            });
        },
    });
});
</script>
@endsection
