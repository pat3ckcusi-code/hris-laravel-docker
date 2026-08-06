@extends('dashboards.layout', [
    'title' => 'Manage Leave Credits',
    'subtitle' => 'Grant leave credits to employees'
])

@section('content')

<section class="card">
    <header class="ll-page-header">
        <div class="ll-page-header-icon"><i class="fas fa-coins"></i></div>
        <div>
            <h2>Manage Leave Credits</h2>
            <p class="ll-page-subtitle">Apply attendance-based VL/SL deductions for selected employees</p>
        </div>
    </header>

    <div class="card-body">

        <div class="ll-filter-bar">
            <div class="ll-field ll-field--grow">
                <label for="leave-credits-search">Search</label>
                <div class="ll-input-icon-wrap">
                    <i class="fas fa-magnifying-glass"></i>
                    <input id="leave-credits-search" class="ll-input" placeholder="Search employees or department…">
                </div>
            </div>
            <div class="ll-field">
                <label for="leave-credits-type-filter">Employee Type</label>
                <select id="leave-credits-type-filter" class="ll-select">
                    <option value="">All Employee Types</option>
                    @foreach($employeeTypes as $employeeType)
                        <option value="{{ strtolower($employeeType) }}">{{ $employeeType }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <p class="ll-edit-hint"><i class="fas fa-circle-info fa-fw"></i> Enter tardiness/undertime minutes to compute the deduction automatically, choose where to deduct from, then click Apply.</p>

        <div class="hris-table-card">
            <div class="hris-table-wrapper">
                <table class="hris-table ll-credits-table" id="leaveCreditsTable">
                    <thead>
                        <tr>
                            <th>Employee Name</th>
                            <th class="text-center">VL</th>
                            <th class="text-center">SL</th>
                            <th class="text-center">Tardiness (min)</th>
                            <th class="text-center">Undertime (min)</th>
                            <th class="text-center">Deduction (days)</th>
                            <th class="text-center">Deduct From</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($balances as $balance)
                            <tr data-id="{{ $balance->id }}" data-type="{{ strtolower($balance->user?->employee_type ?? '') }}">
                                <td>
                                    @if($balance->user)
                                        @php $empName = trim(($balance->user->last_name ?? '') . ', ' . ($balance->user->first_name ?? '')); @endphp
                                        <span class="ll-emp-name">{{ $empName }}</span>
                                        @if(!empty($departments[$balance->user->Dept_id] ?? ''))
                                            <span class="ll-dept-sub">{{ strtoupper($departments[$balance->user->Dept_id]) }}</span>
                                        @endif
                                    @else
                                        -
                                    @endif
                                </td>
                                <td data-label="VL" class="text-center">
                                    <span @class(['ll-balance-chip', 'll-balance-chip--zero' => (float) ($balance->VL ?? 0) <= 0])>{{ $balance->VL !== null ? number_format((float) $balance->VL, 3) : '-' }}</span>
                                </td>
                                <td data-label="SL" class="text-center">
                                    <span @class(['ll-balance-chip', 'll-balance-chip--zero' => (float) ($balance->SL ?? 0) <= 0])>{{ $balance->SL !== null ? number_format((float) $balance->SL, 3) : '-' }}</span>
                                </td>
                                <td class="text-center"><input type="number" min="0" step="1" class="ll-input ll-input--sm tardiness"></td>
                                <td class="text-center"><input type="number" min="0" step="1" class="ll-input ll-input--sm undertime"></td>
                                <td class="text-center"><span class="deduction-days ll-deduction-chip">-</span></td>
                                <td class="text-center">
                                    <select class="ll-select ll-select--sm deduct-from">
                                        <option value="VL">VL</option>
                                        <option value="SL">SL</option>
                                        <option value="NONE">None</option>
                                    </select>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="hris-btn-primary hris-btn-sm apply-row">
                                        <i class="fas fa-check fa-fw"></i> Apply
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="muted">No records found.</td></tr>
                        @endforelse
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
        var table = $('#leaveCreditsTable').DataTable({
            pageLength: 25,
            lengthChange: true,
            lengthMenu: [10, 25, 50, 100],
            autoWidth: false,
            order: [[0, 'asc']],
            columnDefs: [
                { orderable: false, targets: [3, 4, 5, 6, 7] }
            ],
            searching: true,
            dom: '<"dt-top-bar"ip>rt<"dt-bottom-bar"lip>',
            language: { emptyTable: 'No records found.' }
        });

        $('#leave-credits-search').on('input', function () {
            table.search(this.value).draw();
        });

        // Employee Type filter (client-side, matches the row's data-type attribute)
        $.fn.dataTable.ext.search.push(function (settings, data, dataIndex, rowData, counter) {
            if (settings.nTable.id !== 'leaveCreditsTable') return true;

            var selected = $('#leave-credits-type-filter').val();
            if (!selected) return true;

            var rowType = $(table.row(dataIndex).node()).data('type');
            return rowType === selected;
        });

        $('#leave-credits-type-filter').on('change', function () {
            table.draw();
        });

        // Compute deduction (days) from tardiness + undertime (480 min = 1 day)
        function computeDeduction(tr) {
            var tard = parseFloat(tr.find('.tardiness').val()) || 0;
            var undert = parseFloat(tr.find('.undertime').val()) || 0;
            var total = Math.max(0, tard + undert);
            var $chip = tr.find('.deduction-days');

            if (total === 0) {
                $chip.text('-').removeClass('ll-deduction-chip--active');
                return 0;
            }
            var days = total / 480;
            var text = parseFloat(days.toFixed(3)).toString();
            $chip.text(text).addClass('ll-deduction-chip--active');
            return days;
        }

        table.on('input', '.tardiness, .undertime', function () {
            var tr = $(this).closest('tr');
            computeDeduction(tr);
        });

        table.on('click', '.apply-row', function () {
            var tr = $(this).closest('tr');
            var id = tr.data('id');
            var deduction = computeDeduction(tr);
            var deduct_from = tr.find('.deduct-from').val();
            var payload = {
                id: id,
                tardiness: parseInt(tr.find('.tardiness').val()) || 0,
                undertime: parseInt(tr.find('.undertime').val()) || 0,
                deduction_days: deduction,
                deduct_from: deduct_from
            };

            $.ajax({
                url: '{{ route('leave-manager.apply-credits') }}',
                method: 'POST',
                data: $.extend(payload, { _token: '{{ csrf_token() }}' }),
                success: function (res) {
                    if (res && res.balance) {
                        if (payload.deduct_from && payload.deduct_from !== 'NONE') {
                            var field = payload.deduct_from;
                            var raw = res.balance[field];
                            var td = tr.find('td[data-label="' + field + '"]');
                            if (td.length) {
                                var display = (raw !== null && raw !== undefined)
                                    ? parseFloat(raw).toFixed(3)
                                    : '-';
                                var isZero = !(raw !== null && raw !== undefined && parseFloat(raw) > 0);
                                td.empty().append(
                                    $('<span class="ll-balance-chip' + (isZero ? ' ll-balance-chip--zero' : '') + '">').text(display)
                                );
                            }
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Applied',
                            text: 'Deduction applied',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 1500
                        });

                        table.row(tr[0]).invalidate().draw(false);
                        var btn = tr.find('.apply-row');
                        btn.prop('disabled', true).html('<i class="fas fa-check fa-fw"></i> Applied');
                        setTimeout(function () { btn.prop('disabled', false).html('<i class="fas fa-check fa-fw"></i> Apply'); }, 1400);
                    }
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Apply failed';
                    Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    console.error(msg);
                }
            });
        });
    });
</script>
@endsection
