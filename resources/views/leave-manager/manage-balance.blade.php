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

                // "Nearly Exhaust VL/SL" are both scoped to leave-eligible employee types only
                // (User::LEAVE_ELIGIBLE_TYPES: Permanent, Elected Officials, Co-Terminus) -- unlike
                // "Employees" above, which counts every employee type. Threshold is a flat 5 days,
                // deliberately looser than the "< 2" critical convention used elsewhere
                // (LeaveRequestService::criticalBalances(), HRDashboardService) so these cards flag
                // people earlier, before they hit that stricter critical zone.
                $nearlyExhaustThreshold = 5;

                $buildNearlyExhaustList = function (string $field) use ($balances, $departments, $nearlyExhaustThreshold) {
                    return $balances
                        ->filter(function ($b) use ($field, $nearlyExhaustThreshold) {
                            $type = strtolower(trim((string) ($b->user->employee_type ?? '')));

                            return $b->user
                                && in_array($type, \App\Models\User::LEAVE_ELIGIBLE_TYPES, true)
                                && (float) ($b->{$field} ?? 0) < $nearlyExhaustThreshold;
                        })
                        ->map(fn ($b) => [
                            'name' => trim(($b->user->last_name ?? '').', '.($b->user->first_name ?? '')),
                            'department' => $departments[$b->user->Dept_id] ?? '',
                            'value' => (float) ($b->{$field} ?? 0),
                            'value_display' => $b->{$field} ?? '',
                        ])
                        ->sortBy('value')
                        ->values();
                };

                $nearlyExhaustVlList = $buildNearlyExhaustList('VL');
                $nearlyExhaustSlList = $buildNearlyExhaustList('SL');
                $nearlyExhaustVlCount = $nearlyExhaustVlList->count();
                $nearlyExhaustSlCount = $nearlyExhaustSlList->count();

                // Unlike VL/SL above, Solo Parent status isn't tied to employment type or a
                // balance threshold -- just the currently-designated employees among the
                // balances shown.
                $soloParentList = $balances
                    ->filter(fn ($b) => $b->user && $b->user->is_solo_parent)
                    ->map(fn ($b) => [
                        'name' => trim(($b->user->last_name ?? '').', '.($b->user->first_name ?? '')),
                        'department' => $departments[$b->user->Dept_id] ?? '',
                        'sp' => (float) ($b->SP ?? 0),
                        'sp_display' => $b->SP ?? '',
                    ])
                    ->sortBy('name')
                    ->values();
                $soloParentCount = $soloParentList->count();
            @endphp

            <div class="ll-balance-summary">
                <div class="ll-stat-card ll-stat-card--total">
                    <div class="ll-stat-icon"><i class="fas fa-users"></i></div>
                    <div>
                        <div class="ll-stat-label">Employees</div>
                        <div class="ll-stat-value">{{ $totalEmployees }}</div>
                    </div>
                </div>
                <button
                    type="button"
                    id="nearly-exhaust-vl-card"
                    class="ll-stat-card ll-stat-card--vl"
                    aria-haspopup="dialog"
                    aria-controls="nearlyExhaustVlModal"
                    title="Permanent, Elected Officials &amp; Co-Terminus employees with VL below {{ $nearlyExhaustThreshold }} days"
                >
                    <div class="ll-stat-icon"><i class="fas fa-umbrella-beach"></i></div>
                    <div>
                        <div class="ll-stat-label">Nearly Exhaust VL</div>
                        <div class="ll-stat-value">{{ $nearlyExhaustVlCount }}</div>
                    </div>
                </button>
                <button
                    type="button"
                    id="nearly-exhaust-sl-card"
                    class="ll-stat-card ll-stat-card--sl"
                    aria-haspopup="dialog"
                    aria-controls="nearlyExhaustSlModal"
                    title="Permanent, Elected Officials &amp; Co-Terminus employees with SL below {{ $nearlyExhaustThreshold }} days"
                >
                    <div class="ll-stat-icon"><i class="fas fa-briefcase-medical"></i></div>
                    <div>
                        <div class="ll-stat-label">Nearly Exhaust SL</div>
                        <div class="ll-stat-value">{{ $nearlyExhaustSlCount }}</div>
                    </div>
                </button>
                <button
                    type="button"
                    id="solo-parent-card"
                    class="ll-stat-card ll-stat-card--sp"
                    aria-haspopup="dialog"
                    aria-controls="soloParentModal"
                    title="Employees currently designated as Solo Parent"
                >
                    <div class="ll-stat-icon"><i class="fas fa-people-roof"></i></div>
                    <div>
                        <div class="ll-stat-label">Solo Parents</div>
                        <div class="ll-stat-value" id="solo-parent-count">{{ $soloParentCount }}</div>
                    </div>
                </button>
            </div>

            <div class="ll-filter-bar">
                <div class="ll-field ll-field--grow">
                    <label for="leave-balance-search">Search</label>
                    <div class="ll-input-icon-wrap">
                        <i class="fas fa-magnifying-glass"></i>
                        <input id="leave-balance-search" class="ll-input" placeholder="Search employees or department…">
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
            </div>

            <p class="ll-edit-hint"><i class="fas fa-circle-info fa-fw"></i> Click any balance cell to edit it inline. Press Enter to save, Esc to cancel.</p>

            <div class="hris-table-card">
                <div class="hris-table-wrapper">
                    <table id="leave-balance-table" class="hris-table ll-balance-table">
                        <thead>
                            <tr>
                                <th>Employee Name</th>
                                <th>Department</th>
                                <th class="text-center">VL</th>
                                <th class="text-center">SL</th>
                                <th class="text-center">WLNS</th>
                                <th class="text-center">SPL</th>
                                <th class="text-center">CTO</th>
                                <th class="text-center">SPRNT</th>
                                <th class="text-center">Solo Parent</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($balances as $balance)
                                <tr data-type="{{ strtolower($balance->user?->employee_type ?? '') }}">
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
                                    <td class="text-center">
                                        @if($balance->user)
                                            <button
                                                type="button"
                                                class="ll-toggle-pill @if($balance->user->is_solo_parent) ll-toggle-pill--active @else ll-toggle-pill--inactive @endif"
                                                data-user-id="{{ $balance->user_id }}"
                                            >
                                                <i class="fas fa-circle-check"></i>
                                                <span class="ll-toggle-pill-label">{{ $balance->user->is_solo_parent ? 'Active' : 'Inactive' }}</span>
                                            </button>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-overlay" id="nearlyExhaustVlModal">
                <div class="modal-box ll-modal-box">
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                    <div class="ll-modal-header">
                        <span class="ll-modal-icon"><i class="fas fa-triangle-exclamation"></i></span>
                        <div>
                            <h3 style="margin:0;">Nearly Exhaust VL</h3>
                            <p class="ll-modal-subtitle">
                                {{ $nearlyExhaustVlCount }} employee{{ $nearlyExhaustVlCount === 1 ? '' : 's' }} below {{ $nearlyExhaustThreshold }} days
                                &middot; Permanent, Elected Officials &amp; Co-Terminus
                            </p>
                        </div>
                    </div>
                    <div class="hris-table-wrapper">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Employee Name</th>
                                    <th>Department</th>
                                    <th class="text-center">VL Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($nearlyExhaustVlList as $row)
                                    <tr>
                                        <td><span class="ll-emp-name">{{ $row['name'] }}</span></td>
                                        <td class="muted">{{ $row['department'] }}</td>
                                        <td class="text-center">
                                            <span @class(['ll-balance-chip', 'll-balance-chip--zero' => $row['value'] <= 0, 'll-balance-chip--low' => $row['value'] > 0])>{{ $row['value_display'] }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3">
                                            <div class="hris-empty-state">
                                                <div class="hris-empty-state-icon"><i class="fas fa-circle-check"></i></div>
                                                <div class="hris-empty-state-title">No Employees</div>
                                                <p class="hris-empty-state-text">No Permanent, Elected Officials, or Co-Terminus employee currently has a VL balance below {{ $nearlyExhaustThreshold }} days.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal-overlay" id="nearlyExhaustSlModal">
                <div class="modal-box ll-modal-box">
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                    <div class="ll-modal-header">
                        <span class="ll-modal-icon"><i class="fas fa-triangle-exclamation"></i></span>
                        <div>
                            <h3 style="margin:0;">Nearly Exhaust SL</h3>
                            <p class="ll-modal-subtitle">
                                {{ $nearlyExhaustSlCount }} employee{{ $nearlyExhaustSlCount === 1 ? '' : 's' }} below {{ $nearlyExhaustThreshold }} days
                                &middot; Permanent, Elected Officials &amp; Co-Terminus
                            </p>
                        </div>
                    </div>
                    <div class="hris-table-wrapper">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Employee Name</th>
                                    <th>Department</th>
                                    <th class="text-center">SL Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($nearlyExhaustSlList as $row)
                                    <tr>
                                        <td><span class="ll-emp-name">{{ $row['name'] }}</span></td>
                                        <td class="muted">{{ $row['department'] }}</td>
                                        <td class="text-center">
                                            <span @class(['ll-balance-chip', 'll-balance-chip--zero' => $row['value'] <= 0, 'll-balance-chip--low' => $row['value'] > 0])>{{ $row['value_display'] }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3">
                                            <div class="hris-empty-state">
                                                <div class="hris-empty-state-icon"><i class="fas fa-circle-check"></i></div>
                                                <div class="hris-empty-state-title">No Employees</div>
                                                <p class="hris-empty-state-text">No Permanent, Elected Officials, or Co-Terminus employee currently has an SL balance below {{ $nearlyExhaustThreshold }} days.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="modal-overlay" id="soloParentModal">
                <div class="modal-box ll-modal-box">
                    <button type="button" class="modal-close" aria-label="Close">&times;</button>
                    <div class="ll-modal-header">
                        <span class="ll-modal-icon ll-modal-icon--sp"><i class="fas fa-people-roof"></i></span>
                        <div>
                            <h3 style="margin:0;">Solo Parents</h3>
                            <p class="ll-modal-subtitle">
                                {{ $soloParentCount }} employee{{ $soloParentCount === 1 ? '' : 's' }} currently designated
                            </p>
                        </div>
                    </div>
                    <div class="hris-table-wrapper">
                        <table class="hris-table">
                            <thead>
                                <tr>
                                    <th>Employee Name</th>
                                    <th>Department</th>
                                    <th class="text-center">SP Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($soloParentList as $row)
                                    <tr>
                                        <td><span class="ll-emp-name">{{ $row['name'] }}</span></td>
                                        <td class="muted">{{ $row['department'] }}</td>
                                        <td class="text-center">
                                            <span @class(['ll-balance-chip', 'll-balance-chip--zero' => $row['sp'] <= 0])>{{ $row['sp_display'] }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3">
                                            <div class="hris-empty-state">
                                                <div class="hris-empty-state-icon"><i class="fas fa-people-roof"></i></div>
                                                <div class="hris-empty-state-title">No Employees</div>
                                                <p class="hris-empty-state-text">No employee is currently designated as a Solo Parent. Use the toggle in the table below to activate one.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
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
            order: [[0, 'asc']],
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

        // "Nearly Exhaust VL/SL" stat cards -> modals. Lists are rendered server-side once at
        // page load (same static-snapshot behavior as the "Employees" card) -- this only wires
        // open/close.
        function wireStatModal(cardId, modalId) {
            var $modal = $('#' + modalId);
            $('#' + cardId).on('click', function () { $modal.addClass('active'); });
            $modal.on('click', '.modal-close', function () { $modal.removeClass('active'); });
            $modal.on('click', function (e) { if (e.target === this) $modal.removeClass('active'); });
        }
        wireStatModal('nearly-exhaust-vl-card', 'nearlyExhaustVlModal');
        wireStatModal('nearly-exhaust-sl-card', 'nearlyExhaustSlModal');
        wireStatModal('solo-parent-card', 'soloParentModal');

        // Solo Parent designation toggle -> AJAX PATCH, updates the button + stat count in place.
        $('#leave-balance-table').on('click', '.ll-toggle-pill', function () {
            var $btn = $(this);
            if ($btn.prop('disabled')) return;
            var userId = $btn.data('user-id');

            $btn.prop('disabled', true);

            $.ajax({
                url: '{{ url('/leave-manager/manage-balance') }}/' + userId + '/solo-parent',
                method: 'PATCH',
                data: { _token: '{{ csrf_token() }}' },
                success: function (res) {
                    var active = !!res.is_solo_parent;
                    $btn.toggleClass('ll-toggle-pill--active', active);
                    $btn.toggleClass('ll-toggle-pill--inactive', !active);
                    $btn.find('.ll-toggle-pill-label').text(active ? 'Active' : 'Inactive');

                    var $count = $('#solo-parent-count');
                    $count.text(parseInt($count.text(), 10) + (active ? 1 : -1));

                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 1400,
                        icon: 'success',
                        title: active ? 'Marked as Solo Parent' : 'Solo Parent status removed'
                    });
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Update failed';
                    Swal.fire({ toast: true, position: 'top-end', showConfirmButton: true, icon: 'error', title: 'Update failed', text: msg });
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        });
    });
</script>
@endsection
