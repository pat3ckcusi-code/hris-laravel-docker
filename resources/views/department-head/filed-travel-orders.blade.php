@extends('dashboards.layout', [
    'title' => 'Filed Travel Orders',
    'subtitle' => 'Archive of previously filed travel orders for your department.',
])

@section('page_head')
<style>
    /* Travel Order details modal — mirrors the styling used on the Mayor's
       Travel Order Approvals page for a consistent look across the app. */
    #travelOrderModalOverlay .modal-box { max-width: 720px; padding: 0; overflow: hidden; }
    .tod-header {
        display: flex; justify-content: space-between; align-items: center;
        padding: 1.1rem 1.5rem; background: linear-gradient(90deg, #fff7ed 0%, #fffaf0 100%);
        border-bottom: 1px solid #fdba74;
    }
    .tod-header h3 { margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; }
    .tod-header h3 i { color: var(--accent, #ea580c); margin-right: 0.5rem; }
    .tod-body { padding: 1.25rem 1.5rem 1.5rem; max-height: 65vh; overflow-y: auto; }
    .tod-trip { display: flex; gap: 14px; align-items: center; margin-bottom: 1.1rem; }
    .tod-trip-icon {
        flex: 0 0 auto; width: 48px; height: 48px; border-radius: 50%;
        background: #fff7ed; border: 2px solid #fed7aa; color: #9a3412;
        display: flex; align-items: center; justify-content: center; font-size: 1.15rem;
    }
    .tod-trip-title { font-size: 1.05rem; font-weight: 700; color: var(--ink); }
    .tod-trip-sub { font-size: 0.82rem; color: var(--muted); margin-top: 2px; }
    .tod-banner {
        display: flex; align-items: center; gap: 8px; padding: 0.6rem 0.9rem;
        border-radius: 0.5rem; font-weight: 600; font-size: 0.875rem; margin-bottom: 1.1rem;
    }
    .tod-banner-pending { background: #fef3c7; color: #92400e; }
    .tod-banner-approved { background: #dcfce7; color: #166534; }
    .tod-banner-rejected { background: #fee2e2; color: #991b1b; }
    .tod-banner-default { background: #f3f4f6; color: #4b5563; }
    .tod-section { margin-bottom: 1.1rem; }
    .tod-section:last-child { margin-bottom: 0; }
    .tod-section-title {
        font-size: 0.72rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 0.5rem;
    }
    .tod-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
    .tod-item-wide { grid-column: 1 / -1; }
    .tod-item .tod-label { font-size: 0.75rem; color: #64748b; margin-bottom: 0.15rem; }
    .tod-item .tod-value { font-size: 0.9rem; color: #0f172a; font-weight: 500; word-break: break-word; }
    .tod-stat {
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 0.5rem;
        padding: 0.6rem 0.5rem; text-align: center;
    }
    .tod-stat .tod-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }
    .tod-stat .tod-value { font-size: 1.05rem; font-weight: 700; color: #0f172a; margin-top: 2px; }

    /* Employees table inside the modal */
    .emp-list-wrapper {
        max-height: 260px;
        overflow-y: auto;
        border: 1px solid #e2e8f0;
        border-radius: 0.5rem;
        margin-top: 0.375rem;
    }
    .emp-list-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 0.8125rem; }
    .emp-list-table th,
    .emp-list-table td {
        border: 1px solid #e2e8f0;
        padding: 0.5rem 0.625rem;
        text-align: left;
        vertical-align: top;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
    .emp-list-table th {
        background: #f8fafc;
        font-weight: 600;
        position: sticky;
        top: 0;
        z-index: 1;
    }
    .emp-list-table th:first-child, .emp-list-table td:first-child { width: 36px; text-align: center; }
    .emp-list-table th:nth-child(2), .emp-list-table td:nth-child(2) { width: 92px; }

    /* KPI tiles double as quick status filters */
    button.kpi-card { text-align: left; width: 100%; font: inherit; }
    .kpi-card.active { border-color: var(--accent, #ea580c); box-shadow: 0 0 0 2px rgba(234, 88, 12, 0.15), 0 24px 48px rgba(2, 6, 23, 0.08); }
</style>
@endsection

@section('tiles')
    @php
        $toTiles = [
            ['key' => 'Pending', 'label' => 'Pending', 'icon' => 'fa-hourglass-half', 'accent' => 'accent-leave'],
            ['key' => 'Approved', 'label' => 'Approved', 'icon' => 'fa-circle-check', 'accent' => 'accent-overtime'],
            ['key' => 'Rejected', 'label' => 'Rejected', 'icon' => 'fa-circle-xmark', 'accent' => 'accent-eta'],
            ['key' => 'All', 'label' => 'All Orders', 'icon' => 'fa-layer-group', 'accent' => 'accent-workforce'],
        ];
    @endphp
    @foreach($toTiles as $tile)
        <button type="button"
                class="kpi-card {{ $tile['accent'] }} {{ $tile['key'] === 'All' ? 'active' : '' }}"
                data-status-filter="{{ $tile['key'] }}"
                onclick="filterTravelOrdersByStatus('{{ $tile['key'] }}')">
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid {{ $tile['icon'] }}"></i></div>
                <div class="kpi-title">{{ $tile['label'] }}</div>
            </div>
            <div class="kpi-value" id="toTileCount{{ $tile['key'] }}">0</div>
        </button>
    @endforeach
@endsection

@section('content')
<x-hris.table-layout
    title="Filed Travel Orders"
    subtitle="Archive of travel orders filed for your department."
    :showSearch="false"
    :showMonthFilter="false"
>
    <x-slot:filters>
        <div class="hris-filter-left" style="align-items:center;">
            <button type="button" class="month-nav" onclick="shiftTravelOrderMonth(-1)">&laquo; Prev</button>
            <div class="font-weight-bold" id="travelOrderMonthLabel" style="min-width:140px; text-align:center;"></div>
            <button type="button" class="month-nav" onclick="shiftTravelOrderMonth(1)">Next &raquo;</button>
            <button type="button" class="month-nav" onclick="resetTravelOrderMonth()">This Month</button>
        </div>
        <div class="hris-filter-right">
            <input type="text" id="travelOrderSearch" class="form-input" style="width:100%"
                   placeholder="Search by TO number, destination, or employee...">
        </div>
    </x-slot:filters>

    <table class="hris-table" id="travelOrdersTable">
        <thead>
            <tr>
                <th>#</th>
                <th>TO Number</th>
                <th>Destination</th>
                <th>Departure</th>
                <th>Return</th>
                <th>Employees</th>
                <th>Status</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr><td colspan="9" class="text-center text-muted">Loading...</td></tr>
        </tbody>
    </table>
</x-hris.table-layout>

<!-- Travel Order modal (centered overlay) -->
<div class="modal-overlay" id="travelOrderModalOverlay">
    <div class="modal-box">
        <div class="tod-header">
            <h3><i class="fa fa-route"></i>Travel Order Details</h3>
            <button class="modal-close" onclick="closeTravelOrderModal()" style="float:none;">&times;</button>
        </div>
        <div class="tod-body" id="travelOrderModalBody">Loading...</div>
        <div class="modal-actions" style="display:flex; gap:0.5rem; justify-content:flex-end; padding:0 1.5rem 1.25rem;">
            <button class="hris-btn hris-btn-sm" style="background:#16a34a;color:#fff" onclick="printTravelOrderFromModal()"><i class="fas fa-print"></i> Print</button>
            <button class="hris-btn hris-btn-warning hris-btn-sm" id="travelOrderModalEditBtn" style="display:none" onclick="editTravelOrderFromModal()"><i class="fas fa-edit"></i> Edit</button>
            <button class="hris-btn hris-btn-secondary hris-btn-sm" onclick="closeTravelOrderModal()">Close</button>
        </div>
    </div>
</div>
@endsection

@section('page_scripts')
<script>
let _toAllRows = [];
let _toStatusFilter = 'All';
let _toMonth = new Date().getMonth() + 1;
let _toYear = new Date().getFullYear();
var _currentTravelOrderData = null;

const TO_MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];

document.addEventListener('DOMContentLoaded', function() {
    updateTravelOrderMonthLabel();

    fetch('{{ route('api.department.travel-orders') }}')
        .then(r => r.json())
        .then(res => {
            const tbody = document.querySelector('#travelOrdersTable tbody');
            if (!res.success) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Failed to load data</td></tr>';
                return;
            }
            _toAllRows = res.data || [];
            updateTravelOrderTileCounts();
            renderTravelOrdersTable();
        })
        .catch(() => {
            document.querySelector('#travelOrdersTable tbody').innerHTML =
                '<tr><td colspan="9" class="text-center text-danger">Failed to load data</td></tr>';
        });

    document.getElementById('travelOrderSearch').addEventListener('input', renderTravelOrdersTable);
});

function rowMonthYear(dateStr) {
    if (!dateStr) return null;
    const parts = String(dateStr).split(/[- ]/);
    if (parts.length < 3) return null;
    return { year: parseInt(parts[0], 10), month: parseInt(parts[1], 10) };
}

function matchesTravelOrderMonth(row) {
    const my = rowMonthYear(row.departure);
    return !!my && my.year === _toYear && my.month === _toMonth;
}

function updateTravelOrderMonthLabel() {
    document.getElementById('travelOrderMonthLabel').textContent = TO_MONTH_NAMES[_toMonth - 1] + ' ' + _toYear;
}

function shiftTravelOrderMonth(delta) {
    _toMonth += delta;
    if (_toMonth < 1) { _toMonth = 12; _toYear--; }
    if (_toMonth > 12) { _toMonth = 1; _toYear++; }
    updateTravelOrderMonthLabel();
    updateTravelOrderTileCounts();
    renderTravelOrdersTable();
}

function resetTravelOrderMonth() {
    const now = new Date();
    _toMonth = now.getMonth() + 1;
    _toYear = now.getFullYear();
    updateTravelOrderMonthLabel();
    updateTravelOrderTileCounts();
    renderTravelOrdersTable();
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(String(dateStr).replace(' ', 'T'));
    if (isNaN(d.getTime())) return dateStr;
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
}

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function badgeFor(status) {
    const s = (status || '').toLowerCase();
    const cls = { pending: 'badge-pending', approved: 'badge-approved', rejected: 'badge-rejected' }[s] || 'badge-default';
    const label = status ? (status.charAt(0).toUpperCase() + status.slice(1)) : 'Unknown';
    return `<span class="hris-badge ${cls}">${label}</span>`;
}

function updateTravelOrderTileCounts() {
    const counts = { Pending: 0, Approved: 0, Rejected: 0, All: 0 };
    const statusKeys = ['Pending', 'Approved', 'Rejected'];
    _toAllRows.forEach(row => {
        if (!matchesTravelOrderMonth(row)) return;
        counts.All++;
        const key = statusKeys.find(k => k.toLowerCase() === (row.status || '').toLowerCase());
        if (key) counts[key]++;
    });
    document.getElementById('toTileCountPending').textContent = counts.Pending;
    document.getElementById('toTileCountApproved').textContent = counts.Approved;
    document.getElementById('toTileCountRejected').textContent = counts.Rejected;
    document.getElementById('toTileCountAll').textContent = counts.All;
}

function filterTravelOrdersByStatus(status) {
    _toStatusFilter = status;
    document.querySelectorAll('.kpi-card[data-status-filter]').forEach(el => {
        el.classList.toggle('active', el.dataset.statusFilter === status);
    });
    renderTravelOrdersTable();
}

function renderTravelOrdersTable() {
    const tbody = document.querySelector('#travelOrdersTable tbody');
    const term = (document.getElementById('travelOrderSearch').value || '').trim().toLowerCase();

    const filtered = _toAllRows.filter(row => {
        if (!matchesTravelOrderMonth(row)) return false;
        const matchesStatus = _toStatusFilter === 'All' || (row.status || '').toLowerCase() === _toStatusFilter.toLowerCase();
        if (!matchesStatus) return false;
        if (!term) return true;
        if ((row.travel_order_num || '').toLowerCase().includes(term)) return true;
        if ((row.destination || '').toLowerCase().includes(term)) return true;
        return (row.employees || []).some(n => String(n).toLowerCase().includes(term));
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9">
            <div class="hris-empty-state">
                <div class="hris-empty-state-icon"><i class="fa fa-inbox"></i></div>
                <div class="hris-empty-state-title">No travel orders found</div>
                <div class="hris-empty-state-text">${_toAllRows.length === 0 ? 'Travel orders filed for your department will appear here.' : 'Try a different search or filter.'}</div>
            </div>
        </td></tr>`;
        return;
    }

    tbody.innerHTML = filtered.map((row, idx) => {
        const names = (row.employees || []).map(n => `<div class="td-ellipsis" title="${String(n).replace(/"/g,'&quot;')}">${n}</div>`);
        const show = names.slice(0, 3).join('');
        const moreCount = (row.employees || []).length - 3;
        const more = moreCount > 0 ? `<div class="text-muted" style="font-size:0.85em">+${moreCount} more</div>` : '';
        const isPending = (row.status || '').toLowerCase() === 'pending';
        const editBtn = isPending
            ? `<button class="hris-btn hris-btn-warning hris-btn-sm" type="button" onclick="window.location='/travel-orders/' + ${row.id} + '/edit'"><i class="fas fa-edit"></i> Edit</button>`
            : '';
        return `
            <tr>
                <td>${idx + 1}</td>
                <td class="td-ellipsis">${row.travel_order_num}</td>
                <td class="td-ellipsis">${row.destination}</td>
                <td>${formatDate(row.departure)}</td>
                <td>${formatDate(row.return)}</td>
                <td class="employees-cell">${show}${more}</td>
                <td>${badgeFor(row.status)}</td>
                <td>${formatDate(row.created_at)}</td>
                <td>
                    <div class="action-btns">
                        <button class="hris-btn hris-btn-secondary hris-btn-sm" type="button" onclick="openTravelOrderModal(${row.id})"><i class="fa fa-eye"></i> View</button>
                        <button class="hris-btn hris-btn-sm" type="button" style="background:#16a34a;color:#fff" onclick="printTravelOrder(${row.id})"><i class="fas fa-print"></i> Print</button>
                        ${editBtn}
                    </div>
                </td>
            </tr>`;
    }).join('');
}

function todItem(label, value, wide) {
    return `<div class="tod-item${wide ? ' tod-item-wide' : ''}">
        <div class="tod-label">${escapeHtml(label)}</div>
        <div class="tod-value">${escapeHtml(value) || '-'}</div>
    </div>`;
}

function todStat(label, value) {
    return `<div class="tod-stat">
        <div class="tod-label">${escapeHtml(label)}</div>
        <div class="tod-value">${formatDate(value)}</div>
    </div>`;
}

async function openTravelOrderModal(id) {
    const overlay = document.getElementById('travelOrderModalOverlay');
    const body = document.getElementById('travelOrderModalBody');
    overlay.classList.add('active');
    body.innerHTML = '<div class="muted">Loading...</div>';
    try {
        const resp = await fetch(`/api/travel-orders/${id}`);
        const j = await resp.json();
        if (!j.success) { body.innerHTML = '<div class="text-danger">Failed to load details</div>'; return; }
        const d = j.data;

        const statusMeta = {
            pending: { icon: 'fa-hourglass-half', label: 'Pending Approval', cls: 'tod-banner-pending' },
            approved: { icon: 'fa-circle-check', label: 'Approved', cls: 'tod-banner-approved' },
            rejected: { icon: 'fa-circle-xmark', label: 'Rejected', cls: 'tod-banner-rejected' },
        };
        const meta = statusMeta[(d.status || '').toLowerCase()] || { icon: 'fa-circle-info', label: (d.status || 'Unknown'), cls: 'tod-banner-default' };

        const emps = d.employees || [];
        const empList = emps.length > 0
            ? '<div class="emp-list-wrapper"><table class="emp-list-table"><thead><tr><th>#</th><th>Emp No</th><th>Name</th><th>Designation</th></tr></thead><tbody>'
                + emps.map((e, i) => `<tr><td>${i + 1}</td><td>${escapeHtml(e.EmpNo)}</td><td>${escapeHtml(e.name)}</td><td>${escapeHtml(e.designation)}</td></tr>`).join('')
                + '</tbody></table></div>'
            : '<p style="color:#6b7280;font-size:13px;">No employees listed.</p>';

        body.innerHTML = `
            <div class="tod-trip">
                <div class="tod-trip-icon"><i class="fa fa-route"></i></div>
                <div>
                    <div class="tod-trip-title">${escapeHtml(d.travel_order_num)}</div>
                    <div class="tod-trip-sub">${escapeHtml(d.destination)}</div>
                </div>
            </div>
            <div class="tod-banner ${meta.cls}"><i class="fa ${meta.icon}"></i> ${escapeHtml(meta.label)}</div>
            <div class="tod-section">
                <div class="tod-section-title">Schedule</div>
                <div class="tod-grid">
                    ${todStat('Departure', d.departure)}
                    ${todStat('Return', d.return)}
                </div>
            </div>
            <div class="tod-section">
                <div class="tod-section-title">Trip Details</div>
                <div class="tod-grid">
                    ${todItem('Purpose', d.purpose, true)}
                    ${todItem('Report To', d.report_to)}
                    ${todItem('Date of Last Travel', d.date_of_last_travel)}
                    ${todItem('Per Diem / Expenses', d.per_diem)}
                    ${todItem('Appropriation', d.appropriation)}
                    ${todItem('Remarks', d.remarks, true)}
                </div>
            </div>
            <div class="tod-section">
                <div class="tod-section-title">Approval Chain</div>
                <div class="tod-grid">
                    ${todItem('Created By', d.created_by)}
                    ${todItem('Recommender', d.recommender)}
                </div>
            </div>
            <div class="tod-section">
                <div class="tod-section-title">Employees (${emps.length})</div>
                ${empList}
            </div>`;

        _currentTravelOrderData = d;
        document.getElementById('travelOrderModalEditBtn').style.display =
            (d.status || '').toLowerCase() === 'pending' ? 'inline-flex' : 'none';
    } catch (err) {
        body.innerHTML = '<div class="text-danger">Failed to load details</div>';
    }
}

function closeTravelOrderModal() {
    document.getElementById('travelOrderModalOverlay').classList.remove('active');
}

function printTravelOrder(id) {
    window.open(`/api/travel-orders/${id}/print`, '_blank');
}

function printTravelOrderFromModal() {
    if (_currentTravelOrderData) printTravelOrder(_currentTravelOrderData.id);
}

function editTravelOrderFromModal() {
    if (_currentTravelOrderData) window.location = '/travel-orders/' + _currentTravelOrderData.id + '/edit';
}
</script>
@endsection
