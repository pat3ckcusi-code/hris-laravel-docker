@extends('dashboards.layout', [
    'title'    => 'Leave Credit Certification',
    'subtitle' => $isLeaveManager
        ? 'Review the pending queue: reject with a reason, or forward to the HR Manager for signing'
        : 'Sign the Certification of Leave Credits line with your own PNPKI certificate',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('content')

{{-- Workflow banner --}}
<div style="display:flex;align-items:flex-start;gap:14px;background:#eff6ff;border:1px solid #bfdbfe;border-left:4px solid #2563eb;border-radius:10px;padding:14px 18px;margin-bottom:24px;">
    <i class="fa-solid fa-signature" style="color:#2563eb;font-size:1.2rem;margin-top:2px;flex-shrink:0;"></i>
    <div>
        <strong style="color:#1e3a8a;font-size:0.92rem;">Leave Credit Certification</strong>
        <p style="margin:4px 0 0;font-size:0.875rem;color:#1e40af;line-height:1.55;">
            Leaves filed with e-signature intent queue up here for a real cryptographic
            co-signature on the "Certification of Leave Credits" line. This is a
            two-step process:
        </p>
        @if($isLeaveManager)
            <p style="margin:4px 0 0;font-size:0.8rem;color:#1e3a8a;line-height:1.5;">
                <strong>You review.</strong> For each leave below, either
                <span style="font-weight:600;">Reject</span> it with a reason (it moves
                to the Rejected tab, where you or the HR Manager can send it back
                later), or select the ones that look correct and click
                <span style="font-weight:600;">Forward Selected</span> to clear them
                for the HR Manager to sign. You never sign anything yourself here.
            </p>
        @else
            <p style="margin:4px 0 0;font-size:0.8rem;color:#1e3a8a;line-height:1.5;">
                <strong>You sign.</strong> Only leaves the Leave Manager has already
                forwarded appear below. Check the ones you want to certify, click
                <span style="font-weight:600;">Sign Selected</span>, and enter
                <span style="font-weight:600;">your own</span> saved e-signature
                password once to sign them. If you haven't saved an e-signature yet,
                do so first under
                <a href="{{ route('esignature-config.index') }}">E-Signature Config</a>
                before this queue can be processed.
            </p>
        @endif
    </div>
</div>

{{-- Shared filter bar - applies to every list below --}}
<div class="hris-table-card" style="margin-bottom:24px;">
    <form method="GET" action="{{ route('leave-certification.index') }}"
        style="display:flex;align-items:center;gap:10px;padding:14px 16px;flex-wrap:wrap;">
        <select name="department" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;min-width:180px;">
            <option value="">All Departments</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->Dept_id }}" @selected(($filters['department'] ?? '') == $dept->Dept_id)>{{ $dept->Dept_name }}</option>
            @endforeach
        </select>
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search name/EmpNo..."
            style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:0.85rem;min-width:200px;">
        <button type="submit" class="hris-btn hris-btn-secondary hris-btn-sm">
            <i class="fa-solid fa-filter"></i> Filter
        </button>
        @if(($filters['department'] ?? null) || ($filters['search'] ?? null))
            <a href="{{ route('leave-certification.index') }}" style="font-size:0.8rem;color:#6b7280;text-decoration:underline;">Clear</a>
        @endif
    </form>
</div>

@if($isLeaveManager)
{{-- Pending Review (Leave Manager) --}}
<div class="hris-table-card" style="margin-bottom:24px;">
    <div class="hris-table-header" style="background:linear-gradient(90deg,#eff6ff,#fff);">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-hourglass-half" style="color:#2563eb;margin-right:8px;"></i>
                Pending Review ({{ $pending->total() }})
            </h2>
            <p class="hris-table-subtitle">Filed with e-signature, still pending, awaiting your review</p>
        </div>
        <div class="hris-table-header-actions">
            <span id="selected-count-label" style="align-self:center;font-size:0.85rem;color:#475569;margin-right:4px;"></span>
            <button type="button" id="forward-selected-btn" class="hris-btn hris-btn-primary" disabled>
                <i class="fa-solid fa-share"></i> Forward Selected
            </button>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="hris-table" style="width:100%;">
            <thead>
                <tr>
                    <th style="width:1%;"><input type="checkbox" id="select-all-cb" @disabled($pending->total() === 0)></th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Leave Type</th>
                    <th>Period</th>
                    <th>Date Filed</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($pending as $leave)
                    <tr>
                        <td><input type="checkbox" class="cert-row-cb" value="{{ $leave->id }}"></td>
                        <td>{{ $leave->user->full_name ?? '—' }}</td>
                        <td>{{ optional($leave->user?->department)->Dept_name ?? '—' }}</td>
                        <td>
                            @if($leave->hasMixedLeaveTypes())
                                <div style="font-size:0.8rem;line-height:1.5;">
                                    @foreach($leave->leaveDatesBreakdown() as $d)
                                        <div>{{ $d['label'] }}: {{ $d['leave_type'] }}</div>
                                    @endforeach
                                </div>
                            @else
                                {{ $leave->leave_type ?? '—' }}
                            @endif
                        </td>
                        <td>{{ $leave->formattedPeriod() }}</td>
                        <td>{{ $leave->date_filed ? \Carbon\Carbon::parse($leave->date_filed)->format('M d, Y') : '—' }}</td>
                        <td>
                            <button type="button" class="hris-btn hris-btn-danger hris-btn-sm reject-btn" data-leave-id="{{ $leave->id }}">
                                <i class="fa-solid fa-xmark"></i> Reject
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="hris-empty-state">Nothing pending review.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($pending->hasPages())
        <div style="padding:12px 16px;">
            {{ $pending->onEachSide(1)->links() }}
        </div>
    @endif
</div>

{{-- Awaiting HR Manager's Signature (Leave Manager, read-only) --}}
<div class="hris-table-card" style="margin-bottom:24px;">
    <div class="hris-table-header">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-paper-plane" style="color:#6b7280;margin-right:8px;"></i>
                Awaiting HR Manager's Signature ({{ $forwarded->total() }})
            </h2>
            <p class="hris-table-subtitle">Forwarded by you, not yet signed - read-only</p>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="hris-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Leave Type</th>
                    <th>Forwarded By</th>
                    <th>Forwarded At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($forwarded as $leave)
                    <tr>
                        <td>{{ $leave->user->full_name ?? '—' }}</td>
                        <td>{{ optional($leave->user?->department)->Dept_name ?? '—' }}</td>
                        <td>
                            @if($leave->hasMixedLeaveTypes())
                                <div style="font-size:0.8rem;line-height:1.5;">
                                    @foreach($leave->leaveDatesBreakdown() as $d)
                                        <div>{{ $d['label'] }}: {{ $d['leave_type'] }}</div>
                                    @endforeach
                                </div>
                            @else
                                {{ $leave->leave_type ?? '—' }}
                            @endif
                        </td>
                        <td>{{ $leave->certificationReviewedBy->full_name ?? '—' }}</td>
                        <td>{{ $leave->certification_reviewed_at?->format('M d, Y g:i A') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="hris-empty-state">Nothing awaiting the HR Manager's signature.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($forwarded->hasPages())
        <div style="padding:12px 16px;">
            {{ $forwarded->onEachSide(1)->links() }}
        </div>
    @endif
</div>
@else
{{-- Awaiting My Signature (HR Manager) --}}
<div class="hris-table-card" style="margin-bottom:24px;">
    <div class="hris-table-header" style="background:linear-gradient(90deg,#eff6ff,#fff);">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-hourglass-half" style="color:#2563eb;margin-right:8px;"></i>
                Awaiting My Signature ({{ $forwarded->total() }})
            </h2>
            <p class="hris-table-subtitle">Forwarded by the Leave Manager, ready to sign</p>
        </div>
        <div class="hris-table-header-actions">
            <span id="selected-count-label" style="align-self:center;font-size:0.85rem;color:#475569;margin-right:4px;"></span>
            <button type="button" id="sign-selected-btn" class="hris-btn hris-btn-primary" disabled>
                <i class="fa-solid fa-pen-nib"></i> Sign Selected
            </button>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="hris-table" style="width:100%;">
            <thead>
                <tr>
                    <th style="width:1%;"><input type="checkbox" id="select-all-cb" @disabled($forwarded->total() === 0)></th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Leave Type</th>
                    <th>Period</th>
                    <th>Forwarded By</th>
                </tr>
            </thead>
            <tbody>
                @forelse($forwarded as $leave)
                    <tr>
                        <td><input type="checkbox" class="cert-row-cb" value="{{ $leave->id }}"></td>
                        <td>{{ $leave->user->full_name ?? '—' }}</td>
                        <td>{{ optional($leave->user?->department)->Dept_name ?? '—' }}</td>
                        <td>
                            @if($leave->hasMixedLeaveTypes())
                                <div style="font-size:0.8rem;line-height:1.5;">
                                    @foreach($leave->leaveDatesBreakdown() as $d)
                                        <div>{{ $d['label'] }}: {{ $d['leave_type'] }}</div>
                                    @endforeach
                                </div>
                            @else
                                {{ $leave->leave_type ?? '—' }}
                            @endif
                        </td>
                        <td>{{ $leave->formattedPeriod() }}</td>
                        <td>{{ $leave->certificationReviewedBy->full_name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="hris-empty-state">Nothing awaiting your signature.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($forwarded->hasPages())
        <div style="padding:12px 16px;">
            {{ $forwarded->onEachSide(1)->links() }}
        </div>
    @endif
</div>
@endif

{{-- Rejected (both roles) --}}
<div class="hris-table-card" style="margin-bottom:24px;">
    <div class="hris-table-header">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-ban" style="color:#dc2626;margin-right:8px;"></i>
                Rejected ({{ $rejected->total() }})
            </h2>
            <p class="hris-table-subtitle">Declined with a reason - send back once resolved</p>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="hris-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Reason</th>
                    <th>Rejected By</th>
                    <th>Rejected At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rejected as $leave)
                    <tr>
                        <td>{{ $leave->user->full_name ?? '—' }}</td>
                        <td>{{ optional($leave->user?->department)->Dept_name ?? '—' }}</td>
                        <td>{{ $leave->certification_review_remarks ?? '—' }}</td>
                        <td>{{ $leave->certificationReviewedBy->full_name ?? '—' }}</td>
                        <td>{{ $leave->certification_reviewed_at?->format('M d, Y g:i A') ?? '—' }}</td>
                        <td>
                            <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm reopen-btn" data-leave-id="{{ $leave->id }}">
                                <i class="fa-solid fa-rotate-left"></i> Send Back to Pending
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="hris-empty-state">Nothing rejected.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($rejected->hasPages())
        <div style="padding:12px 16px;">
            {{ $rejected->onEachSide(1)->links() }}
        </div>
    @endif
</div>

{{-- History --}}
<div class="hris-table-card">
    <div class="hris-table-header">
        <div class="hris-table-header-title">
            <h2 class="hris-table-title" style="font-size:1.05rem;">
                <i class="fa-solid fa-clock-rotate-left" style="color:#6b7280;margin-right:8px;"></i>
                Certification History
            </h2>
            <p class="hris-table-subtitle">Most recently signed first</p>
        </div>
    </div>

    <div style="overflow-x:auto;">
        <table class="hris-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Signed By</th>
                    <th>Signed At</th>
                </tr>
            </thead>
            <tbody>
                @forelse($history as $signing)
                    <tr>
                        <td>{{ optional($signing->signable?->user)->full_name ?? '—' }}</td>
                        <td>{{ $signing->requestedBy->full_name ?? '—' }}</td>
                        <td>{{ $signing->completed_at?->format('M d, Y g:i A') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="hris-empty-state">No certifications signed yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($history->hasPages())
        <div style="padding:12px 16px;">
            {{ $history->links() }}
        </div>
    @endif
</div>

@endsection

@section('page_scripts_after')
<script>
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
}

function postJson(url, body) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: body,
    }).then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); });
}

function getSelectedLeaveIds() {
    return Array.prototype.map.call(
        document.querySelectorAll('.cert-row-cb:checked'),
        function (cb) { return cb.value; }
    );
}

function updateSelectionUi() {
    var ids = getSelectedLeaveIds();
    var actionBtn = document.getElementById('sign-selected-btn') || document.getElementById('forward-selected-btn');
    var label = document.getElementById('selected-count-label');
    if (actionBtn) actionBtn.disabled = ids.length === 0;
    if (label) label.textContent = ids.length ? ids.length + ' selected' : '';

    var rowBoxes = document.querySelectorAll('.cert-row-cb');
    var selectAll = document.getElementById('select-all-cb');
    if (selectAll && rowBoxes.length) {
        selectAll.checked = ids.length === rowBoxes.length;
        selectAll.indeterminate = ids.length > 0 && ids.length < rowBoxes.length;
    }
}

document.getElementById('select-all-cb')?.addEventListener('change', function () {
    var checked = this.checked;
    document.querySelectorAll('.cert-row-cb').forEach(function (cb) { cb.checked = checked; });
    updateSelectionUi();
});

document.querySelectorAll('.cert-row-cb').forEach(function (cb) {
    cb.addEventListener('change', updateSelectionUi);
});

// ── HR Manager: Sign Selected (own password) ─────────────────────────
document.getElementById('sign-selected-btn')?.addEventListener('click', function () {
    var ids = getSelectedLeaveIds();
    if (!ids.length) return;

    Swal.fire({
        title: 'Sign Selected',
        html: 'This certifies the leave-credit figures on the ' + ids.length + ' selected leave(s) with your own saved PNPKI certificate.<br><br>Enter your e-signature password to continue.',
        input: 'password',
        inputPlaceholder: 'Your e-signature password',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sign',
        confirmButtonColor: '#2563eb',
        inputValidator: function (v) { if (!v) return 'Please enter your e-signature password.'; },
    }).then(function (result) {
        if (!result.isConfirmed) return;

        var body = new URLSearchParams({ pnpki_password: result.value });
        ids.forEach(function (id) { body.append('leave_ids[]', id); });

        postJson('{{ route('leave-certification.batch-sign') }}', body)
            .then(function (res) {
                if (!res.ok || !res.data.success) {
                    Swal.fire({ icon: 'error', title: 'Could Not Sign', text: res.data.message || 'Something went wrong.' });
                    return;
                }
                Swal.fire({
                    icon: res.data.errors && res.data.errors.length ? 'warning' : 'success',
                    title: 'Signing In Progress',
                    text: res.data.message,
                }).then(function () { window.location.reload(); });
            })
            .catch(function () {
                Swal.fire({ icon: 'error', text: 'Failed to sign selected leaves.' });
            });
    });
});

// ── Leave Manager: Forward Selected (no password) ─────────────────────
document.getElementById('forward-selected-btn')?.addEventListener('click', function () {
    var ids = getSelectedLeaveIds();
    if (!ids.length) return;

    Swal.fire({
        title: 'Forward Selected',
        text: 'Forward the ' + ids.length + ' selected leave(s) to the HR Manager for signing?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Forward',
        confirmButtonColor: '#2563eb',
    }).then(function (result) {
        if (!result.isConfirmed) return;

        var body = new URLSearchParams();
        ids.forEach(function (id) { body.append('leave_ids[]', id); });

        postJson('{{ route('leave-certification.forward') }}', body)
            .then(function (res) {
                if (!res.ok || !res.data.success) {
                    Swal.fire({ icon: 'error', title: 'Could Not Forward', text: res.data.message || 'Something went wrong.' });
                    return;
                }
                Swal.fire({ icon: 'success', title: 'Forwarded', text: res.data.message })
                    .then(function () { window.location.reload(); });
            })
            .catch(function () {
                Swal.fire({ icon: 'error', text: 'Failed to forward selected leaves.' });
            });
    });
});

// ── Leave Manager: Reject (per row, required reason) ──────────────────
document.querySelectorAll('.reject-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var leaveId = btn.getAttribute('data-leave-id');

        Swal.fire({
            title: 'Reject Certification',
            input: 'textarea',
            inputPlaceholder: 'Reason for rejecting this leave\'s certification...',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Reject',
            confirmButtonColor: '#dc2626',
            inputValidator: function (v) { if (!v || !v.trim()) return 'Please enter a reason.'; },
        }).then(function (result) {
            if (!result.isConfirmed) return;

            var body = new URLSearchParams({ remarks: result.value });

            postJson('{{ url('leave-certification') }}/' + leaveId + '/reject', body)
                .then(function (res) {
                    if (!res.ok || !res.data.success) {
                        Swal.fire({ icon: 'error', title: 'Could Not Reject', text: res.data.message || 'Something went wrong.' });
                        return;
                    }
                    Swal.fire({ icon: 'success', title: 'Rejected', text: res.data.message })
                        .then(function () { window.location.reload(); });
                })
                .catch(function () {
                    Swal.fire({ icon: 'error', text: 'Failed to reject this leave.' });
                });
        });
    });
});

// ── Either role: send a rejected leave back to pending review ─────────
document.querySelectorAll('.reopen-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var leaveId = btn.getAttribute('data-leave-id');

        Swal.fire({
            title: 'Send Back to Pending?',
            text: 'This leave will reappear in the Leave Manager\'s pending review queue.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Send Back',
            confirmButtonColor: '#2563eb',
        }).then(function (result) {
            if (!result.isConfirmed) return;

            postJson('{{ url('leave-certification') }}/' + leaveId + '/reopen', new URLSearchParams())
                .then(function (res) {
                    if (!res.ok || !res.data.success) {
                        Swal.fire({ icon: 'error', title: 'Could Not Send Back', text: res.data.message || 'Something went wrong.' });
                        return;
                    }
                    Swal.fire({ icon: 'success', title: 'Sent Back', text: res.data.message })
                        .then(function () { window.location.reload(); });
                })
                .catch(function () {
                    Swal.fire({ icon: 'error', text: 'Failed to send this leave back to pending.' });
                });
        });
    });
});
</script>
@endsection
