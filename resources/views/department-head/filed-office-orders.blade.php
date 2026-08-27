@extends('dashboards.layout', [
    'title' => 'Filed Office Orders',
    'subtitle' => 'Archive of previously filed office orders for your department.',
])

@section('page_head')
<style>
    /* Office Order details modal */
    #officeOrderModalOverlay .modal-box { max-width: 760px; padding: 0; overflow: hidden; }
    .ood-header {
        display: flex; justify-content: space-between; align-items: center;
        padding: 1.1rem 1.5rem; background: linear-gradient(90deg, #fff7ed 0%, #fffaf0 100%);
        border-bottom: 1px solid #fdba74;
    }
    .ood-header h3 { margin: 0; font-size: 1.05rem; font-weight: 700; color: #0f172a; }
    .ood-header h3 i { color: var(--accent, #ea580c); margin-right: 0.5rem; }
    .ood-body { padding: 1.25rem 1.5rem 1.5rem; max-height: 65vh; overflow-y: auto; }

    /* KPI tiles double as quick status filters */
    button.kpi-card { text-align: left; width: 100%; font: inherit; }
    .kpi-card.active { border-color: var(--accent, #ea580c); box-shadow: 0 0 0 2px rgba(234, 88, 12, 0.15), 0 24px 48px rgba(2, 6, 23, 0.08); }

    /* Cancelled office order rows */
    .oo-cancelled-text { text-decoration: line-through; color: #94a3b8; }
    .oo-cancellation-block {
        margin-top: 24px; padding: 12px 14px; border: 1px solid #fecaca; background: #fef2f2;
        border-radius: 8px; font-family: -apple-system, Segoe UI, Roboto, sans-serif; font-size: 0.85rem; color: #991b1b;
    }
    .oo-cancellation-block strong { color: #7f1d1d; }
</style>
@endsection

@section('tiles')
    @php
        $ooTiles = [
            ['key' => 'Total', 'label' => 'All Orders', 'icon' => 'fa-archive', 'accent' => 'accent-workforce'],
            ['key' => 'Active', 'label' => 'Active', 'icon' => 'fa-circle-check', 'accent' => 'accent-overtime'],
            ['key' => 'Expired', 'label' => 'Expired', 'icon' => 'fa-circle-xmark', 'accent' => 'accent-eta'],
        ];
    @endphp
    @foreach($ooTiles as $tile)
        <button type="button"
                class="kpi-card {{ $tile['accent'] }} {{ $tile['key'] === 'Total' ? 'active' : '' }}"
                data-status-filter="{{ $tile['key'] }}"
                onclick="filterOfficeOrdersByStatus('{{ $tile['key'] }}')">
            <div class="kpi-head">
                <div class="kpi-icon" aria-hidden="true"><i class="fa-solid {{ $tile['icon'] }}"></i></div>
                <div class="kpi-title">{{ $tile['label'] }}</div>
            </div>
            <div class="kpi-value" id="ooTileCount{{ $tile['key'] }}">0</div>
        </button>
    @endforeach
@endsection

@section('content')
<x-hris.table-layout
    title="Filed Office Orders"
    subtitle="Archive of office orders filed for your department."
    :showSearch="false"
    :showMonthFilter="false"
>
    <x-slot:filters>
        <div class="hris-filter-left" style="align-items:center;">
            <button type="button" class="month-nav" onclick="shiftOfficeOrderMonth(-1)">&laquo; Prev</button>
            <div class="font-weight-bold" id="officeOrderMonthLabel" style="min-width:140px; text-align:center;"></div>
            <button type="button" class="month-nav" onclick="shiftOfficeOrderMonth(1)">Next &raquo;</button>
            <button type="button" class="month-nav" onclick="resetOfficeOrderMonth()">This Month</button>
        </div>
        <div class="hris-filter-right">
            <input type="text" id="officeOrderSearch" class="form-input" style="width:100%"
                   placeholder="Search by OO number, subject, or employee...">
        </div>
    </x-slot:filters>

    <table class="hris-table" id="officeOrdersTable">
        <thead>
            <tr>
                <th>#</th>
                <th>OO Number</th>
                <th>Subject</th>
                <th>Date Issued</th>
                <th>Effective Until</th>
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

<!-- Office Order modal (centered overlay) -->
<div class="modal-overlay" id="officeOrderModalOverlay">
    <div class="modal-box">
        <div class="ood-header">
            <h3><i class="fas fa-file-signature"></i>Office Order Details</h3>
            <button class="modal-close" onclick="closeOfficeOrderModal()" style="float:none;">&times;</button>
        </div>
        <div class="ood-body" id="officeOrderModalBody">Loading...</div>
        <div class="modal-actions" style="display:flex; gap:0.5rem; justify-content:flex-end; padding:0 1.5rem 1.25rem;">
            <button class="hris-btn hris-btn-sm" style="background:#16a34a;color:#fff" onclick="if(_currentOfficeOrderData) window.open('/office-orders/' + _currentOfficeOrderData.id + '/print', '_blank')"><i class="fas fa-print"></i> Print</button>
            <button class="hris-btn hris-btn-sm" style="background:#2563eb;color:#fff" onclick="if(_currentOfficeOrderData) window.location='/office-orders/' + _currentOfficeOrderData.id + '/word'"><i class="fas fa-file-word"></i> Download Word</button>
            <button class="hris-btn hris-btn-secondary hris-btn-sm" onclick="closeOfficeOrderModal()">Close</button>
        </div>
    </div>
</div>
@endsection

@section('page_scripts')
<script>
let _ooAllRows = [];
let _ooStatusFilter = 'Total';
let _ooMonth = new Date().getMonth() + 1;
let _ooYear = new Date().getFullYear();
var _currentOfficeOrderData = null;

const OO_MONTH_NAMES = ['January','February','March','April','May','June','July','August','September','October','November','December'];

document.addEventListener('DOMContentLoaded', function() {
    updateOfficeOrderMonthLabel();

    fetch('{{ route('api.department.office-orders') }}')
        .then(r => r.json())
        .then(res => {
            const tbody = document.querySelector('#officeOrdersTable tbody');
            if (!res.success) {
                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Failed to load data</td></tr>';
                return;
            }
            _ooAllRows = res.data || [];
            updateOfficeOrderTileCounts();
            renderOfficeOrdersTable();
        })
        .catch(() => {
            document.querySelector('#officeOrdersTable tbody').innerHTML =
                '<tr><td colspan="9" class="text-center text-danger">Failed to load data</td></tr>';
        });

    document.getElementById('officeOrderSearch').addEventListener('input', renderOfficeOrdersTable);
});

function todayIso() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function isOfficeOrderActive(row) {
    return !row.effective_date || row.effective_date >= todayIso();
}

function rowMonthYear(dateStr) {
    if (!dateStr) return null;
    const parts = String(dateStr).split(/[- ]/);
    if (parts.length < 3) return null;
    return { year: parseInt(parts[0], 10), month: parseInt(parts[1], 10) };
}

function matchesOfficeOrderMonth(row) {
    const my = rowMonthYear(row.issued_date);
    return !!my && my.year === _ooYear && my.month === _ooMonth;
}

function updateOfficeOrderMonthLabel() {
    document.getElementById('officeOrderMonthLabel').textContent = OO_MONTH_NAMES[_ooMonth - 1] + ' ' + _ooYear;
}

function shiftOfficeOrderMonth(delta) {
    _ooMonth += delta;
    if (_ooMonth < 1) { _ooMonth = 12; _ooYear--; }
    if (_ooMonth > 12) { _ooMonth = 1; _ooYear++; }
    updateOfficeOrderMonthLabel();
    updateOfficeOrderTileCounts();
    renderOfficeOrdersTable();
}

function resetOfficeOrderMonth() {
    const now = new Date();
    _ooMonth = now.getMonth() + 1;
    _ooYear = now.getFullYear();
    updateOfficeOrderMonthLabel();
    updateOfficeOrderTileCounts();
    renderOfficeOrdersTable();
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

function effectiveBadge(row) {
    return isOfficeOrderActive(row)
        ? '<span class="hris-badge badge-approved">Active</span>'
        : '<span class="hris-badge badge-rejected">Expired</span>';
}

function statusBadge(row) {
    if (row.status === 'Cancelled') {
        return '<span class="hris-badge badge-cancelled">Cancelled</span>';
    }
    return effectiveBadge(row);
}

function updateOfficeOrderTileCounts() {
    const counts = { Total: 0, Active: 0, Expired: 0 };
    _ooAllRows.forEach(row => {
        if (!matchesOfficeOrderMonth(row)) return;
        counts.Total++;
        counts[isOfficeOrderActive(row) ? 'Active' : 'Expired']++;
    });
    document.getElementById('ooTileCountTotal').textContent = counts.Total;
    document.getElementById('ooTileCountActive').textContent = counts.Active;
    document.getElementById('ooTileCountExpired').textContent = counts.Expired;
}

function filterOfficeOrdersByStatus(status) {
    _ooStatusFilter = status;
    document.querySelectorAll('.kpi-card[data-status-filter]').forEach(el => {
        el.classList.toggle('active', el.dataset.statusFilter === status);
    });
    renderOfficeOrdersTable();
}

function renderOfficeOrdersTable() {
    const tbody = document.querySelector('#officeOrdersTable tbody');
    const term = (document.getElementById('officeOrderSearch').value || '').trim().toLowerCase();

    const filtered = _ooAllRows.filter(row => {
        if (!matchesOfficeOrderMonth(row)) return false;
        if (_ooStatusFilter !== 'Total') {
            const active = isOfficeOrderActive(row);
            if (_ooStatusFilter === 'Active' && !active) return false;
            if (_ooStatusFilter === 'Expired' && active) return false;
        }
        if (!term) return true;
        if ((row.office_order_num || '').toLowerCase().includes(term)) return true;
        if ((row.subject || '').toLowerCase().includes(term)) return true;
        return (row.employees || []).some(n => String(n).toLowerCase().includes(term));
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9">
            <div class="hris-empty-state">
                <div class="hris-empty-state-icon"><i class="fa fa-inbox"></i></div>
                <div class="hris-empty-state-title">No office orders found</div>
                <div class="hris-empty-state-text">${_ooAllRows.length === 0 ? 'Office orders filed for your department will appear here.' : 'Try a different search or filter.'}</div>
            </div>
        </td></tr>`;
        return;
    }

    tbody.innerHTML = filtered.map((row, idx) => {
        const names = (row.employees || []).map(n => `<div class="td-ellipsis" title="${String(n).replace(/"/g,'&quot;')}">${n}</div>`);
        const show = names.slice(0, 3).join('');
        const moreCount = (row.employees || []).length - 3;
        const more = moreCount > 0 ? `<div class="text-muted" style="font-size:0.85em">+${moreCount} more</div>` : '';
        const isCancelled = row.status === 'Cancelled';
        const textCls = isCancelled ? ' oo-cancelled-text' : '';
        const editBtn = isCancelled ? '' : `<button class="hris-btn hris-btn-warning hris-btn-sm" type="button" onclick="window.location='/office-orders/' + ${row.id} + '/edit'"><i class="fas fa-edit"></i> Edit</button>`;
        const cancelBtn = isCancelled ? '' : `<button class="hris-btn hris-btn-sm" type="button" style="background:#dc2626;color:#fff" onclick="promptCancelOfficeOrder(${row.id})"><i class="fas fa-ban"></i> Cancel</button>`;
        return `
            <tr>
                <td>${idx + 1}</td>
                <td class="td-ellipsis${textCls}">${row.office_order_num || row.id}</td>
                <td class="td-ellipsis${textCls}">${row.subject || '-'}</td>
                <td class="${textCls}">${formatDate(row.issued_date)}</td>
                <td class="${textCls}">${row.effective_date ? formatDate(row.effective_date) : 'No expiry'}</td>
                <td class="employees-cell">${show}${more}</td>
                <td>${statusBadge(row)}</td>
                <td>${formatDate(row.created_at)}</td>
                <td>
                    <div class="action-btns">
                        <button class="hris-btn hris-btn-secondary hris-btn-sm" type="button" onclick="openOfficeOrderModal(${row.id})"><i class="fa fa-eye"></i> View</button>
                        ${editBtn}
                        <button class="hris-btn hris-btn-sm" type="button" style="background:#16a34a;color:#fff" onclick="window.open('/office-orders/' + ${row.id} + '/print', '_blank')"><i class="fas fa-print"></i> Print</button>
                        ${cancelBtn}
                    </div>
                </td>
            </tr>`;
    }).join('');
}

async function openOfficeOrderModal(id) {
    const overlay = document.getElementById('officeOrderModalOverlay');
    const body = document.getElementById('officeOrderModalBody');
    overlay.classList.add('active');
    body.innerHTML = '<div class="muted">Loading...</div>';
    try {
        const resp = await fetch(`/api/office-orders/${id}`);
        const j = await resp.json();
        if (!j.success) { body.innerHTML = '<div class="text-danger">Failed to load details</div>'; return; }
        const d = j.data;
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        const fmtDate = (s) => {
            if (!s) return '-';
            const dt = new Date(s + 'T00:00:00');
            if (isNaN(dt)) return esc(s);
            return dt.toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' }).toUpperCase();
        };
        const party = (p) => p ? `<span style="font-weight:bold;text-transform:uppercase">${esc(p.name)}</span>${p.designation ? `<br><span style="font-style:italic">${esc(p.designation)}</span>` : ''}` : '-';
        const toBlock = (d.employees && d.employees.length)
            ? d.employees.map(e => `<div style="margin-bottom:8px">${party(e)}</div>`).join('')
            : '-';
        const conformed = (d.employees && d.employees.length)
            ? d.employees.map(e => `<div style="font-weight:bold;text-transform:uppercase;margin-bottom:16px">${esc(e.name)}</div>`).join('')
            : '<div style="font-weight:bold">_______________________</div>';
        const row = (label, value) => `<div style="display:flex;margin-bottom:12px"><div style="width:78px;flex:0 0 78px;font-weight:bold">${label}</div><div style="flex:1">${value}</div></div>`;
        body.innerHTML = `
            <div style="font-family:'Times New Roman',serif;font-size:12pt;line-height:1.5;color:#000;padding:8px 4px">
                <div style="font-weight:bold;font-size:13pt;margin-bottom:22px">Office Order No. <span style="text-decoration:underline">${esc(d.office_order_num || d.id)}</span></div>
                ${row('To', toBlock)}
                ${row('From', party(d.issued_by))}
                ${row('Subject', `<strong>${esc(d.subject || '-')}</strong>`)}
                ${row('Date', `<strong>${fmtDate(d.issued_date)}</strong>`)}
                <hr style="border:none;border-top:2px solid #000;margin:16px 0 20px">
                <div style="text-align:justify;white-space:pre-line;margin-bottom:22px">${esc(d.details || '')}</div>
                <div style="margin-bottom:40px">For information and strict compliance.</div>
                <div style="margin-bottom:28px">Conformed:</div>
                ${conformed}
                ${d.remarks ? `<div style="margin-top:24px;padding-top:10px;border-top:1px dashed #cbd5e1;font-size:0.85em;color:#64748b"><strong>Internal remarks:</strong> ${esc(d.remarks)}</div>` : ''}
                ${d.status === 'Cancelled' ? `<div class="oo-cancellation-block"><strong>Cancelled${d.cancelled_by_name ? ' by ' + esc(d.cancelled_by_name) : ''}${d.cancelled_at ? ' on ' + fmtDate(String(d.cancelled_at).slice(0, 10)) : ''}</strong><br>Reason: ${esc(d.cancellation_reason || '-')}</div>` : ''}
            </div>`;
        _currentOfficeOrderData = d;
    } catch (err) {
        body.innerHTML = '<div class="text-danger">Failed to load details</div>';
    }
}

function closeOfficeOrderModal() {
    document.getElementById('officeOrderModalOverlay').classList.remove('active');
}

async function ensureSwal() {
    if (window.Swal && typeof Swal.fire === 'function') return window.Swal;
    return new Promise((resolve) => {
        const s = document.createElement('script');
        s.src = '/js/app.js';
        s.onload = () => resolve(window.Swal || null);
        s.onerror = () => resolve(null);
        document.head.appendChild(s);
    });
}

async function promptCancelOfficeOrder(id) {
    function doCancel(reason) {
        const token = document.querySelector('meta[name="csrf-token"]').content;
        fetch('/api/office-orders/' + id + '/cancel', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: new URLSearchParams({ reason: reason })
        }).then(async response => {
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Failed to cancel office order.');
            }
            const row = _ooAllRows.find(r => r.id === id);
            if (row) row.status = 'Cancelled';
            updateOfficeOrderTileCounts();
            renderOfficeOrdersTable();
            if (window.Swal) {
                Swal.fire('Cancelled', data.message || 'Office order cancelled.', 'success');
            }
        }).catch(error => {
            if (window.Swal) Swal.fire('Error', error.message || 'Failed to cancel office order.', 'error');
            else alert(error.message || 'Failed to cancel office order.');
        });
    }

    const SwalLib = await ensureSwal();
    if (SwalLib) {
        SwalLib.fire({
            icon: 'warning',
            title: 'Cancel Office Order',
            input: 'textarea',
            inputLabel: 'Reason for cancelling this office order',
            showCancelButton: true,
            confirmButtonText: 'Submit',
            confirmButtonColor: '#dc2626',
            preConfirm: (v) => { if (!v) SwalLib.showValidationMessage('A reason is required'); return v; }
        }).then((result) => {
            if (result.isConfirmed) doCancel(result.value);
        });
    } else {
        const reason = prompt('Reason for cancelling this office order:');
        if (reason) doCancel(reason);
    }
}
</script>
@endsection
