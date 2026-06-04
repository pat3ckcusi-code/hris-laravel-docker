@extends('dashboards.layout', [
    'title' => 'Approved Requests',
    'subtitle' => 'Previously approved requests',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('tiles')
    <article class="tile tab-card active" data-tab="leave">
        <strong>Leave</strong>
        <div class="muted">Approved leave applications</div>
        <div class="tile-count">{{ method_exists($requests, 'total') ? $requests->total() : ($requests->count() ?? 0) }}</div>
    </article>

    <article class="tile tab-card" data-tab="eta">
        <strong>ETA</strong>
        <div class="muted">Approved ETA requests</div>
        <div class="tile-count">{{ method_exists($etaRequests, 'total') ? $etaRequests->total() : ($etaRequests->count() ?? 0) }}</div>
    </article>

    <article class="tile tab-card" data-tab="locator">
        <strong>Locator</strong>
        <div class="muted">Approved locator requests</div>
        <div class="tile-count">{{ method_exists($locatorRequests, 'total') ? $locatorRequests->total() : ($locatorRequests->count() ?? 0) }}</div>
    </article>
@endsection

@section('content')
    @if (!$dept)
        <div class="muted">No department found for your account. Ensure your employee number is set in the Departments table.</div>
    @else
        @php
            $prevDate = (new DateTime())->setDate($year, $month, 1)->modify('-1 month');
            $nextDate = (new DateTime())->setDate($year, $month, 1)->modify('+1 month');
        @endphp
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <div style="display:flex;gap:10px;align-items:center;">
                <button class="month-nav" onclick="window.location='?month={{ $prevDate->format('n') }}&year={{ $prevDate->format('Y') }}'">&laquo; Prev</button>
                <div class="font-weight-bold">{{ date('F', mktime(0,0,0,$month,1,$year)) }} {{ $year }}</div>
                <button class="month-nav" onclick="window.location='?month={{ $nextDate->format('n') }}&year={{ $nextDate->format('Y') }}'">Next &raquo;</button>
            </div>
            <div>
                <button class="month-nav" onclick="window.location='?month={{ date('n') }}&year={{ date('Y') }}'">This Month</button>
            </div>
        </div>
        <div id="tab-content">
            <div class="tab-pane" data-pane="leave">
                @if ($requests->isEmpty())
                    <div class="muted">No approved leave requests.</div>
                @else
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Leave Type</th>
                                <th>Period</th>
                                <th>Total Days</th>
                                <th>Approved At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($requests as $r)
                                <tr id="leave-row-{{ $r->id }}" data-employee="{{ $r->user->name ?? '—' }}" data-type="{{ $r->leave_type }}" data-period="{{ \Carbon\Carbon::parse($r->start_date)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($r->end_date)->format('M d, Y') }}" data-total="{{ $r->total_days ?? '—' }}" data-approved="{{ $r->updated_at ? $r->updated_at->format('M d, Y') : '—' }}" data-vl="{{ optional($r->user->leaveBalance)->VL ?? '0' }}" data-sl="{{ optional($r->user->leaveBalance)->SL ?? '0' }}">
                                    <td>{{ $r->user->name ?? '—' }}</td>
                                    <td>{{ $r->leave_type }}</td>
                                    <td>{{ \Carbon\Carbon::parse($r->start_date)->format('M d, Y') }} to {{ \Carbon\Carbon::parse($r->end_date)->format('M d, Y') }}</td>
                                    <td>{{ $r->total_days ?? '—' }}</td>
                                    <td>{{ $r->updated_at ? $r->updated_at->format('M d, Y') : '—' }}</td>
                                    <td>
                                        <div class="action-btns">
                                            <button class="hris-btn hris-btn-secondary hris-btn-sm" type="button" onclick="openApprovedModal('leave', {{ $r->id }})">View</button>
                                            <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="printApproved('leave', {{ $r->id }})">Print</button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @include('partials.simple-pagination', ['paginator' => $requests])
                @endif
            </div>

            <div class="tab-pane" data-pane="eta" style="display:none">
                @if ($etaRequests->isEmpty())
                    <div class="muted">No approved ETA requests.</div>
                @else
                    <table class="hris-table">
                        <thead>
                            <tr><th>Employee</th><th>Departure</th><th>Arrival</th><th>Destination</th><th>Approved At</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        @foreach($etaRequests as $e)
                            <tr id="eta-row-{{ $e->id }}" data-employee="{{ optional($e->user)->name ?? '—' }}" data-departure="{{ \Carbon\Carbon::parse($e->departure_date)->format('M d, Y') }}" data-arrival="{{ \Carbon\Carbon::parse($e->arrival_date)->format('M d, Y') }}" data-destination="{{ $e->destination }}" data-purpose="{{ $e->purpose ?? '' }}" data-approved="{{ $e->updated_at ? $e->updated_at->format('M d, Y') : '—' }}">
                                <td>{{ optional($e->user)->name ?? '—' }}</td>
                                <td>{{ \Carbon\Carbon::parse($e->departure_date)->format('M d, Y') }}</td>
                                <td>{{ \Carbon\Carbon::parse($e->arrival_date)->format('M d, Y') }}</td>
                                <td>{{ $e->destination }}</td>
                                <td>{{ $e->updated_at ? $e->updated_at->format('M d, Y') : '—' }}</td>
                                <td>
                                    <div class="action-btns">
                                        <button class="hris-btn hris-btn-secondary hris-btn-sm" type="button" onclick="openApprovedModal('eta', {{ $e->id }})">View</button>
                                        <a class="hris-btn hris-btn-secondary hris-btn-sm" href="{{ route('employee.eta.print.single', ['eta' => $e->id]) }}" target="_blank">Print</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @include('partials.simple-pagination', ['paginator' => $etaRequests, 'pageParam' => 'eta_page'])
                @endif
            </div>

            <div class="tab-pane" data-pane="locator" style="display:none">
                @if ($locatorRequests->isEmpty())
                    <div class="muted">No approved locator requests.</div>
                @else
                    <table class="hris-table">
                        <thead>
                            <tr><th>Employee</th><th>Type</th><th>Travel Date</th><th>Location</th><th>Approved At</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        @foreach($locatorRequests as $l)
                            <tr id="locator-row-{{ $l->id }}" data-employee="{{ optional($l->user)->name ?? '—' }}" data-type="{{ $l->application_type }}" data-travel="{{ \Carbon\Carbon::parse($l->travel_date)->format('M d, Y') }}" data-location="{{ $l->location }}" data-purpose="{{ $l->purpose ?? '' }}" data-approved="{{ $l->updated_at ? $l->updated_at->format('M d, Y') : '—' }}">
                                <td>{{ optional($l->user)->name ?? '—' }}</td>
                                <td>{{ $l->application_type }}</td>
                                <td>{{ \Carbon\Carbon::parse($l->travel_date)->format('M d, Y') }}</td>
                                <td>{{ $l->location }}</td>
                                <td>{{ $l->updated_at ? $l->updated_at->format('M d, Y') : '—' }}</td>
                                <td>
                                    <div class="action-btns">
                                        <button class="hris-btn hris-btn-secondary hris-btn-sm" type="button" onclick="openApprovedModal('locator', {{ $l->id }})">View</button>
                                        <a class="hris-btn hris-btn-secondary hris-btn-sm" href="{{ route('employee.locator.print.single', ['locator' => $l->id]) }}" target="_blank">Print</a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @include('partials.simple-pagination', ['paginator' => $locatorRequests, 'pageParam' => 'locator_page'])
                @endif
            </div>
        </div>
    @endif
@endsection

@section('page_scripts')
<script>
// Simple tab switching
document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.tab-card').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.tab-card').forEach(c=>c.classList.remove('active'));
            card.classList.add('active');
            const tab = card.getAttribute('data-tab');
            document.querySelectorAll('.tab-pane').forEach(p => p.style.display = (p.getAttribute('data-pane') === tab) ? '' : 'none');
        });
    });
});

function printApproved(type, id) {
    if (type === 'leave') {
        // Open server-side PDF print endpoint for the leave
        const url = `{{ url('dashboard/employee/leave') }}/${id}/print`;
        window.open(url, '_blank');
    }
}
</script>
@endsection

@section('modals')
<!-- Approved item modal (dialog style like Add New Employee) -->
<dialog id="approvedModal" class="employee-modal">

    <header>
        <h3 id="approved-modal-title">Details</h3>
        <span class="record-email">View details for the approved application</span>
    </header>

    <div id="approved-modal-body" style="margin-top:8px;">
        <!-- populated dynamically -->
    </div>

    <form method="dialog" class="modal-actions" style="margin-top:12px; text-align:right">
        <button class="btn" type="submit">Close</button>
        <button class="btn" type="button" id="approved-modal-print">Print</button>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function openApprovedModal(type, id) {
    const rowId = `${type}-row-${id}`;
    const row = document.getElementById(rowId);
    if (!row) return alert('Details not available');
    const modal = document.getElementById('approved-modal');
    const body = document.getElementById('approved-modal-body');
    const title = document.getElementById('approved-modal-title');
    body.innerHTML = '';
    if (type === 'leave') {
        title.textContent = 'Approved Leave Details';
        const employee = row.getAttribute('data-employee') || '';
        const typeLabel = row.getAttribute('data-type') || '';
        const period = row.getAttribute('data-period') || '';
        const total = row.getAttribute('data-total') || '';
        const approved = row.getAttribute('data-approved') || '';
        const vl = row.getAttribute('data-vl') || '0';
        const sl = row.getAttribute('data-sl') || '0';
        body.innerHTML = `<table style="width:100%;border-collapse:collapse"><tbody>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${employee}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Leave Type</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${typeLabel}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Period</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${period}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Total Days</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${total}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Approved At</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${approved}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Vacation Leave Balance</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${vl}</td></tr>
            <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Sick Leave Balance</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${sl}</td></tr>
        </tbody></table>`;
        document.getElementById('approved-modal-print').onclick = () => printApproved('leave', id);
    } else if (type === 'eta') {
        title.textContent = 'Approved ETA Details';
        const employee = row.getAttribute('data-employee') || '';
        const departure = row.getAttribute('data-departure') || '';
        const arrival = row.getAttribute('data-arrival') || '';
        const destination = row.getAttribute('data-destination') || '';
        const purpose = row.getAttribute('data-purpose') || '';
        const approved = row.getAttribute('data-approved') || '';
        body.innerHTML = `<table style="width:100%;border-collapse:collapse"><tbody>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Employee</strong></td><td style="padding:6px;border:1px solid #eee">${employee}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Departure</strong></td><td style="padding:6px;border:1px solid #eee">${departure}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Arrival</strong></td><td style="padding:6px;border:1px solid #eee">${arrival}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Destination</strong></td><td style="padding:6px;border:1px solid #eee">${destination}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Purpose</strong></td><td style="padding:6px;border:1px solid #eee">${purpose}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Approved At</strong></td><td style="padding:6px;border:1px solid #eee">${approved}</td></tr>
        </tbody></table>`;
        document.getElementById('approved-modal-print').onclick = () => window.open(`{{ url('dashboard/employee/eta-locator') }}/${id}/print`, '_blank');
    } else if (type === 'locator') {
        title.textContent = 'Approved Locator Details';
        const employee = row.getAttribute('data-employee') || '';
        const ttype = row.getAttribute('data-type') || '';
        const travel = row.getAttribute('data-travel') || '';
        const location = row.getAttribute('data-location') || '';
        const purpose = row.getAttribute('data-purpose') || '';
        const approved = row.getAttribute('data-approved') || '';
        body.innerHTML = `<table style="width:100%;border-collapse:collapse"><tbody>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Employee</strong></td><td style="padding:6px;border:1px solid #eee">${employee}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Type</strong></td><td style="padding:6px;border:1px solid #eee">${ttype}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Travel Date</strong></td><td style="padding:6px;border:1px solid #eee">${travel}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Location</strong></td><td style="padding:6px;border:1px solid #eee">${location}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Purpose</strong></td><td style="padding:6px;border:1px solid #eee">${purpose}</td></tr>
            <tr><td style="padding:6px;border:1px solid #eee"><strong>Approved At</strong></td><td style="padding:6px;border:1px solid #eee">${approved}</td></tr>
        </tbody></table>`;
        document.getElementById('approved-modal-print').onclick = () => window.open(`{{ url('dashboard/employee/locator') }}/${id}/print`, '_blank');
    }
    const dlg = document.getElementById('approvedModal');
    if (dlg && typeof dlg.showModal === 'function') dlg.showModal();
}

function closeApprovedModal() { const dlg = document.getElementById('approvedModal'); if (dlg && typeof dlg.close === 'function') dlg.close(); }
</script>
@endsection
