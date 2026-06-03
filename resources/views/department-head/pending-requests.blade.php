@extends('dashboards.layout', [
    'title' => 'Pending Requests',
    'subtitle' => 'Requests awaiting your approval',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('tiles')
    <article class="tile tab-card active" data-tab="leave">
        <strong>Leave</strong>
        <div class="muted">Pending applications</div>
        <div class="tile-count">{{ $requests->count() ?? 0 }}</div>
    </article>

    <article class="tile tab-card" data-tab="eta">
        <strong>ETA</strong>
        <div class="muted">Employee Travel Authorization</div>
        <div class="tile-count">{{ $etaRequests->count() ?? 0 }}</div>
    </article>

    <article class="tile tab-card" data-tab="locator">
        <strong>Locator</strong>
        <div class="muted">Locator / Travel</div>
        <div class="tile-count">{{ $locatorRequests->count() ?? 0 }}</div>
    </article>
@endsection

@section('modals')
<!-- Pending item modal (dialog style) -->
<dialog id="pendingModal" class="employee-modal">

    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 id="pending-modal-title" style="margin:0">Details</h3>
            <span class="record-email">View details for the pending application</span>
        </div>
        <form method="dialog">
            <button type="submit" class="modal-close" aria-label="Close">x</button>
        </form>
    </div>

    <div id="pending-modal-body" style="margin-top:8px;">
        <!-- populated dynamically -->
    </div>

    <form method="dialog" class="modal-actions" style="margin-top:12px; text-align:right">
        <button class="btn" type="submit">Close</button>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openPendingModal(type, id) {
    const rowId = `${type}-row-${id}`;
    const row = document.getElementById(rowId);
    if (!row) return alert('Details not available');
    const modal = document.getElementById('pendingModal');
    const body = document.getElementById('pending-modal-body');
    const title = document.getElementById('pending-modal-title');
    body.innerHTML = '';
    if (type === 'leave') {
        title.textContent = 'Pending Leave Details';
        const employee = row.getAttribute('data-employee') || '';
        const typeLabel = row.getAttribute('data-type') || '';
        const reason = row.getAttribute('data-reason') || '';
        const period = row.getAttribute('data-period') || '';
        const total = row.getAttribute('data-total') || '';
        const filed = row.getAttribute('data-filed') || '';
        body.innerHTML = `<table style="width:100%;border-collapse:collapse"><tbody>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${employee}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Leave Type</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${typeLabel}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Reason</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${reason}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Period</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${period}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Total Days</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${total}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Filed At</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${filed}</td></tr>
        </tbody></table>`;
        
    } else if (type === 'eta') {
        title.textContent = 'Pending ETA Details';
        const employee = row.getAttribute('data-employee') || '';
        const departure = row.getAttribute('data-departure') || '';
        const arrival = row.getAttribute('data-arrival') || '';
        const destination = row.getAttribute('data-destination') || '';
        const purpose = row.getAttribute('data-purpose') || '';
        const filed = row.getAttribute('data-filed') || '';
        body.innerHTML = `<table style="width:100%;border-collapse:collapse"><tbody>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${employee}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Departure</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${departure}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Arrival</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${arrival}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Destination</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${destination}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Purpose</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${purpose}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Filed At</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${filed}</td></tr>
        </tbody></table>`;
        
    } else if (type === 'locator') {
        title.textContent = 'Pending Locator Details';
        const employee = row.getAttribute('data-employee') || '';
        const ttype = row.getAttribute('data-type') || '';
        const travel = row.getAttribute('data-travel') || '';
        const location = row.getAttribute('data-location') || '';
        const purpose = row.getAttribute('data-purpose') || '';
        const filed = row.getAttribute('data-filed') || '';
        body.innerHTML = `<table style="width:100%;border-collapse:collapse"><tbody>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${employee}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Type</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${ttype}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Travel Date</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${travel}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Location</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${location}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Purpose</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${purpose}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Filed At</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${filed}</td></tr>
        </tbody></table>`;
        
    }
    const dlg = document.getElementById('pendingModal');
    if (dlg && typeof dlg.showModal === 'function') dlg.showModal();
}

    

function closePendingModal() { const dlg = document.getElementById('pendingModal'); if (dlg && typeof dlg.close === 'function') dlg.close(); }
</script>
@endsection

@section('content')
    @if (!$dept)
        <div class="muted">No department found for your account. Ensure your employee number is set in the Departments table.</div>
    @else
        <div id="tab-content">
            <div class="tab-pane" data-pane="leave">
                @if ($requests->isEmpty())
                    <div class="muted">No pending leave requests.</div>
                @else
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave Type</th>
                                <th>Reason / Purpose</th>
                                <th>Period</th>
                                <th>Total Days</th>
                                <th>Filed At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($requests as $r)
                                @php
                                    $leaveTypeLabel = $r->leave_type ?? '';
                                    $isWellness = stripos((string)$leaveTypeLabel, 'wlns') !== false || stripos((string)$leaveTypeLabel, 'wellness') !== false;
                                    $reasonDisplay = $isWellness ? 'Wellness' : ($r->reason ?? '—');
                                @endphp
                                <tr id="leave-row-{{ $r->id }}" data-employee="{{ $r->user->name ?? '—' }}" data-type="{{ $r->leave_type }}" data-reason="{{ $reasonDisplay }}" data-period="{{ $r->start_date ? \Carbon\Carbon::parse($r->start_date)->format('M d, Y') : '—' }} to {{ $r->end_date ? \Carbon\Carbon::parse($r->end_date)->format('M d, Y') : '—' }}" data-total="{{ $r->total_days ?? '—' }}" data-filed="{{ $r->created_at ? $r->created_at->format('M d, Y') : '—' }}">
                                    <td>{{ $r->user->name ?? '—' }}</td>
                                    <td>{{ $r->leave_type }}</td>
                                    <td>{{ $reasonDisplay }}</td>
                                    <td>{{ $r->start_date ? \Carbon\Carbon::parse($r->start_date)->format('M d, Y') : '—' }} to {{ $r->end_date ? \Carbon\Carbon::parse($r->end_date)->format('M d, Y') : '—' }}</td>
                                    <td>{{ $r->total_days ?? '—' }}</td>
                                    <td>{{ $r->created_at ? $r->created_at->format('M d, Y') : '—' }}</td>
                                    <td>
                                        @php
                                            $rawRole = strtolower(str_replace(['-', '_'], ' ', trim((string) (optional(auth()->user())->access_level ?? ''))));
                                            $approverPrefix = ($rawRole === 'administrative officer') ? 'admin-officer' : 'department-head';
                                        @endphp
                                        <div class="action-btns">
                                            <button class="hris-btn hris-btn-secondary hris-btn-sm" type="button" onclick="openPendingModal('leave', {{ $r->id }})">View</button>

                                            @if($r->status === 'pending')
                                                @if(empty($r->printing_allowed))
                                                    <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" id="print-btn-{{ $r->id }}" disabled title="Printing enabled after Allow Printing."><i class="fa fa-print"></i> Print</button>
                                                    <button type="button" class="hris-btn hris-btn-warning hris-btn-sm" id="allow-print-{{ $r->id }}" onclick="allowPrinting({{ $r->id }}, '{{ $approverPrefix }}')">Allow Printing</button>
                                                @else
                                                    <a href="{{ route('employee.leave.print.single', $r->id) }}" class="hris-btn hris-btn-primary hris-btn-sm" target="_blank" id="print-btn-{{ $r->id }}"><i class="fa fa-print"></i> Print</a>
                                                    <form method="POST" action="{{ route($approverPrefix . '.leave.approve', $r->id) }}" id="approve-form-{{ $r->id }}" style="display:inline">
                                                        @csrf
                                                        <button type="button" class="hris-btn hris-btn-primary hris-btn-sm" id="approve-btn-{{ $r->id }}" onclick="confirmApprove({{ $r->id }})">Approve</button>
                                                    </form>
                                                    <form method="POST" action="{{ route($approverPrefix . '.leave.reject', $r->id) }}" id="reject-form-{{ $r->id }}" style="display:inline">
                                                        @csrf
                                                        <input type="hidden" name="rejection_notes" value="" />
                                                        <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="promptReject({{ $r->id }})">Reject</button>
                                                    </form>
                                                @endif

                                            @elseif($r->status === 'approved')
                                                @if(!empty($r->printing_allowed))
                                                    <a href="{{ route('employee.leave.print.single', $r->id) }}" class="hris-btn hris-btn-primary hris-btn-sm" target="_blank" id="print-btn-{{ $r->id }}"><i class="fa fa-print"></i> Print</a>
                                                @else
                                                    <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" id="print-btn-{{ $r->id }}" disabled title="Printing not allowed until approved."><i class="fa fa-print"></i> Print</button>
                                                @endif

                                            @else
                                                {{-- cancelled / rejected / declined -> show only view (already shown) --}}
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="pagination-wrap" style="margin-top:10px">{{ $requests->withQueryString()->links() }}</div>
                @endif
            </div>

            <div class="tab-pane" data-pane="eta" style="display:none">
                @if ($etaRequests->isEmpty())
                    <div class="muted">No pending ETA requests.</div>
                @else
                    <table class="hris-table">
                        <thead>
                            <tr><th>Employee</th><th>Departure</th><th>Arrival</th><th>Destination</th><th>Purpose Details</th><th>Filed At</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        @foreach($etaRequests as $e)
                            <tr id="eta-row-{{ $e->id }}" data-employee="{{ optional($e->user)->name ?? '—' }}" data-departure="{{ $e->departure_date ? \Carbon\Carbon::parse($e->departure_date)->format('M d, Y') : '—' }}" data-arrival="{{ $e->arrival_date ? \Carbon\Carbon::parse($e->arrival_date)->format('M d, Y') : '—' }}" data-destination="{{ $e->destination }}" data-purpose="{{ $e->purpose ?? '' }}" data-filed="{{ $e->created_at ? $e->created_at->format('M d, Y') : '—' }}">
                                <td>{{ optional($e->user)->name ?? '—' }}</td>
                                <td>{{ $e->departure_date ? \Carbon\Carbon::parse($e->departure_date)->format('M d, Y') : '—' }}</td>
                                <td>{{ $e->arrival_date ? \Carbon\Carbon::parse($e->arrival_date)->format('M d, Y') : '—' }}</td>
                                <td>{{ $e->destination }}</td>
                                <td>{{ $e->purpose ?? '—' }}</td>
                                <td>{{ $e->created_at ? $e->created_at->format('M d, Y') : '—' }}</td>
                                        <td>
                                            <div class="action-btns">
                                                <button class="hris-btn hris-btn-secondary hris-btn-sm" type="button" onclick="openPendingModal('eta', {{ $e->id }})">View</button>
                                                <form method="POST" action="{{ route('department-head.eta.approve', $e->id) }}" id="approve-eta-form-{{ $e->id }}" style="display:inline">
                                                    @csrf
                                                    <button type="button" class="hris-btn hris-btn-primary hris-btn-sm" onclick="confirmApproveEta({{ $e->id }})">Approve</button>
                                            </form>

                                            <form method="POST" action="{{ route('department-head.eta.reject', $e->id) }}" id="reject-eta-form-{{ $e->id }}" style="display:inline">
                                                @csrf
                                                <input type="hidden" name="rejection_notes" value="" />
                                                <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="promptRejectEta({{ $e->id }})">Reject</button>
                                            </form>
                                            </div>
                                        </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <div class="pagination-wrap" style="margin-top:10px">{{ $etaRequests->withQueryString()->links('pagination::simple-default') }}</div>
                @endif
            </div>

            <div class="tab-pane" data-pane="locator" style="display:none">
                @if ($locatorRequests->isEmpty())
                    <div class="muted">No pending locator requests.</div>
                @else
                    <table class="hris-table">
                        <thead>
                            <tr><th>Employee</th><th>Type</th><th>Travel Date</th><th>Location</th><th>Purpose of Travel</th><th>Filed At</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                                @foreach($locatorRequests as $l)
                                    <tr id="locator-row-{{ $l->id }}" data-employee="{{ optional($l->user)->name ?? '—' }}" data-type="{{ $l->application_type }}" data-travel="{{ $l->travel_date ? \Carbon\Carbon::parse($l->travel_date)->format('M d, Y') : '—' }}" data-location="{{ $l->location }}" data-purpose="{{ $l->detail ?? '' }}" data-detail="{{ $l->detail ?? '' }}" data-filed="{{ $l->created_at ? $l->created_at->format('M d, Y') : '—' }}">
                                          <td>{{ optional($l->user)->name ?? '—' }}</td>
                                          <td>{{ $l->application_type }}</td>
                                              <td>{{ $l->travel_date ? \Carbon\Carbon::parse($l->travel_date)->format('M d, Y') : '—' }}</td>
                                          <td>{{ $l->location }}</td>
                                          <td>{{ $l->detail ?? '—' }}</td>
                                              <td>{{ $l->created_at ? $l->created_at->format('M d, Y') : '—' }}</td>
                                <td>
                                    <div class="action-btns">
                                        <button class="hris-btn hris-btn-secondary hris-btn-sm" type="button" onclick="openPendingModal('locator', {{ $l->id }})">View</button>
                                        <form method="POST" action="{{ route('department-head.locator.approve', $l->id) }}" id="approve-locator-form-{{ $l->id }}" style="display:inline">
                                            @csrf
                                            <button type="button" class="hris-btn hris-btn-primary hris-btn-sm" onclick="confirmApproveLocator({{ $l->id }})">Approve</button>
                                        </form>

                                        <form method="POST" action="{{ route('department-head.locator.reject', $l->id) }}" id="reject-locator-form-{{ $l->id }}" style="display:inline">
                                            @csrf
                                            <input type="hidden" name="rejection_notes" value="" />
                                            <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" onclick="promptRejectLocator({{ $l->id }})">Reject</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <div class="pagination-wrap" style="margin-top:10px">{{ $locatorRequests->withQueryString()->links('pagination::simple-default') }}</div>
                @endif
            </div>
        </div>
    @endif
@endsection

@section('page_scripts')
<script>
function promptReject(id) {
    if (window.Swal) {
        window.Swal.fire({
            icon: 'warning',
            title: 'Reject request',
            input: 'textarea',
            inputLabel: 'Rejection reason',
            inputPlaceholder: 'Provide reason for rejection',
            showCancelButton: true,
            confirmButtonText: 'Reject',
            preConfirm: (value) => {
                if (!value) {
                    window.Swal.showValidationMessage('Rejection reason is required');
                }
                return value;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.getElementById('reject-form-' + id);
                if (!form) return;
                const token = form.querySelector('input[name="_token"]').value;
                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({ rejection_notes: result.value, _token: token })
                }).then(r => r.json()).then(data => {
                    if (data && data.swal) {
                        Swal.fire(data.swal).then(() => location.reload());
                    } else {
                        Swal.fire({ icon: 'success', text: data.message || 'Leave request rejected.' }).then(() => location.reload());
                    }
                }).catch(e => {
                    Swal.fire({ icon: 'error', text: 'Failed to reject request' });
                });
            }
        });
    } else {
        const reason = prompt('Rejection reason:');
        if (reason) {
            const form = document.getElementById('reject-form-' + id);
            if (!form) return;
            const token = form.querySelector('input[name="_token"]').value;
            fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ rejection_notes: reason, _token: token })
            }).then(() => location.reload());
        }
    }
}

function confirmApprove(id) {
    const form = document.getElementById('approve-form-' + id);
    if (!form) return;
    const token = form.querySelector('input[name="_token"]').value;
    if (window.Swal) {
        window.Swal.fire({
            title: 'Approve application?',
            text: 'Are you sure you want to approve this application?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Approve',
            confirmButtonColor: '#16a34a',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: new URLSearchParams({ _token: token })
                }).then(r => r.json()).then(data => {
                    Swal.fire({ icon: 'success', text: data.message || 'Leave approved' }).then(()=> {
                        const btn = document.getElementById('approve-btn-' + id);
                        if (btn) {
                            btn.disabled = true;
                            btn.classList.remove('btn-approve');
                            btn.classList.add('btn-secondary');
                            btn.textContent = 'Approved';
                        }
                    });
                }).catch(e => {
                    Swal.fire({ icon: 'error', text: 'Failed to approve leave' });
                });
            }
        });
    } else {
        if (confirm('Approve this application?')) {
            if (form) form.submit();
        }
    }
}

function allowPrinting(id, prefix) {
    const token = document.querySelector(`#reject-form-${id} input[name="_token"]`)?.value || document.querySelector(`meta[name="csrf-token"]`)?.getAttribute('content');
    if (!token) return alert('CSRF token missing');
    const url = `/${prefix}/leave/${id}/allow-printing`;
    if (window.Swal) {
        Swal.fire({
            title: 'Allow printing?',
            text: 'This will enable printing for the applicant and show the Approve button.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Allow Printing'
        }).then(result => {
            if (!result.isConfirmed) return;
            fetch(url, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({ _token: token })
            }).then(r => r.json()).then(data => {
                if (data && data.success) {
                    // update UI in-place: replace disabled print and allow-print with actual Print link and Approve button
                    const printBtn = document.getElementById('print-btn-' + id);
                    const allowBtn = document.getElementById('allow-print-' + id);
                    if (printBtn) {
                        const a = document.createElement('a');
                        a.className = printBtn.className.replace(/btn-secondary|btn-disabled-print/, 'btn-primary');
                        a.id = printBtn.id;
                        a.target = '_blank';
                        a.href = `/dashboard/employee/leave/${id}/print`;
                        a.title = 'Print Leave Form';
                        a.innerHTML = '<i class="fa fa-print"></i> Print';
                        printBtn.replaceWith(a);
                    }
                    if (allowBtn) {
                        // create approve form/button
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.style.display = 'inline';
                        form.id = 'approve-form-' + id;
                        // set action according to prefix
                        form.action = `/${prefix}/leave/${id}/approve`;
                        const csrf = document.createElement('input');
                        csrf.type = 'hidden';
                        csrf.name = '_token';
                        csrf.value = token;
                        form.appendChild(csrf);
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'btn-sm btn-approve';
                        btn.id = 'approve-btn-' + id;
                        btn.textContent = 'Approve';
                        btn.onclick = function () { confirmApprove(id); };
                        form.appendChild(btn);
                        allowBtn.replaceWith(form);
                        // create reject form/button and insert after approve form
                        const rejectForm = document.createElement('form');
                        rejectForm.method = 'POST';
                        rejectForm.style.display = 'inline';
                        rejectForm.id = 'reject-form-' + id;
                        rejectForm.action = `/${prefix}/leave/${id}/reject`;
                        const rcsrf = document.createElement('input');
                        rcsrf.type = 'hidden';
                        rcsrf.name = '_token';
                        rcsrf.value = token;
                        rejectForm.appendChild(rcsrf);
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'rejection_notes';
                        hidden.value = '';
                        rejectForm.appendChild(hidden);
                        const rbtn = document.createElement('button');
                        rbtn.type = 'button';
                        rbtn.className = 'btn-sm btn-reject';
                        rbtn.textContent = 'Reject';
                        rbtn.onclick = function () { promptReject(id); };
                        rejectForm.appendChild(rbtn);
                        if (form.parentNode) form.parentNode.insertBefore(rejectForm, form.nextSibling);
                    }
                    Swal.fire({ icon: 'success', text: 'Printing allowed.' });
                } else {
                    Swal.fire({ icon: 'error', text: data.message || 'Failed to allow printing.' });
                }
            }).catch(() => Swal.fire({ icon: 'error', text: 'Failed to allow printing.' }));
        });
    } else {
        if (confirm('Allow printing?')) {
            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token }, body: new URLSearchParams({ _token: token }) })
                .then(() => location.reload()).catch(() => alert('Failed to allow printing'));
        }
    }
}

function promptRejectEta(id) {
    const form = document.getElementById('reject-eta-form-' + id);
    if (!form) return;
    const token = form.querySelector('input[name="_token"]').value;
    if (window.Swal) {
        window.Swal.fire({
            icon: 'warning',
            title: 'Reject ETA request',
            input: 'textarea',
            inputLabel: 'Rejection reason',
            showCancelButton: true,
            confirmButtonText: 'Reject',
            preConfirm: (value) => {
                if (!value) window.Swal.showValidationMessage('Rejection reason is required');
                return value;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({ rejection_notes: result.value, _token: token })
                }).then(r => r.json()).then(data => {
                    Swal.fire({ icon: 'success', text: data.message || 'Rejected' }).then(()=> location.reload());
                }).catch(e => {
                    Swal.fire({ icon: 'error', text: 'Failed to reject request' });
                });
            }
        });
    } else {
        const reason = prompt('Rejection reason:');
        if (reason) {
            fetch(form.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({ rejection_notes: reason, _token: token })
            }).then(()=> location.reload());
        }
    }
}

function confirmApproveEta(id) {
    const form = document.getElementById('approve-eta-form-' + id);
    if (!form) return;
    const token = form.querySelector('input[name="_token"]').value;
    if (window.Swal) {
        window.Swal.fire({ title: 'Approve ETA?', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve' })
        .then((r) => {
            if (r.isConfirmed) {
                console.log('[ETA Approval] Submitting request to:', form.action);
                fetch(form.action, { 
                    method: 'POST', 
                    headers: { 
                        'X-CSRF-TOKEN': token, 
                        'X-Requested-With': 'XMLHttpRequest', 
                        'Accept': 'application/json' 
                    }, 
                    body: new URLSearchParams({ _token: token }) 
                })
                .then(res => {
                    console.log('[ETA Approval] Response status:', res.status);
                    if (!res.ok) {
                        console.error('[ETA Approval] HTTP error, status:', res.status);
                        return res.json().then(data => {
                            console.error('[ETA Approval] Error response body:', data);
                            throw new Error(data.message || `HTTP ${res.status}: ${res.statusText}`);
                        }).catch(err => {
                            console.error('[ETA Approval] Failed to parse error response:', err);
                            throw err;
                        });
                    }
                    return res.json();
                })
                .then(data => {
                    console.log('[ETA Approval] Success response:', data);
                    Swal.fire({ icon: 'success', text: data.message || 'Approved' }).then(()=> location.reload());
                })
                .catch(err => {
                    console.error('[ETA Approval] Request failed:', err.message || err);
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Approval Failed',
                        text: err.message || 'Failed to approve ETA. Please check console for details.' 
                    });
                });
            }
        });
    } else { if (confirm('Approve this ETA?') && form) form.submit(); }
}

function promptRejectLocator(id) {
    const form = document.getElementById('reject-locator-form-' + id);
    if (!form) return;
    const token = form.querySelector('input[name="_token"]').value;
    if (window.Swal) {
        window.Swal.fire({ icon: 'warning', title: 'Reject Locator request', input: 'textarea', inputLabel: 'Rejection reason', showCancelButton: true, confirmButtonText: 'Reject', preConfirm: (v)=> { if (!v) Swal.showValidationMessage('Rejection reason is required'); return v; } })
        .then((result) => {
            if (result.isConfirmed) {
                fetch(form.action, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: new URLSearchParams({ rejection_notes: result.value, _token: token }) })
                .then(res => res.json()).then(data => Swal.fire({ icon: 'success', text: data.message || 'Rejected' }).then(()=> location.reload()))
                .catch(()=> Swal.fire({ icon: 'error', text: 'Failed to reject' }));
            }
        });
    } else {
        const reason = prompt('Rejection reason:');
        if (reason) fetch(form.action, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams({ rejection_notes: reason, _token: token }) }).then(()=> location.reload());
    }
}

function confirmApproveLocator(id) {
    const form = document.getElementById('approve-locator-form-' + id);
    if (!form) return;
    const token = form.querySelector('input[name="_token"]').value;
    if (window.Swal) {
        const row = document.getElementById('locator-row-' + id);
        const employee = row?.getAttribute('data-employee') || '';
        const detail = row?.getAttribute('data-detail') || '';
        const purpose = row?.getAttribute('data-purpose') || '';
        const travel = row?.getAttribute('data-travel') || '';
        const html = `
            <p><strong>Employee:</strong> ${employee}</p>
            <p><strong>Travel Date:</strong> ${travel}</p>
            <p><strong>Detail of Travel:</strong> ${detail}</p>
            <p><strong>Purpose of Travel:</strong> ${purpose}</p>
        `;

        window.Swal.fire({
            title: 'Approve Locator Request?',
            html: html,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Approve',
            cancelButtonText: 'Cancel'
        }).then((r) => {
            if (!r.isConfirmed) return;
            fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({ _token: token })
            }).then(res => res.json()).then(data => {
                Swal.fire({ icon: 'success', text: data.message || 'Locator approved.' }).then(()=> location.reload());
            }).catch(() => Swal.fire({ icon: 'error', text: 'Failed to approve' }));
        });
    } else { if (confirm('Approve this Locator?') && form) form.submit(); }
}

// Show flash messages (success / error / validation) via SweetAlert when the page loads
@if(session('success'))
try {
    if (window.Swal && typeof Swal.fire === 'function') {
        Swal.fire({ icon: 'success', title: 'Success', text: {!! json_encode(session('success')) !!} });
    } else {
        alert({!! json_encode(session('success')) !!});
    }
} catch (e) {}
@endif

@if(session('error'))
try {
    if (window.Swal && typeof Swal.fire === 'function') {
        Swal.fire({ icon: 'error', title: 'Error', text: {!! json_encode(session('error')) !!} });
    } else {
        alert({!! json_encode(session('error')) !!});
    }
} catch (e) {}
@endif

@if($errors->any())
try {
    const _errs = {!! json_encode($errors->all()) !!}.join('\n');
    if (window.Swal && typeof Swal.fire === 'function') {
        Swal.fire({ icon: 'error', title: 'Validation error', text: _errs });
    } else {
        alert(_errs);
    }
} catch (e) {}
@endif

document.addEventListener('DOMContentLoaded', function(){
    const tabs = document.querySelectorAll('.tab-card');
    const panes = document.querySelectorAll('.tab-pane');
    tabs.forEach(t=> t.addEventListener('click', ()=>{
        tabs.forEach(x=>x.classList.remove('active'));
        t.classList.add('active');
        const tab = t.getAttribute('data-tab');
        panes.forEach(p=> {
            if (p.getAttribute('data-pane') === tab) p.style.display = '';
            else p.style.display = 'none';
        });
        const content = document.getElementById('tab-content');
        if (content) {
            content.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        const activePane = document.querySelector('.tab-pane[data-pane="' + tab + '"]');
        if (activePane) {
            document.querySelectorAll('.tab-pane table tr.active-row').forEach(r=> r.classList.remove('active-row'));
            const firstRow = activePane.querySelector('table tbody tr');
            if (firstRow) {
                firstRow.classList.add('active-row');
                const focusEl = firstRow.querySelector('button, a, input');
                if (focusEl) {
                    try { focusEl.focus({ preventScroll: true }); } catch (e) { focusEl.focus(); }
                }
            }
        }
    }));
});
</script>
@endsection

