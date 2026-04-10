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
        <div class="muted">Estimated time of arrival</div>
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
        const period = row.getAttribute('data-period') || '';
        const total = row.getAttribute('data-total') || '';
        const filed = row.getAttribute('data-filed') || '';
        body.innerHTML = `<table style="width:100%;border-collapse:collapse"><tbody>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${employee}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Leave Type</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${typeLabel}</td></tr>
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
                    <table class="data-table leave-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave Type</th>
                                <th>Period</th>
                                <th>Total Days</th>
                                <th>Filed At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($requests as $r)
                                <tr id="leave-row-{{ $r->id }}" data-employee="{{ $r->user->name ?? '—' }}" data-type="{{ $r->leave_type }}" data-period="{{ $r->start_date ? \Carbon\Carbon::parse($r->start_date)->format('M d, Y') : '—' }} to {{ $r->end_date ? \Carbon\Carbon::parse($r->end_date)->format('M d, Y') : '—' }}" data-total="{{ $r->total_days ?? '—' }}" data-filed="{{ $r->created_at ? $r->created_at->format('M d, Y') : '—' }}">
                                    <td>{{ $r->user->name ?? '—' }}</td>
                                    <td>{{ $r->leave_type }}</td>
                                    <td>{{ $r->start_date ? \Carbon\Carbon::parse($r->start_date)->format('M d, Y') : '—' }} to {{ $r->end_date ? \Carbon\Carbon::parse($r->end_date)->format('M d, Y') : '—' }}</td>
                                    <td>{{ $r->total_days ?? '—' }}</td>
                                    <td>{{ $r->created_at ? $r->created_at->format('M d, Y') : '—' }}</td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="btn-sm btn-view" type="button" onclick="openPendingModal('leave', {{ $r->id }})">View</button>
                                            <form method="POST" action="{{ route('department-head.leave.approve', $r->id) }}" id="approve-form-{{ $r->id }}" style="display:inline">
                                                @csrf
                                                <button type="button" class="btn-sm btn-approve" onclick="confirmApprove({{ $r->id }})">Approve</button>
                                            </form>

                                            <form method="POST" action="{{ route('department-head.leave.reject', $r->id) }}" id="reject-form-{{ $r->id }}" style="display:inline">
                                                @csrf
                                                <input type="hidden" name="rejection_notes" value="" />
                                                <button type="button" class="btn-sm btn-reject" onclick="promptReject({{ $r->id }})">Reject</button>
                                            </form>
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
                    <table class="data-table leave-table">
                        <thead>
                            <tr><th>Employee</th><th>Departure</th><th>Arrival</th><th>Destination</th><th>Filed At</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        @foreach($etaRequests as $e)
                            <tr id="eta-row-{{ $e->id }}" data-employee="{{ optional($e->user)->name ?? '—' }}" data-departure="{{ $e->departure_date ? \Carbon\Carbon::parse($e->departure_date)->format('M d, Y') : '—' }}" data-arrival="{{ $e->arrival_date ? \Carbon\Carbon::parse($e->arrival_date)->format('M d, Y') : '—' }}" data-destination="{{ $e->destination }}" data-purpose="{{ $e->purpose ?? '' }}" data-filed="{{ $e->created_at ? $e->created_at->format('M d, Y') : '—' }}">
                                <td>{{ optional($e->user)->name ?? '—' }}</td>
                                <td>{{ $e->departure_date ? \Carbon\Carbon::parse($e->departure_date)->format('M d, Y') : '—' }}</td>
                                <td>{{ $e->arrival_date ? \Carbon\Carbon::parse($e->arrival_date)->format('M d, Y') : '—' }}</td>
                                <td>{{ $e->destination }}</td>
                                <td>{{ $e->created_at ? $e->created_at->format('M d, Y') : '—' }}</td>
                                        <td>
                                            <div class="action-btns">
                                                <button class="btn-sm btn-view" type="button" onclick="openPendingModal('eta', {{ $e->id }})">View</button>
                                                <form method="POST" action="{{ route('department-head.eta.approve', $e->id) }}" id="approve-eta-form-{{ $e->id }}" style="display:inline">
                                                    @csrf
                                                    <button type="button" class="btn-sm btn-approve" onclick="confirmApproveEta({{ $e->id }})">Approve</button>
                                            </form>

                                            <form method="POST" action="{{ route('department-head.eta.reject', $e->id) }}" id="reject-eta-form-{{ $e->id }}" style="display:inline">
                                                @csrf
                                                <input type="hidden" name="rejection_notes" value="" />
                                                <button type="button" class="btn-sm btn-reject" onclick="promptRejectEta({{ $e->id }})">Reject</button>
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
                    <table class="data-table leave-table">
                        <thead>
                            <tr><th>Employee</th><th>Type</th><th>Travel Date</th><th>Location</th><th>Filed At</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        @foreach($locatorRequests as $l)
                            <tr id="locator-row-{{ $l->id }}" data-employee="{{ optional($l->user)->name ?? '—' }}" data-type="{{ $l->application_type }}" data-travel="{{ $l->travel_date ? \Carbon\Carbon::parse($l->travel_date)->format('M d, Y') : '—' }}" data-location="{{ $l->location }}" data-purpose="{{ $l->purpose ?? '' }}" data-filed="{{ $l->created_at ? $l->created_at->format('M d, Y') : '—' }}">
                                <td>{{ optional($l->user)->name ?? '—' }}</td>
                                <td>{{ $l->application_type }}</td>
                                   <td>{{ $l->travel_date ? \Carbon\Carbon::parse($l->travel_date)->format('M d, Y') : '—' }}</td>
                                <td>{{ $l->location }}</td>
                                   <td>{{ $l->created_at ? $l->created_at->format('M d, Y') : '—' }}</td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn-sm btn-view" type="button" onclick="openPendingModal('locator', {{ $l->id }})">View</button>
                                        <form method="POST" action="{{ route('department-head.locator.approve', $l->id) }}" id="approve-locator-form-{{ $l->id }}" style="display:inline">
                                            @csrf
                                            <button type="button" class="btn-sm btn-approve" onclick="confirmApproveLocator({{ $l->id }})">Approve</button>
                                        </form>

                                        <form method="POST" action="{{ route('department-head.locator.reject', $l->id) }}" id="reject-locator-form-{{ $l->id }}" style="display:inline">
                                            @csrf
                                            <input type="hidden" name="rejection_notes" value="" />
                                            <button type="button" class="btn-sm btn-reject" onclick="promptRejectLocator({{ $l->id }})">Reject</button>
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
                    Swal.fire({ icon: 'success', text: data.message || 'Leave approved' }).then(()=> location.reload());
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
                fetch(form.action, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: new URLSearchParams({ _token: token }) })
                .then(res => res.json()).then(data => Swal.fire({ icon: 'success', text: data.message || 'Approved' }).then(()=> location.reload()))
                .catch(()=> Swal.fire({ icon: 'error', text: 'Failed to approve' }));
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
        window.Swal.fire({ title: 'Approve Locator?', icon: 'question', showCancelButton: true, confirmButtonText: 'Approve' })
        .then((r) => { if (r.isConfirmed) fetch(form.action, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: new URLSearchParams({ _token: token }) }).then(res => res.json()).then(data => Swal.fire({ icon: 'success', text: data.message || 'Approved' }).then(()=> location.reload())).catch(()=> Swal.fire({ icon: 'error', text: 'Failed to approve' })); });
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

