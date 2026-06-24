@extends('dashboards.layout', [
    'title' => 'Filed Office Orders',
    'subtitle' => 'Previously filed office orders',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('tiles')
    <article class="tile">
        <strong>Filed Office Orders</strong>
        Archive of filed office orders.
    </article>
@endsection

@section('content')
<div style="display:flex; justify-content:center;">
    <div class="card" style="max-width:1100px; width:100%">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-list-alt"></i> Filed Office Orders</h3></div>
        <div class="card-body">
        <div class="table-responsive">
            <table class="hris-table" id="officeOrdersTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>OO Number</th>
                        <th>Subject</th>
                        <th>Date Issued</th>
                        <th>Effective Until</th>
                        <th>Employees</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="8" class="text-center text-muted">Loading...</td></tr>
                </tbody>
            </table>
                </div>
        </div>
    </div>
</div>

@endsection

@section('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
        const table = document.getElementById('officeOrdersTable');
        const tbody = table.querySelector('tbody');

        fetch('{{ route('api.department.office-orders') }}')
                .then(r => r.json())
                .then(res => {
                        if (!res.success) {
                                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Failed to load data</td></tr>';
                                return;
                        }

                        const rows = res.data;
                        if (!rows || rows.length === 0) {
                                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">No office orders found.</td></tr>';
                                return;
                        }
                        tbody.innerHTML = rows.map((row, idx) => {
                                const names = (row.employees || []).map(n => `<div class="td-ellipsis" title="${n.replace(/"/g,'&quot;')}">${n}</div>`);
                                const show = names.slice(0,3).join('');
                                const moreCount = (row.employees || []).length - 3;
                                const more = moreCount > 0 ? `<div class="text-muted" style="font-size:0.85em">+${moreCount} more</div>` : '';
                                return `
                                        <tr>
                                            <td>${idx + 1}</td>
                                            <td class="td-ellipsis">${row.office_order_num || row.id}</td>
                                            <td class="td-ellipsis">${row.subject || '-'}</td>
                                            <td>${row.issued_date || '-'}</td>
                                            <td>${row.effective_date || '-'}</td>
                                            <td class="employees-cell">${show}${more}</td>
                                            <td>${row.created_at}</td>
                                            <td>
                                                <button class="btn-sm btn-view" type="button" onclick="openOfficeOrderModal(${row.id})">View</button>
                                                <button class="btn-sm btn-view" type="button" style="background:#f59e0b" onclick="window.location='/office-orders/' + ${row.id} + '/edit'"><i class="fas fa-edit"></i> Edit</button>
                                                <button class="btn-sm btn-view" type="button" style="background:#16a34a" onclick="window.open('/office-orders/' + ${row.id} + '/print', '_blank')"><i class="fas fa-print"></i> Print</button>
                                            </td>
                                        </tr>`;
                        }).join('');
                })
                .catch(() => {
                        tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">Failed to load data</td></tr>';
                });
});
</script>
<!-- Office Order modal (centered overlay) -->
<div class="modal-overlay" id="officeOrderModalOverlay">
    <div class="modal-box" style="max-width:900px">
        <button class="modal-close-btn" onclick="closeOfficeOrderModal()">&times;</button>
        <h3>Office Order Details</h3>
        <div class="muted" style="font-size:0.9rem;margin-bottom:12px">View details for this office order</div>
        <div id="officeOrderModalBody"></div>
        <div class="modal-actions">
            <button class="btn-sm btn-view" style="background:#16a34a" onclick="if(_currentOfficeOrderData) window.open('/office-orders/' + _currentOfficeOrderData.id + '/print', '_blank')"><i class="fas fa-print"></i> Print</button>
            <button class="btn-sm btn-view" style="background:#2563eb" onclick="if(_currentOfficeOrderData) window.location='/office-orders/' + _currentOfficeOrderData.id + '/word'"><i class="fas fa-file-word"></i> Download Word</button>
            <button class="btn-sm btn-view" onclick="closeOfficeOrderModal()">Close</button>
        </div>
    </div>
</div>
<script>
async function openOfficeOrderModal(id) {
        const overlay = document.getElementById('officeOrderModalOverlay');
        const body = document.getElementById('officeOrderModalBody');
        overlay.style.display = 'flex';
        body.innerHTML = '<div class="muted">Loading...</div>';
        try {
                const resp = await fetch(`/api/office-orders/${id}`);
                const j = await resp.json();
                if (!j.success) { body.innerHTML = '<div class="text-danger">Failed to load details</div>'; return; }
                const d = j.data;
                const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
                const fmtDate = (s) => {
                    if (!s) return '—';
                    const dt = new Date(s + 'T00:00:00');
                    if (isNaN(dt)) return esc(s);
                    return dt.toLocaleDateString('en-US', { month: 'long', day: '2-digit', year: 'numeric' }).toUpperCase();
                };
                const party = (p) => p ? `<span style="font-weight:bold;text-transform:uppercase">${esc(p.name)}</span>${p.designation ? `<br><span style="font-style:italic">${esc(p.designation)}</span>` : ''}` : '—';
                const toBlock = (d.employees && d.employees.length)
                    ? d.employees.map(e => `<div style="margin-bottom:8px">${party(e)}</div>`).join('')
                    : '—';
                const conformed = (d.employees && d.employees.length)
                    ? d.employees.map(e => `<div style="font-weight:bold;text-transform:uppercase;margin-bottom:16px">${esc(e.name)}</div>`).join('')
                    : '<div style="font-weight:bold">_______________________</div>';
                const row = (label, value) => `<div style="display:flex;margin-bottom:12px"><div style="width:78px;flex:0 0 78px;font-weight:bold">${label}</div><div style="flex:1">${value}</div></div>`;
                body.innerHTML = `
                    <div style="font-family:'Times New Roman',serif;font-size:12pt;line-height:1.5;color:#000;padding:8px 4px">
                        <div style="font-weight:bold;font-size:13pt;margin-bottom:22px">Office Order No. <span style="text-decoration:underline">${esc(d.office_order_num || d.id)}</span></div>
                        ${row('To', toBlock)}
                        ${row('From', party(d.issued_by))}
                        ${row('Subject', `<strong>${esc(d.subject || '—')}</strong>`)}
                        ${row('Date', `<strong>${fmtDate(d.issued_date)}</strong>`)}
                        <hr style="border:none;border-top:2px solid #000;margin:16px 0 20px">
                        <div style="text-align:justify;white-space:pre-line;margin-bottom:22px">${esc(d.details || '')}</div>
                        <div style="margin-bottom:40px">For information and strict compliance.</div>
                        <div style="margin-bottom:28px">Conformed:</div>
                        ${conformed}
                        ${d.remarks ? `<div style="margin-top:24px;padding-top:10px;border-top:1px dashed #cbd5e1;font-size:0.85em;color:#64748b"><strong>Internal remarks:</strong> ${esc(d.remarks)}</div>` : ''}
                    </div>`;
                _currentOfficeOrderData = d;
        } catch (err) {
                body.innerHTML = '<div class="text-danger">Failed to load details</div>';
        }
}
function closeOfficeOrderModal(){ document.getElementById('officeOrderModalOverlay').style.display='none'; }

var _currentOfficeOrderData = null;
</script>

@endsection
