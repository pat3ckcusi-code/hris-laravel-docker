@extends('dashboards.layout', [
    'title' => 'Manage Leave Balance',
    'subtitle' => 'Adjust and review employees\' leave balances',
])

@section('content')

    <section class="card">
        <header>
            <h2>Manage Leave Balance</h2>
        </header>

        <div class="card-body">
            <div class="leave-balance-toolbar">
                <p class="muted">This page lets leave managers view and adjust employee leave balances.</p>

                <div class="leave-balance-controls">
                    <input id="leave-balance-search" class="leave-balance-search" placeholder="Search employees, dept or EmpNo">
                    <button id="export-csv" class="btn" type="button">Export CSV</button>
                </div>
            </div>

            <div class="table-responsive">
            <table id="leave-balance-table" class="leave-table">
                <thead>
                    <tr>
                        <th>Employee Number</th>
                        <th>Employee Name</th>
                        <th>Department</th>
                        <th>VL</th>
                        <th>SL</th>
                        <th>WLNS</th>
                        <th>SPL</th>
                        <th>CTO</th>
                        <th>SPRNT</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($balances as $balance)
                        <tr>
                            <td>{{ $balance->user?->EmpNo ?? '-' }}</td>
                            <td>
                                @if($balance->user)
                                    {{ trim(($balance->user->last_name ?? '') . ', ' . ($balance->user->first_name ?? '')) }}
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $balance->user ? ($departments[$balance->user->Dept_id] ?? '') : '' }}</td>
                            <td class="editable" data-field="VL" data-id="{{ $balance->id }}">{{ $balance->VL ?? '' }}</td>
                            <td class="editable" data-field="SL" data-id="{{ $balance->id }}">{{ $balance->SL ?? '' }}</td>
                            <td class="editable" data-field="WLNS" data-id="{{ $balance->id }}">{{ $balance->WLNS ?? '' }}</td>
                            <td class="editable" data-field="SPL" data-id="{{ $balance->id }}">{{ $balance->SPL ?? '' }}</td>
                            <td class="editable" data-field="CTO" data-id="{{ $balance->id }}">{{ $balance->CTO ?? '' }}</td>
                            <td class="editable" data-field="SP" data-id="{{ $balance->id }}">{{ $balance->SP ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
            dom: 'rt<"bottom"ip>',
            language: {
                emptyTable: 'No leave balances found.'
            }
        });

        // Wire external search box
        $('#leave-balance-search').on('input', function () {
            table.search(this.value).draw();
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

        // Inline editing: click to replace cell with input
        $('#leave-balance-table').on('click', 'td.editable', function () {
            var td = $(this);
            if (td.find('input.inline-edit').length) return; // already editing

            var original = td.text().trim();
            var field = td.data('field');
            var id = td.data('id');

            var input = $('<input type="text" class="inline-edit" />').val(original).css({width: '100%', padding: '6px'});
            td.empty().append(input);
            input.focus().select();

            function cancel() { td.empty().text(original); }

            function save() {
                var val = input.val().trim();
                if (val === original) { cancel(); return; }

                $.ajax({
                    url: '{{ url('/leave-manager/manage-balance') }}/' + id,
                    method: 'PATCH',
                    data: { field: field, value: val, _token: '{{ csrf_token() }}' },
                    success: function (res) {
                        var newVal = (res && res.balance && (res.balance[field] !== undefined)) ? res.balance[field] : val;
                        td.empty().text(newVal);
                        // prefer SweetAlert2 toast if available
                        if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: false,
                                timer: 1400,
                                icon: 'success',
                                title: 'Saved'
                            });
                        } else {
                            var badge = $('<span class="saved-badge">Saved</span>');
                            td.append(badge);
                            td.addClass('flash-success');
                            setTimeout(function () { badge.fadeOut(200, function () { $(this).remove(); }); }, 1200);
                            setTimeout(function () { td.removeClass('flash-success'); }, 1400);
                        }
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Update failed';
                        if (typeof Swal !== 'undefined' && typeof Swal.fire === 'function') {
                            Swal.fire({
                                toast: true,
                                position: 'top-end',
                                showConfirmButton: true,
                                icon: 'error',
                                title: 'Update failed',
                                text: msg
                            });
                        } else {
                            var badge = $('<span class="error-badge">Error</span>');
                            td.append(badge);
                            td.addClass('flash-error');
                            setTimeout(function () { badge.fadeOut(200, function () { $(this).remove(); }); }, 1800);
                            setTimeout(function () { td.removeClass('flash-error'); }, 1800);
                        }
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
