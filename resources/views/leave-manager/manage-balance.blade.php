@extends('dashboards.layout', [
    'title' => 'Manage Leave Balance',
    'subtitle' => 'Adjust and review employees\' leave balances',
])

@section('content')

    <section class="card">
        <header class="ll-page-header">
            <div class="ll-page-header-icon"><i class="fas fa-wallet"></i></div>
            <div>
                <h2>Manage Leave Balance</h2>
                <p class="ll-page-subtitle">Adjust and review employees' leave balances</p>
            </div>
        </header>

        <div class="card-body">

            @php
                $totalEmployees = $balances->count();
                $zeroVl = $balances->filter(fn ($b) => (float) ($b->VL ?? 0) <= 0)->count();
                $zeroSl = $balances->filter(fn ($b) => (float) ($b->SL ?? 0) <= 0)->count();
            @endphp

            <div class="ll-balance-summary">
                <div class="ll-stat-card ll-stat-card--total">
                    <div class="ll-stat-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="ll-stat-label">Employees</div>
                        <div class="ll-stat-value">{{ $totalEmployees }}</div>
                    </div>
                </div>
                <div class="ll-stat-card ll-stat-card--vl">
                    <div class="ll-stat-icon"><i class="fas fa-umbrella-beach"></i></div>
                    <div>
                        <div class="ll-stat-label">Zero VL Balance</div>
                        <div class="ll-stat-value">{{ $zeroVl }}</div>
                    </div>
                </div>
                <div class="ll-stat-card ll-stat-card--sl">
                    <div class="ll-stat-icon"><i class="fas fa-briefcase-medical"></i></div>
                    <div>
                        <div class="ll-stat-label">Zero SL Balance</div>
                        <div class="ll-stat-value">{{ $zeroSl }}</div>
                    </div>
                </div>
            </div>

            <div class="ll-filter-bar">
                <div class="ll-field ll-field--grow">
                    <label for="leave-balance-search">Search</label>
                    <div class="ll-input-icon-wrap">
                        <i class="fas fa-magnifying-glass"></i>
                        <input id="leave-balance-search" class="ll-input" placeholder="Search employees, department, or EmpNo…">
                    </div>
                </div>
                <div class="ll-field">
                    <label for="leave-balance-type-filter">Employee Type</label>
                    <select id="leave-balance-type-filter" class="ll-select">
                        <option value="">All Employee Types</option>
                        @foreach($employeeTypes as $employeeType)
                            <option value="{{ strtolower($employeeType) }}">{{ $employeeType }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="ll-filter-actions">
                    <button id="export-csv" class="hris-btn-secondary" type="button">
                        <i class="fas fa-file-csv fa-fw"></i> Export CSV
                    </button>
                </div>
            </div>

            <p class="ll-edit-hint"><i class="fas fa-circle-info fa-fw"></i> Click any balance cell to edit it inline. Press Enter to save, Esc to cancel.</p>

            <div class="hris-table-card">
                <div class="hris-table-wrapper">
                    <table id="leave-balance-table" class="hris-table ll-balance-table">
                        <thead>
                            <tr>
                                <th>Employee Number</th>
                                <th>Employee Name</th>
                                <th>Department</th>
                                <th class="text-center">VL</th>
                                <th class="text-center">SL</th>
                                <th class="text-center">WLNS</th>
                                <th class="text-center">SPL</th>
                                <th class="text-center">CTO</th>
                                <th class="text-center">SPRNT</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($balances as $balance)
                                <tr data-type="{{ strtolower($balance->user?->employee_type ?? '') }}">
                                    <td><span class="ll-empno">{{ $balance->user?->EmpNo ?? '-' }}</span></td>
                                    <td>
                                        @if($balance->user)
                                            <span class="ll-emp-name">{{ trim(($balance->user->last_name ?? '') . ', ' . ($balance->user->first_name ?? '')) }}</span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="muted">{{ $balance->user ? ($departments[$balance->user->Dept_id] ?? '') : '' }}</td>
                                    <td class="editable text-center" data-field="VL" data-id="{{ $balance->id }}">
                                        <span @class(['ll-balance-chip', 'll-balance-chip--zero' => (float) ($balance->VL ?? 0) <= 0])>{{ $balance->VL ?? '' }}</span>
                                    </td>
                                    <td class="editable text-center" data-field="SL" data-id="{{ $balance->id }}">
                                        <span @class(['ll-balance-chip', 'll-balance-chip--zero' => (float) ($balance->SL ?? 0) <= 0])>{{ $balance->SL ?? '' }}</span>
                                    </td>
                                    <td class="editable text-center" data-field="WLNS" data-id="{{ $balance->id }}">
                                        <span @class(['ll-balance-chip', 'll-balance-chip--zero' => (float) ($balance->WLNS ?? 0) <= 0])>{{ $balance->WLNS ?? '' }}</span>
                                    </td>
                                    <td class="editable text-center" data-field="SPL" data-id="{{ $balance->id }}">
                                        <span @class(['ll-balance-chip', 'll-balance-chip--zero' => (float) ($balance->SPL ?? 0) <= 0])>{{ $balance->SPL ?? '' }}</span>
                                    </td>
                                    <td class="editable text-center" data-field="CTO" data-id="{{ $balance->id }}">
                                        <span @class(['ll-balance-chip', 'll-balance-chip--zero' => (float) ($balance->CTO ?? 0) <= 0])>{{ $balance->CTO ?? '' }}</span>
                                    </td>
                                    <td class="editable text-center" data-field="SP" data-id="{{ $balance->id }}">
                                        <span @class(['ll-balance-chip', 'll-balance-chip--zero' => (float) ($balance->SP ?? 0) <= 0])>{{ $balance->SP ?? '' }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('page_scripts_after')
<script>
    $(function () {
        var table = $('#leave-balance-table').DataTable({
            pageLength: 25,
            lengthChange: true,
            lengthMenu: [10, 25, 50, 100],
            autoWidth: false,
            order: [[1, 'asc']],
            columnDefs: [
                { orderable: false, targets: [] }
            ],
            // disable the built-in filter input UI (we use the external search box)
            searching: true,
            dom: '<"dt-top-bar"ip>rt<"dt-bottom-bar"lip>',
            language: {
                emptyTable: 'No leave balances found.'
            }
        });

        // Wire external search box
        $('#leave-balance-search').on('input', function () {
            table.search(this.value).draw();
        });

        // Employee Type filter (client-side, matches the row's data-type attribute)
        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex, rowData, counter) {
            if (settings.nTable.id !== 'leave-balance-table') return true;

            var selected = $('#leave-balance-type-filter').val();
            if (!selected) return true;

            var rowType = $(table.row(dataIndex).node()).data('type');
            return rowType === selected;
        });

        $('#leave-balance-type-filter').on('change', function () {
            table.draw();
        });

        // Basic CSV export (client-side)
        $('#export-csv').on('click', function () {
            var rows = [];
            $('#leave-balance-table thead tr').each(function () {
                var cols = $(this).find('th').map(function () { return $(this).text().trim(); }).get();
                rows.push(cols.join(','));
            });
            $('#leave-balance-table tbody tr').each(function () {
                var cols = $(this).find('td').map(function () { return '"' + ($(this).text().trim().replace(/"/g, '""')) + '"'; }).get();
                if (cols.length) rows.push(cols.join(','));
            });

            var csv = rows.join('\n');
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'leave_balances.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });

        // Re-wraps a balance value in its "chip" span, tinting it red when the balance is zero
        // or negative -- keeps the low-balance affordance accurate after every inline edit,
        // not just on initial page load.
        function makeChip(value) {
            var num = parseFloat(value);
            var isZero = !isNaN(num) ? num <= 0 : (value === '' || value === '0');
            return $('<span class="ll-balance-chip' + (isZero ? ' ll-balance-chip--zero' : '') + '">').text(value);
        }

        // Inline editing: click to replace cell with input
        $('#leave-balance-table').on('click', 'td.editable', function () {
            var td = $(this);
            if (td.find('input.inline-edit').length) return; // already editing

            var original = td.text().trim();
            var field = td.data('field');
            var id = td.data('id');

            var input = $('<input type="text" class="inline-edit ll-inline-edit-input" />').val(original);
            td.empty().append(input);
            input.focus().select();

            function cancel() { td.empty().append(makeChip(original)); }

            function save() {
                var val = input.val().trim();
                if (val === original) { cancel(); return; }

                $.ajax({
                    url: '{{ url('/leave-manager/manage-balance') }}/' + id,
                    method: 'PATCH',
                    data: { field: field, value: val, _token: '{{ csrf_token() }}' },
                    success: function (res) {
                        var newVal = (res && res.balance && (res.balance[field] !== undefined)) ? res.balance[field] : val;
                        td.empty().append(makeChip(newVal));
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 1400,
                            icon: 'success',
                            title: 'Saved'
                        });
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Update failed';
                        Swal.fire({
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: true,
                            icon: 'error',
                            title: 'Update failed',
                            text: msg
                        });
                        cancel();
                        console.error(msg);
                    }
                });
            }

            input.on('blur', save);
            input.on('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); save(); }
                if (e.key === 'Escape') { e.preventDefault(); cancel(); }
            });
        });
    });
</script>
@endsection
