@extends('dashboards.layout', [
        'title' => 'Cancel Leaves',
        'subtitle' => 'Leave cancellation requests'
])

@section('content')
<section class="card">
        <header>
                <h2>Cancel Leaves</h2>
        </header>

        <div class="card-body">
                <style>
                    #cancel-leaves-table {
                        table-layout: fixed;
                        width: 100%;
                    }
                    #cancel-leaves-table th,
                    #cancel-leaves-table td {
                        white-space: normal !important;
                        word-wrap: break-word;
                        overflow-wrap: anywhere;
                        padding: 8px 10px;
                        font-size: 13px;
                    }
                    #cancel-leaves-table th { font-weight: 700; }
                    #cancel-leaves-table th:nth-child(1), #cancel-leaves-table td:nth-child(1) { width: 5%; }
                    #cancel-leaves-table th:nth-child(2), #cancel-leaves-table td:nth-child(2) { width: 18%; }
                    #cancel-leaves-table th:nth-child(3), #cancel-leaves-table td:nth-child(3) { width: 12%; }
                    #cancel-leaves-table th:nth-child(4), #cancel-leaves-table td:nth-child(4) { width: 12%; }
                    #cancel-leaves-table th:nth-child(5), #cancel-leaves-table td:nth-child(5) { width: 20%; }
                    #cancel-leaves-table th:nth-child(6), #cancel-leaves-table td:nth-child(6) { width: 16%; }
                    #cancel-leaves-table th:nth-child(7), #cancel-leaves-table td:nth-child(7) { width: 9%; }
                </style>
                <p class="muted">Below are approved leaves for auto-cancel workflows (holiday-related cancellations). Employee cancellation requests are handled on the separate "Employee Cancellation Requests" page.</p>

                {{-- Bulk Holiday Actions --}}
                <div class="holiday-actions mb-3" style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="button" id="btn-bulk-cancel-holiday" class="btn btn-danger">
                        <i class="fas fa-calendar-times"></i> Cancel All Leaves on Declared Holiday
                    </button>
                    <button type="button" id="btn-add-holiday" class="btn btn-warning">
                        <i class="fas fa-calendar-plus"></i> Add Holiday (Auto-Cancel Leaves)
                    </button>
                </div>


                <div class="filter-bar">
                    <div class="filter-field">
                        <label for="filter-month" class="small mb-1">Filter by month</label>
                        @php
                            $monthOptions = [];
                            for ($i = 0; $i < 12; $i++) {
                                $m = date('Y-m', strtotime("-{$i} months"));
                                $label = date('F Y', strtotime($m . '-01'));
                                $monthOptions[$m] = $label;
                            }
                        @endphp
                        <select id="filter-month" name="month" class="form-control form-control-sm">
                            @foreach($monthOptions as $val => $lbl)
                                <option value="{{ $val }}" @if($val === request('month', date('Y-m'))) selected @endif>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="filter-field" style="min-width:200px;">
                        <label for="filter-status" class="small mb-1">Status</label>
                        <select id="filter-status" name="status" class="form-control form-control-sm">
                            <option value="">All statuses</option>
                            <option value="approved" @if(request('status') === 'approved') selected @endif>Approved</option>
                            <option value="cancelled" @if(request('status') === 'cancelled') selected @endif>Cancelled</option>
                            <option value="pending" @if(request('status') === 'pending') selected @endif>Pending</option>
                        </select>
                    </div>
                    <div class="filter-field" style="flex:1">                        
                        <div style="display: flex; align-items: center; gap: 12px; position:relative; width:100%;">
                            <label for="claEmployeeSearch" class="filter-label-emp mb-0" style="font-size:1.18rem;font-weight:600; white-space:nowrap;">Employee</label>
                            <input type="text" id="claEmployeeSearch" class="form-control form-control-lg filter-input-emp" style="font-size:1.15rem; flex:1; min-width:0;" placeholder="Type name or EmpNo to search" autocomplete="off">
                            <input type="hidden" id="claEmployee" name="claEmployee" value="{{ request('emp', '') }}">
                            <div id="claEmployee_suggestions" class="list-group" style="position:absolute;z-index:1050;top:100%;left:0;right:0;display:none;max-height:240px;overflow:auto"></div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <div class="table-summary" style="margin-bottom:10px;font-size:0.95rem;color:#555;">
                        Showing {{ $requests->firstItem() ?? 0 }} to {{ $requests->lastItem() ?? 0 }} of {{ $requests->total() }} records.
                    </div>
                    <table id="cancel-leaves-table" class="leave-table">
                                <thead>
                                        <tr>
            <th>LeaveID</th>
            <th>Employee</th>
            <th>Department</th>
            <th>Leave Type</th>
            <th>Filed / Start</th>
            <th>Dates</th>
            <th>Action</th>
        </tr>
                                </thead>
                                <tbody>
                                        @foreach($requests as $r)
                                                <tr>
                                                        <td class="text-center">{{ $r->id }}</td>
                                                        <td>
                                                                @if($r->user)
                                                                        {{ trim(($r->user->last_name ?? '') . ', ' . ($r->user->first_name ?? '')) }}
                                                                @else
                                                                        -
                                                                @endif
                                                        </td>
                                                        <td class="text-center">
                                                                @if($r->user && !empty($departments[$r->user->Dept_id] ?? ''))
                                                                        {{ $departments[$r->user->Dept_id] }}
                                                                @else
                                                                        -
                                                                @endif
                                                        </td>
                                                        <td class="text-center">{{ strtoupper($r->leave_type ?? '') }}</td>
                                                        <td>
                                                            @php
                                                                $fileDate = $r->date_filed ? \Carbon\Carbon::parse($r->date_filed) : null;
                                                                $rawDates = $r->leaveDates->sortBy('leave_date');
                                                                $firstDate = $rawDates->first();
                                                                $startDate = $firstDate ? \Carbon\Carbon::parse($firstDate->leave_date) : null;
                                                                $selectedMonth = $selectedMonth ?? request('month');
                                                                $fileLabel = $fileDate ? $fileDate->format('M d, Y') : '-';
                                                                $startLabel = $startDate ? $startDate->format('M d, Y') : '-';
                                                            @endphp
                                                            <div style="font-size:0.95rem; line-height:1.25;">
                                                                <strong>Filed:</strong> {{ $fileLabel }}<br>
                                                                <strong>Start:</strong> {{ $startLabel }}
                                                            </div>
                                                            @if($selectedMonth && $fileDate && $startDate)
                                                                @php
                                                                    $fileMonth = $fileDate->format('Y-m');
                                                                    $startMonth = $startDate->format('Y-m');
                                                                    $monthLabel = $fileDate->format('F');
                                                                    $startLabelText = $startDate->format('F');
                                                                @endphp
                                                                @if($fileMonth === $selectedMonth && $startMonth !== $selectedMonth)
                                                                    <div class="badge badge-warning" style="display:inline-block;margin-top:6px;font-size:0.85rem;">
                                                                        Filed in {{ $monthLabel }}, starts in {{ $startLabelText }}
                                                                    </div>
                                                                @endif
                                                            @endif
                                                        </td>
                                                        <td>
                                                            @php
                                                                $rawDates = $r->leaveDates->sortBy('leave_date');
                                                            @endphp
                                                            <div class="dates-container">
                                                                @if($rawDates->count())
                                                                @foreach($rawDates as $ld)
                                                                    @php
                                                                        $label = \Carbon\Carbon::parse($ld->leave_date)->format('M d, Y');
                                                                        $cancelInfo = '';
                                                                        if ($ld->is_cancelled) {
                                                                            $who = $ld->cancelled_by ? (\App\Models\User::find($ld->cancelled_by)->name ?? $ld->cancelled_by) : '—';
                                                                            $when = $ld->cancelled_at ? \Carbon\Carbon::parse($ld->cancelled_at)->format('M d, Y H:i') : '';
                                                                            $reason = $ld->cancel_reason ?? '-';
                                                                            $cancelInfo = "Cancelled by: {$who}\nAt: {$when}\nReason: {$reason}";
                                                                        }
                                                                    @endphp
                                                                    @if($ld->is_cancelled)
                                                                        <button type="button" class="btn btn-sm cancelled-date mr-1" disabled title="{{ $cancelInfo }}">{{ $label }}</button>
                                                                    @else
                                                                        <button type="button" class="btn btn-sm btn-outline-danger mr-1 cancel-date-btn" data-id="{{ $r->id }}" data-date="{{ $ld->leave_date }}">{{ $label }}</button>
                                                                    @endif
                                                                @endforeach
                                                            @else
                                                                --
                                                            @endif
                                                            </div>
                                                        </td>
                                                        <td>
                                                            @if(!empty($r->cancellation_status))
                                                                <div style="margin-bottom:6px"><strong class="text-warning">{{ $r->cancellation_status }}</strong></div>
                                                                <div style="margin-bottom:6px;font-size:0.95rem;color:#444">Reason: {{ $r->cancellation_reason ?? '-' }}</div>
                                                                @if(!empty($r->cancellation_reviewed_at))
                                                                    <div style="font-size:0.9rem;color:#555">Reviewed by: @php $rev = $r->cancellation_reviewed_by ? \App\Models\User::find($r->cancellation_reviewed_by) : null; @endphp {{ $rev ? trim(($rev->last_name ?? '') . ', ' . ($rev->first_name ?? '')) : '-' }} at {{ \Carbon\Carbon::parse($r->cancellation_reviewed_at)->format('M d, Y H:i') }}</div>
                                                                    <div style="font-size:0.9rem;color:#555">Remarks: {{ $r->cancellation_remarks ?? '-' }}</div>
                                                                @endif
                                                            @endif
                                                                @php
                                                                    $cancelledDates = $r->leaveDates->where('is_cancelled', true)->map(function($d){ return \Carbon\Carbon::parse($d->leave_date)->format('M d, Y'); })->values()->all();
                                                                @endphp
                                                                @if(!empty($cancelledDates))
                                                                        <button type="button" class="btn btn-sm btn-primary print-cancelled-btn mr-2"
                                                                            data-cancelled='@json($cancelledDates)'
                                                                            data-leaveid="{{ $r->id }}"
                                                                            data-empno="{{ $r->user->EmpNo ?? '' }}"
                                                                            data-employee="{{ $r->user ? trim(($r->user->last_name ?? '') . ', ' . ($r->user->first_name ?? '')) : '' }}"
                                                                            data-dept="{{ $r->user && !empty($departments[$r->user->Dept_id] ?? '') ? $departments[$r->user->Dept_id] : '' }}"
                                                                            data-leavetype="{{ $r->leave_type ?? '' }}"
                                                                        ><i class="fas fa-print"></i> Print</button>
                                                                @else
                                                                        <button type="button" class="btn btn-sm disabled-print-btn" disabled>Print</button>
                                                                @endif
                                                                {{-- Cancellation request actions --}}
                                                                @if(!empty($r->cancellation_status) && $r->cancellation_status === 'Pending Cancellation')
                                                                    <div style="margin-top:8px">
                                                                        <button class="btn btn-sm btn-success approve-cancellation-btn" data-id="{{ $r->id }}">Approve</button>
                                                                        <button class="btn btn-sm btn-secondary reject-cancellation-btn" data-id="{{ $r->id }}" style="margin-left:8px">Reject</button>
                                                                    </div>
                                                                @endif
                                                        </td>
                                                </tr>
                                        @endforeach
                                </tbody>
                        </table>
                        <div style="margin-top:10px">{{ $requests->appends(request()->query())->links() }}</div>
                </div>
        </div>
</section>

@endsection

@section('page_scripts_after')
<script>
(function($){

    // ── Bulk Cancel All Leaves on Declared Holiday ─────────────────────
    $(document).on('click', '#btn-bulk-cancel-holiday', function(){
        if (typeof Swal === 'undefined') { alert('Dialog library missing'); return; }

        Swal.fire({
            title: 'Cancel All Leaves on a Holiday',
            html:
                '<div style="text-align:left;padding:0 4px">' +
                '<label style="font-weight:600;display:block;margin-bottom:4px">Holiday Date <span style="color:#d33">*</span></label>' +
                '<input type="date" id="swal-bulk-date" class="swal2-input" style="width:100%;margin:0 0 12px 0">' +
                '<label style="font-weight:600;display:block;margin-bottom:4px">Holiday Title <span style="color:#d33">*</span></label>' +
                '<input type="text" id="swal-bulk-title" class="swal2-input" placeholder="e.g. Independence Day" style="width:100%;margin:0">' +
                '</div>',
            showCancelButton: true,
            confirmButtonText: 'Cancel All Leaves',
            confirmButtonColor: '#d33',
            preConfirm: function() {
                var date = document.getElementById('swal-bulk-date').value;
                var title = document.getElementById('swal-bulk-title').value;
                if (!date) { Swal.showValidationMessage('Please select a date'); return false; }
                if (!title || !title.trim()) { Swal.showValidationMessage('Please enter a holiday title'); return false; }
                return { date: date, title: title.trim() };
            }
        }).then(function(res){
            if (!res.isConfirmed || !res.value) return;
            var data = res.value;

            // Confirmation step
            Swal.fire({
                title: 'Are you sure?',
                html: 'This will cancel <strong>ALL</strong> approved leave dates on <strong>' + data.date + '</strong> and refund credits to affected employees.<br><br>Holiday: <strong>' + data.title + '</strong>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, proceed',
                confirmButtonColor: '#d33'
            }).then(function(confirm){
                if (!confirm.isConfirmed) return;

                $.ajax({
                    url: '{{ route("api.leave.bulk-cancel-holiday") }}',
                    method: 'POST',
                    data: {
                        date: data.date,
                        holiday_title: data.title,
                        _token: '{{ csrf_token() }}'
                    },
                    dataType: 'json'
                }).done(function(resp){
                    if (resp && resp.success) {
                        var msg = resp.cancelled_count + ' leave date(s) cancelled for ' + resp.affected_employees + ' employee(s). Credits have been refunded.';
                        if (resp.cancelled_count === 0) msg = 'No approved leave dates found on that date.';
                        Swal.fire('Done', msg, resp.cancelled_count > 0 ? 'success' : 'info').then(function(){ if (resp.cancelled_count > 0) window.location.reload(); });
                    } else {
                        Swal.fire('Error', resp.error || 'Failed to cancel leaves.', 'error');
                    }
                }).fail(function(xhr){
                    var msg = 'Failed to process request.';
                    try { var j = JSON.parse(xhr.responseText); if (j.error) msg = j.error; if (j.message) msg = j.message; } catch(e){}
                    Swal.fire('Error', msg, 'error');
                });
            });
        });
    });

    // ── Add Holiday (SweetAlert2) ─────────────────────────────────────
    $(document).on('click', '#btn-add-holiday', function(){
        if (typeof Swal === 'undefined') { alert('Dialog library missing'); return; }

        Swal.fire({
            title: 'Declare Holiday',
            html:
                '<p style="color:#666;font-size:13px;margin-bottom:12px">Adding a holiday will automatically cancel all approved leaves on that date and refund credits.</p>' +
                '<div style="text-align:left">' +
                '<label style="font-weight:600;display:block;margin-bottom:4px">Holiday Title <span style="color:#d33">*</span></label>' +
                '<input type="text" id="swal-holiday-title" class="swal2-input" placeholder="e.g. Independence Day" style="width:100%;margin:0 0 12px 0">' +
                '<label style="font-weight:600;display:block;margin-bottom:4px">Holiday Date <span style="color:#d33">*</span></label>' +
                '<input type="date" id="swal-holiday-date" class="swal2-input" style="width:100%;margin:0 0 12px 0">' +
                '<label style="font-weight:600;display:block;margin-bottom:4px">Type <span style="color:#d33">*</span></label>' +
                '<select id="swal-holiday-type" class="swal2-select" style="width:100%;margin:0;padding:8px;border:1px solid #d9d9d9;border-radius:4px;font-size:1rem">' +
                '<option value="regular">Regular Holiday</option>' +
                '<option value="special">Special Non-Working Holiday</option>' +
                '<option value="suspension">Work Suspension</option>' +
                '</select>' +
                '</div>',
            showCancelButton: true,
            confirmButtonText: 'Declare Holiday',
            confirmButtonColor: '#e6a800',
            preConfirm: function() {
                var title = document.getElementById('swal-holiday-title').value;
                var date = document.getElementById('swal-holiday-date').value;
                var type = document.getElementById('swal-holiday-type').value;
                if (!title || !title.trim()) { Swal.showValidationMessage('Holiday title is required'); return false; }
                if (!date) { Swal.showValidationMessage('Holiday date is required'); return false; }
                return { title: title.trim(), date: date, type: type };
            }
        }).then(function(res){
            if (!res.isConfirmed || !res.value) return;
            var data = res.value;

            Swal.fire({
                title: 'Confirm Holiday Declaration',
                html: 'Declare <strong>' + data.title + '</strong> on <strong>' + data.date + '</strong> (' + data.type + ')?<br><br>All approved leaves on this date will be <strong>automatically cancelled</strong> and credits refunded.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, declare',
                confirmButtonColor: '#e6a800'
            }).then(function(confirm){
                if (!confirm.isConfirmed) return;

                $.ajax({
                    url: '{{ route("api.holidays.store") }}',
                    method: 'POST',
                    data: {
                        title: data.title,
                        holiday_date: data.date,
                        type: data.type,
                        _token: '{{ csrf_token() }}'
                    },
                    dataType: 'json'
                }).done(function(resp){
                    if (resp && resp.success) {
                        Swal.fire('Holiday Declared', resp.message || 'Holiday added. Overlapping leaves cancelled.', 'success').then(function(){ window.location.reload(); });
                    } else {
                        Swal.fire('Error', resp.error || 'Failed to add holiday.', 'error');
                    }
                }).fail(function(xhr){
                    var msg = 'Failed to add holiday.';
                    try { var j = JSON.parse(xhr.responseText); if (j.error) msg = j.error; if (j.message) msg = j.message; } catch(e){}
                    Swal.fire('Error', msg, 'error');
                });
            });
        });
    });

    // ── Cancel single date ─────────────────────────────────────────────
    $(document).on('click', '.cancel-date-btn', function(){
        const $btn = $(this);
        const leaveId = $btn.data('id');
        const date = $btn.data('date');
        if (!leaveId || !date) return;
        if (typeof Swal === 'undefined' || typeof Swal.fire !== 'function') { alert('Dialog library missing'); return; }

        Swal.fire({
            title: 'Cancel this date?',
            html: `Cancel <strong>${date}</strong> from Leave #${leaveId}. This will refund 1 day to the employee's credits.<br/><br/>Provide a reason (required):`,
            input: 'text',
            inputPlaceholder: 'Reason (required)',
            showCancelButton: true,
            confirmButtonText: 'Yes, cancel date',
            confirmButtonColor: '#d33',
            inputValidator: (value) => { if (!value || !value.trim()) return 'Please provide a reason for the cancellation.'; return null; }
        }).then(function(res){
            if (!res || (!res.isConfirmed && res.value === undefined)) return;
            const reason = res.value || '';
            $.ajax({
                url: '{{ route('api.leave.cancel-date') }}',
                method: 'POST',
                data: { leave_id: leaveId, date: date, reason: reason, _token: '{{ csrf_token() }}' },
                dataType: 'json'
            }).done(function(resp){
                if (resp && resp.success) {
                    Swal.fire('Cancelled', 'Date cancelled and credits refunded.', 'success').then(function(){ window.location.reload(); });
                } else {
                    const msg = resp && resp.error ? resp.error : 'Failed to cancel date.';
                    Swal.fire('Error', msg, 'error');
                }
            }).fail(function(xhr){
                let msg = 'Failed to cancel date.';
                try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){}
                Swal.fire('Error', msg, 'error');
            });
        });
    });

    $(document).on('click', '.print-cancelled-btn', function(){
        const btn = $(this);
        let cancelledData = btn.attr('data-cancelled') || '[]';
        let dates = [];
        try { dates = JSON.parse(cancelledData); } catch(e){ dates = []; }
        const leaveId = btn.data('leaveid') || '';
        const empNo = btn.data('empno') || '';
        const employee = btn.data('employee') || '';
        const dept = btn.data('dept') || '';
        const leavetype = btn.data('leavetype') || '';

        const rowsHtml = `
            <table style="width:700px;margin:12px auto;border-collapse:collapse;border:1px solid #ddd">
                <tbody>
                    <tr><td style="width:180px;padding:8px;border-bottom:1px solid #ddd"><strong>Employee Number</strong></td><td style="padding:8px;border-bottom:1px solid #ddd">${empNo}</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #ddd"><strong>Employee</strong></td><td style="padding:8px;border-bottom:1px solid #ddd">${employee}</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #ddd"><strong>Department</strong></td><td style="padding:8px;border-bottom:1px solid #ddd">${dept}</td></tr>
                    <tr><td style="padding:8px;border-bottom:1px solid #ddd"><strong>Leave Type</strong></td><td style="padding:8px;border-bottom:1px solid #ddd">${leavetype}</td></tr>
                </tbody>
            </table>
        `;

        const datesTable = dates.length ? (`<table style="width:700px;margin:8px auto;border-collapse:collapse;border:1px solid #ddd"><thead><tr><th style="padding:8px;border-bottom:1px solid #ddd;text-align:left;width:60px">#</th><th style="padding:8px;border-bottom:1px solid #ddd;text-align:left">Date</th></tr></thead><tbody>${dates.map((d,i)=>`<tr><td style="padding:8px;border-top:1px solid #eee">${i+1}</td><td style="padding:8px;border-top:1px solid #eee">${d}</td></tr>`).join('')}</tbody></table>`) : `<p style="text-align:center;font-style:italic;margin-top:12px">No cancelled dates listed.</p>`;

        // Certification paragraph and signature block to appear after dates table
        const cancelledCount = (dates && dates.length) ? dates.length : 0;
        const smallNumbers = {1:'one',2:'two',3:'three',4:'four',5:'five',6:'six',7:'seven',8:'eight',9:'nine',10:'ten'};
        const countWord = smallNumbers[cancelledCount] || cancelledCount.toString();
        const dayLabel = cancelledCount === 1 ? 'day' : 'days';
        const countDisplay = cancelledCount > 0 ? `${countWord} (${cancelledCount}) ${dayLabel}` : '0 (0) days';

        const certificationHtml = `
            <div style="max-width:700px;margin:14px auto;color:#222;font-size:13px;line-height:1.4">
                <p>I hereby certify that the information shown above is true and correct. I understand that cancelling the above date(s) will refund <strong>${countDisplay}</strong> to my leave credits. I agree to sign below to confirm this.</p>
            </div>
            <div style="max-width:700px;margin:6px auto 20px;display:flex;justify-content:space-between;align-items:flex-end">
                <div style="text-align:center;flex:0 0 45%">
                    <div style="border-bottom:1px solid #000;margin:18px auto;width:60%"></div>
                    <div style="margin-top:6px;color:#333">HR Representative</div>
                </div>
                <div style="text-align:center;flex:0 0 45%">
                    <div style="border-bottom:1px solid #000;margin:18px auto;width:60%"></div>
                    <div style="margin-top:6px;color:#333;font-weight:700">${employee}</div>
                </div>
            </div>
        `;

        const logoLeft = '{{ asset("assets/login/chrmd1.png") }}';
        const logoRight = '{{ asset("assets/login/mbs.jpg") }}';

        const headerHtml = `
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                <div style="flex:0 0 100px;text-align:left"><img src="${logoLeft}" style="width:80px;height:auto"></div>
                <div style="flex:1;text-align:center;line-height:1.05;margin:0 12px">
                    <div style="font-weight:normal;font-size:12px">Republic of the Philippines</div>
                    <div style="font-weight:normal;font-size:12px">Province of Oriental Mindoro</div>
                    <div style="font-weight:700">CALAPAN CITY</div>
                    <div style="font-weight:700;margin-top:6px">CITY GOVERNMENT OF CALAPAN</div>
                    <div style="font-weight:700">CITY HUMAN RESOURCE MANAGEMENT DEPARTMENT</div>
                    <h3 style="margin-top:8px">Cancelled Dates Report</h3>
                </div>
                <div style="flex:0 0 100px;text-align:right"><img src="${logoRight}" style="width:80px;height:auto"></div>
            </div>
        `;

        const html = `<!doctype html><html><head><meta charset="utf-8"><title>Cancelled Dates Report - Leave ${leaveId}</title><style>body{font-family:Arial,Helvetica,sans-serif;color:#222;margin:20px}</style></head><body>${headerHtml}${rowsHtml}${datesTable}${certificationHtml}<p style="text-align:center;margin-top:12px;font-size:12px;color:#666">Generated: ${new Date().toLocaleString()}</p></body></html>`;
        const win = window.open('', '_blank');
        if (!win) { Swal.fire('Error','Popup blocked. Please allow popups for printing.','error'); return; }
        win.document.write(html);
        win.document.close();
        win.focus();
        setTimeout(function(){ win.print(); }, 300);
    });

    var claEmpTimer = null, claSuggestionIndex = -1;
    function resetClaSuggestions() { claSuggestionIndex = -1; $('#claEmployee_suggestions').hide().empty(); }
    $('#claEmployeeSearch').on('input', function(){
        const q = $(this).val(); $('#claEmployee').val(''); if (claEmpTimer) clearTimeout(claEmpTimer);
        if (!q || q.length < 2) { resetClaSuggestions(); return; }
        claEmpTimer = setTimeout(()=>{
            $.getJSON('{{ route('api.employee.search') }}', { q: q }, function(rows){
                const $box = $('#claEmployee_suggestions'); $box.empty(); if (!rows || !rows.length) { $box.hide(); return; }
                rows.forEach(r=>{
                    const label = (r.FullName || r.EmpNo) + (r.Position ? (' — ' + r.Position) : '') + ' (' + r.EmpNo + ')';
                    const $it = $(`<a href="#" class="list-group-item list-group-item-action">${label}</a>`);
                    $it.data('empno', r.EmpNo); $it.data('label', label);
                    $it.on('click', function(e){ e.preventDefault(); $('#claEmployee').val($(this).data('empno')); $('#claEmployeeSearch').val($(this).data('label')); $box.hide(); applyFilters(); });
                    $box.append($it);
                }); $box.show(); claSuggestionIndex = -1;
            });
        }, 200);
    });

    $('#claEmployeeSearch').on('keydown', function(e){ const $box = $('#claEmployee_suggestions'); const $items = $box.children('.list-group-item'); if (!$items.length) return; if (e.key === 'ArrowDown'){ e.preventDefault(); claSuggestionIndex = Math.min(claSuggestionIndex+1, $items.length-1); $items.removeClass('active').eq(claSuggestionIndex).addClass('active')[0].scrollIntoView({block:'nearest'});} else if (e.key === 'ArrowUp'){ e.preventDefault(); claSuggestionIndex = Math.max(claSuggestionIndex-1, 0); $items.removeClass('active').eq(claSuggestionIndex).addClass('active')[0].scrollIntoView({block:'nearest'});} else if (e.key === 'Enter'){ e.preventDefault(); if (claSuggestionIndex>=0) $items.eq(claSuggestionIndex).trigger('click'); else { const empVal = $('#claEmployee').val()||''; if (empVal) { applyFilters(); return; } const txt = $('#claEmployeeSearch').val()||''; const m = txt.match(/\((\d+)\)\s*$/); if (m && m[1]) { $('#claEmployee').val(m[1]); applyFilters(); return; } $('#claEmployeeSearch').trigger('input'); }} else if (e.key === 'Escape') { $box.hide(); }});

    $(document).on('click', function(e){ if (!$(e.target).closest('#claEmployee_suggestions, #claEmployeeSearch').length) $('#claEmployee_suggestions').hide(); });

    function applyFilters(){ const month = $('#filter-month').val()||''; const status = $('#filter-status').val()||''; const emp = $('#claEmployee').val()||''; const params = []; if (month) params.push('month='+encodeURIComponent(month)); if (status) params.push('status='+encodeURIComponent(status)); if (emp) params.push('emp='+encodeURIComponent(emp)); const url = '{{ route('leave-manager.cancel-leaves') }}' + (params.length ? ('?'+params.join('&')) : ''); window.location.href = url; }

    $(document).on('change', '#filter-month, #filter-status', function(){ applyFilters(); });

    function pollPendingCancellationBadge() {
        const badge = $('.sidebar-badge[data-badge-key="pending_employee_cancellation_requests"]');
        if (!badge.length) return;
        $.getJSON('{{ route('api.leave-manager.pending-cancellation-count') }}')
            .done(function(resp){
                const count = parseInt(resp.count || 0, 10);
                badge.text(count);
                if (count <= 0) {
                    badge.hide();
                } else {
                    badge.show();
                }
            });
    }

    $(function(){
        // Initialize DataTable for sorting and pagination on cancel-leaves table
        if (typeof $.fn.DataTable === 'function') {
            $('#cancel-leaves-table').DataTable({
                paging: false,
                pageLength: 25,
                lengthChange: true,
                lengthMenu: [10, 25, 50, 100],
                autoWidth: false,
                order: [[1, 'asc']],
                // make only the Action column (index 5) not orderable and set widths for key columns
                columnDefs: [
                    { orderable: false, targets: [5] },
                    { width: '20px', targets: 0 },
                    { width: '20px', targets: 4 },
                    { width: 'px', targets: 5 }
                ],
                dom: 'rt<"bottom"ip>',
                language: {
                    emptyTable: 'No approved leaves found.'
                }
            });
        }

        pollPendingCancellationBadge();
        setInterval(pollPendingCancellationBadge, 20000);
    });

    // Approve cancellation request
    $(document).on('click', '.approve-cancellation-btn', function(){
        const leaveId = $(this).data('id');
        if (!leaveId) return;
        if (typeof Swal === 'undefined') { if (!confirm('Approve cancellation request?')) return; }
        if (typeof Swal !== 'undefined') {
            Swal.fire({ title: 'Approve cancellation?', text: 'This will cancel the approved leave and refund credits.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Yes, approve', confirmButtonColor: '#16a34a' })
            .then(function(res){ if (!res.isConfirmed) return; doApproveCancellation(leaveId); });
        } else {
            doApproveCancellation(leaveId);
        }
    });

    function doApproveCancellation(id) {
        $.post(`{{ url('/api/leave') }}/${id}/approve-cancellation`, { _token: '{{ csrf_token() }}' })
            .done(function(resp){ if (resp && resp.success) { if (typeof Swal !== 'undefined') Swal.fire('Approved', 'Cancellation approved and credits refunded.', 'success').then(()=>window.location.reload()); else { alert('Approved'); window.location.reload(); } } else { const msg = resp && resp.error ? resp.error : 'Failed to approve'; if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error'); else alert(msg); } })
            .fail(function(xhr){ let msg = 'Failed to approve cancellation.'; try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){} if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error'); else alert(msg); });
    }

    // Reject cancellation request
    $(document).on('click', '.reject-cancellation-btn', function(){
        const leaveId = $(this).data('id');
        if (!leaveId) return;
        if (typeof Swal === 'undefined') {
            const remarks = prompt('Enter remarks for rejection:'); if (!remarks) return; doRejectCancellation(leaveId, remarks);
            return;
        }
        Swal.fire({ title: 'Reject cancellation?', input: 'text', inputPlaceholder: 'Manager remarks (required)', showCancelButton: true, confirmButtonText: 'Reject', inputValidator: (v) => { if (!v || !v.trim()) return 'Remarks required'; } }).then(function(res){ if (!res.isConfirmed) return; doRejectCancellation(leaveId, res.value); });
    });

    function doRejectCancellation(id, remarks) {
        $.post(`{{ url('/api/leave') }}/${id}/reject-cancellation`, { remarks: remarks, _token: '{{ csrf_token() }}' })
            .done(function(resp){ if (resp && resp.success) { if (typeof Swal !== 'undefined') Swal.fire('Rejected', 'Cancellation request rejected.', 'success').then(()=>window.location.reload()); else { alert('Rejected'); window.location.reload(); } } else { const msg = resp && resp.error ? resp.error : 'Failed to reject'; if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error'); else alert(msg); } })
            .fail(function(xhr){ let msg = 'Failed to reject cancellation.'; try { const j = JSON.parse(xhr.responseText); if (j && j.error) msg = j.error; } catch(e){} if (typeof Swal !== 'undefined') Swal.fire('Error', msg, 'error'); else alert(msg); });
    }
})(jQuery);
</script>
@endsection
