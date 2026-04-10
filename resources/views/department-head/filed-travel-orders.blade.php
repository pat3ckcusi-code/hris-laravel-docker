@extends('dashboards.layout', [
    'title' => 'Filed Travel Orders',
    'subtitle' => 'Previously filed travel orders',
])

@section('page_head')
    @include('partials.table-styles')
@endsection

@section('tiles')
    <article class="tile">
        <strong>Filed Travel Orders</strong>
        Archive of filed travel orders.
    </article>
@endsection

@section('content')
<div style="display:flex; justify-content:center;">
    <div class="card" style="max-width:1100px; width:100%">
        <div class="card-header"><h3 class="card-title"><i class="fas fa-list-alt"></i> Filed Travel Orders</h3></div>
        <div class="card-body">
        <div class="table-responsive">
            <table class="leave-table" id="travelOrdersTable">
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
                </div>
        </div>
    </div>
</div>

@endsection

@section('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
        const table = document.getElementById('travelOrdersTable');
        const tbody = table.querySelector('tbody');

        fetch('{{ route('api.department.travel-orders') }}')
                .then(r => r.json())
                .then(res => {
                        if (!res.success) {
                                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Failed to load data</td></tr>';
                                return;
                        }

                        const rows = res.data;
                        if (!rows || rows.length === 0) {
                                tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No travel orders found.</td></tr>';
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
                                const names = (row.employees || []).map(n => `<div class=\"td-ellipsis\" title=\"${n.replace(/"/g,'&quot;')}\">${n}</div>`);
                                const show = names.slice(0,3).join('');
                                const moreCount = (row.employees || []).length - 3;
                                const more = moreCount > 0 ? `<div class="text-muted" style="font-size:0.85em">+${moreCount} more</div>` : '';
                                return `
                                        <tr>
                                            <td>${idx + 1}</td>
                                            <td class="td-ellipsis">${row.travel_order_num}</td>
                                            <td class="td-ellipsis">${row.destination}</td>
                                            <td>${row.departure || '-'}</td>
                                            <td>${row.return || '-'}</td>
                                            <td class="employees-cell">${show}${more}</td>
                                            <td>${badgeFor(row.status)}</td>
                                            <td>${row.created_at}</td>
                                            <td>
                                                <button class="btn-sm btn-view" type="button" onclick="openTravelOrderModal(${row.id})">View</button>
                                            </td>
                                        </tr>`;
                        }).join('');
                })
                .catch(() => {
                        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-danger">Failed to load data</td></tr>';
                });
});
</script>
<!-- Travel Order modal (centered overlay) -->
<div class="modal-overlay" id="travelOrderModalOverlay">
    <div class="modal-box" style="max-width:900px">
        <button class="modal-close-btn" onclick="closeTravelOrderModal()">&times;</button>
        <h3>Travel Order Details</h3>
        <div class="muted" style="font-size:0.9rem;margin-bottom:12px">View details for this travel order</div>
        <div id="travelOrderModalBody"></div>
        <div class="modal-actions">
            <button class="btn-sm btn-view" onclick="closeTravelOrderModal()">Close</button>
        </div>
    </div>
</div>
<script>
async function openTravelOrderModal(id) {
        const overlay = document.getElementById('travelOrderModalOverlay');
        const body = document.getElementById('travelOrderModalBody');
        overlay.style.display = 'flex';
        body.innerHTML = '<div class="muted">Loading...</div>';
        try {
                const resp = await fetch(`/api/travel-orders/${id}`);
                const j = await resp.json();
                if (!j.success) { body.innerHTML = '<div class="text-danger">Failed to load details</div>'; return; }
                const d = j.data;
                const emps = (d.employees || []).map(e => `<div style="padding:8px 0;border-bottom:1px solid #f1f5f9">${e.name}${e.designation ? ' — <span class="muted">' + e.designation + '</span>' : ''}</div>`).join('');
                body.innerHTML = `
                    <div style="display:grid; grid-template-columns: 1fr 340px; gap:18px;">
                        <div>
                            <table style="width:100%; border-collapse:collapse">
                                <tbody>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9; width:140px"><strong>TO Number</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.travel_order_num}</td></tr>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Destination</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.destination}</td></tr>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Departure</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.departure || '-'}</td></tr>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Return</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.return || '-'}</td></tr>
                                    <tr><td style="padding:8px; border:1px solid #f1f5f9"><strong>Purpose</strong></td><td style="padding:8px; border:1px solid #f1f5f9">${d.purpose || '-'}</td></tr>
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
function closeTravelOrderModal(){ document.getElementById('travelOrderModalOverlay').style.display='none'; }
</script>

@endsection
