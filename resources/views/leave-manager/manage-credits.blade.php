@extends('dashboards.layout', [
    'title' => 'Manage Leave Credits',
    'subtitle' => 'Grant leave credits to employees'
])

@section('content')

<section class="card">
    <header>
        <h2>Manage Leave Credits</h2>
    </header>

    <div class="card-body">
        <div class="leave-credits-toolbar">
            <p class="muted">Apply leave credits for selected employees or by department.</p>

            <div>
                <input id="leave-credits-search" class="leave-credits-search" placeholder="Search employees, dept or EmpNo">
            </div>
        </div>

        <div class="table-responsive">
            <table class="credits-table table-bordered table-hover hris-table" id="leaveCreditsTable">
                <thead class="bg-light text-center">
                    <tr>
                        <th style="min-width:240px">Employee Name</th>
                        <th class="col-small text-center">VL</th>
                        <th class="col-small text-center">SL</th>
                        <th class="col-small text-center">Wellness (WLNS)</th>
                        <th class="col-small text-center">SPL</th>
                        <th class="col-small text-center">CTO</th>
                        <th class="col-tiny text-center">Tardiness (min)</th>
                        <th class="col-tiny text-center">Undertime (min)</th>
                        <th class="col-tiny text-center">Deduction (days)</th>
                        <th class="col-deduct text-center">Deduct From</th>
                        <th class="col-action text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($balances as $balance)
                        <tr data-id="{{ $balance->id }}">
                            <td data-label="Employee Name" class="employee-name">
                                @if($balance->user)
                                    @php $empName = trim(($balance->user->last_name ?? '') . ', ' . ($balance->user->first_name ?? '')); @endphp
                                    <span class="emp-name-text">{{ $empName }}</span>
                                    @if(!empty($departments[$balance->user->Dept_id] ?? ''))
                                        <div class="dept-italic">— {{ strtoupper($departments[$balance->user->Dept_id]) }}</div>
                                    @endif
                                @else
                                    -
                                @endif
                            </td>
                            <td data-label="VL" class="text-center current-vl">{{ $balance->VL ?? '' }}</td>
                            <td data-label="SL" class="text-center current-sl">{{ $balance->SL ?? '' }}</td>
                            <td data-label="Wellness (WLNS)" class="text-center current-wlns">{{ $balance->WLNS ?? '' }}</td>
                            <td data-label="SPL" class="text-center current-spl">{{ $balance->SPL ?? '' }}</td>
                            <td data-label="CTO" class="text-center current-cto">{{ $balance->CTO ?? '' }}</td>
                            <td data-label="Tardiness (min)" class="col-tiny text-center"><input type="number" min="0" class="form-control input-small tardiness" step="1"></td>
                            <td data-label="Undertime (min)" class="col-tiny text-center"><input type="number" min="0" class="form-control input-small undertime" step="1"></td>
                            <td data-label="Deduction (days)" class="col-tiny text-center deduction-days">-</td>
                            <td data-label="Deduct From" class="col-deduct text-center">
                                <select class="form-control deduct-from">
                                    <option value="VL">VL</option>
                                    <option value="SL">SL</option>
                                    <option value="CTO">CTO</option>
                                    <option value="NONE">None</option>
                                </select>
                            </td>
                            <td data-label="Action" class="col-action text-center"><button type="button" class="btn btn-sm btn-primary apply-row">Apply</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="muted">No records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection

@section('page_scripts_after')
<script>
    $(function () {
        var table = $('#leaveCreditsTable');

        $('#leave-credits-search').on('input', function () {
            var q = this.value.toLowerCase();
            $('#leaveCreditsTable tbody tr').each(function () {
                var text = $(this).text().toLowerCase();
                $(this).toggle(text.indexOf(q) !== -1);
            });
        });

        // Compute deduction (days) from tardiness + undertime (480 min = 1 day)
        function computeDeduction(tr) {
            var tard = parseFloat(tr.find('.tardiness').val()) || 0;
            var undert = parseFloat(tr.find('.undertime').val()) || 0;
            var total = Math.max(0, tard + undert);
            if (total === 0) {
                tr.find('.deduction-days').text('-');
                return 0;
            }
            var days = total / 480;
            // show up to 3 decimal places, trim trailing zeros
            var text = parseFloat(days.toFixed(3)).toString();
            tr.find('.deduction-days').text(text);
            return days;
        }

        // Recompute on input change
        table.on('input', '.tardiness, .undertime', function () {
            var tr = $(this).closest('tr');
            computeDeduction(tr);
        });

        // (preview column removed)

        // Apply single row (POST to server)
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
                        // update visible balance cell for the field that was deducted
                        if (payload.deduct_from && payload.deduct_from !== 'NONE') {
                            var field = payload.deduct_from;
                            var newVal = res.balance[field] !== undefined ? res.balance[field] : null;
                            // find the td with matching data-label for that field
                            // map model fields to table columns
                            var map = { 'VL':'VL', 'SL':'SL', 'WLNS':'Wellness (WLNS)', 'SPL':'SPL', 'CTO':'CTO', 'SP':'SP' };
                            var label = map[field] || field;
                            var td = tr.find('td[data-label="' + label + '"]');
                            if (td.length) {
                                td.text(newVal);
                            }
                        }

                        // show success toast
                        Swal.fire({
                            icon: 'success',
                            title: 'Applied',
                            text: 'Deduction applied',
                            toast: true,
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 1500
                        });

                        var btn = tr.find('.apply-row');
                        btn.prop('disabled', true).text('Applied');
                        setTimeout(function () { btn.prop('disabled', false).text('Apply'); }, 1400);
                    }
                },
                error: function (xhr) {
                    var msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Apply failed';
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: msg
                    });
                    console.error(msg);
                }
            });
        });
    });
</script>
@endsection
