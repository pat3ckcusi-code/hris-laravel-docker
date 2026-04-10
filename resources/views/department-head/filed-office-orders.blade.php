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
            <table class="leave-table" id="officeOrdersTable">
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
                                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Failed to load data</td></tr>';
                                return;
                        }

                        const rows = res.data;
                        if (!rows || rows.length === 0) {
                                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No office orders found.</td></tr>';
                                return;
                        }
                        const badgeFor = (status) => {
                                if (!status) return '<span class="status-badge status-draft">Unknown</span>';
                                const s = status.toLowerCase();
                                if (s.includes('draft')) return '<span class="status-badge status-draft">Draft</span>';
                                if (s.includes('pending recommendation')) return '<span class="status-badge status-pending-rec">Pending Recommendation</span>';
                                if (s.includes('pending approval')) return '<span class="status-badge status-pending-approval">Pending Approval</span>';
                                if (s.includes('approved')) return '<span class="status-badge status-approved">Approved</span>';
                                return '<span class="status-badge">' + status + '</span>';
                        };

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
                                            <td>${badgeFor(row.status)}</td>
                                            <td>${row.created_at}</td>
                                            <td>
                                                <button class="btn-sm btn-view" type="button" onclick="openOfficeOrderModal(${row.id})">View</button>
                                            </td>
                                        </tr>`;
                        }).join('');
                })
                .catch(() => {
                        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Failed to load data</td></tr>';
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
                const emps = (d.employees || []).map(e => `<div style="padding:8px 0;border-bottom:1px solid #f1f5f9">${e.name}${e.designation ? ' — <span class="muted">' + e.designation + '</span>' : ''}</div>`).join('');
                body.innerHTML = `
                    <div style="display:grid; grid-template-columns: 1fr 340px; gap:18px;">
                        <div>
                            <table style="width:100%; border-collapse:collapse">
                                <tbody>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9; width:140px"><strong>OO Number</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.office_order_num || d.id}</td></tr>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Subject</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.subject || '-'}</td></tr>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Date Issued</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.issued_date || '-'}</td></tr>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Effective Until</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.effective_date || '-'}</td></tr>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Details</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.details || '-'}</td></tr>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Remarks</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.remarks || '-'}</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div>
                            <h4 style="margin-top:0; margin-bottom:8px">Employees</h4>
                            <div style="border:1px solid #f1f5f9; border-radius:6px; padding:8px">${emps || '<div class="muted">No employees listed</div>'}</div>
                        </div>
                    </div>`;
        } catch (err) {
                body.innerHTML = '<div class="text-danger">Failed to load details</div>';
        }
}
function closeOfficeOrderModal(){ document.getElementById('officeOrderModalOverlay').style.display='none'; }
</script>

@endsection
