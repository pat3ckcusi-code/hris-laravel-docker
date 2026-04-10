@extends('dashboards.layout', [
    'title' => 'Travel Order Approval',
    'subtitle' => 'Review and manage travel order requests.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
    <style>
        .mayor-filter-row { display:flex; gap:12px; align-items:center; margin-bottom:18px; flex-wrap:wrap; }
        .mayor-filter-row label { font-weight:600; font-size:14px; }
        .mayor-filter-row select,
        .mayor-filter-row input[type="month"] { padding:6px 10px; border:1px solid #d1d5db; border-radius:4px; font-size:14px; }
        .leave-table { width:100%; border-collapse:collapse; margin-top:12px; }
        .leave-table th, .leave-table td { border:1px solid #e5e7eb; padding:10px 12px; font-size:14px; text-align:left; }
        .leave-table th { background:#f9fafb; font-weight:600; text-transform:uppercase; font-size:12px; letter-spacing:.04em; }
        .leave-table tr:hover { background:#f0f9ff; }
        .badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:600; text-transform:capitalize; }
        .badge-pending { background:#fef3c7; color:#92400e; }
        .badge-approved { background:#dcfce7; color:#15803d; }
        .badge-rejected { background:#fee2e2; color:#b91c1c; }
        .badge-draft { background:#f3f4f6; color:#6b7280; }
        .btn-sm { padding:5px 12px; font-size:13px; border:none; border-radius:4px; cursor:pointer; font-weight:600; }
        .btn-view { background:#2563eb; color:#fff; }
        .btn-view:hover { background:#1d4ed8; }
        .btn-approve { background:#16a34a; color:#fff; }
        .btn-approve:hover { background:#15803d; }
        .btn-reject { background:#dc2626; color:#fff; }
        .btn-reject:hover { background:#b91c1c; }
        .action-btns { display:flex; gap:6px; flex-wrap:wrap; }
        .empty-state { text-align:center; padding:40px 20px; color:#6b7280; font-size:15px; }
        .pagination-wrap { margin-top:16px; display:flex; justify-content:center; }
        .pagination-wrap nav { display:flex; gap:4px; }

        /* View modal */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; justify-content:center; align-items:center; }
        .modal-overlay.active { display:flex; }
        .modal-box { background:#fff; border-radius:8px; max-width:650px; width:95%; max-height:85vh; overflow-y:auto; padding:24px; }
        .modal-box h3 { margin:0 0 16px; font-size:18px; }
        .modal-box .detail-row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:14px; }
        .modal-box .detail-row:last-child { border-bottom:none; }
        .modal-close { float:right; background:none; border:none; font-size:22px; cursor:pointer; color:#6b7280; }
        .modal-close:hover { color:#111; }
        .modal-section-title { font-weight:600; font-size:15px; margin:16px 0 8px; padding-bottom:4px; border-bottom:2px solid #e5e7eb; }
        .emp-list-table { width:100%; border-collapse:collapse; margin-top:6px; font-size:13px; }
        .emp-list-table th, .emp-list-table td { border:1px solid #e5e7eb; padding:6px 10px; text-align:left; }
        .emp-list-table th { background:#f9fafb; font-weight:600; }
        .modal-actions { display:flex; gap:8px; margin-top:18px; justify-content:flex-end; }
    </style>
@endsection

@section('content')
<section>
    <div class="mayor-filter-row">
        <label for="statusFilter">Status</label>
        <select id="statusFilter">
            <option value="Pending" @if($statusFilter === 'Pending') selected @endif>Pending</option>
            <option value="Approved" @if($statusFilter === 'Approved') selected @endif>Approved</option>
            <option value="Rejected" @if($statusFilter === 'Rejected') selected @endif>Rejected</option>
            <option value="All" @if($statusFilter === 'All') selected @endif>All</option>
        </select>

        <label for="monthFilter">Month</label>
        <input type="month" id="monthFilter" value="{{ $monthFilter }}">
    </div>

    @if($travelOrders->isEmpty())
        <div class="empty-state">
            <i class="fas fa-inbox" style="font-size:32px;margin-bottom:12px;"></i>
            <p>No travel orders found for the selected filters.</p>
        </div>
    @else
        <table class="leave-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Travel Order ID</th>
                    <th>Date</th>
                    <th>Employee Count</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($travelOrders as $idx => $to)
                    @php
                        $statusClass = match($to->status) {
                            'Pending' => 'badge-pending',
                            'Approved' => 'badge-approved',
                            'Rejected' => 'badge-rejected',
                            default => 'badge-draft',
                        };
                    @endphp
                    <tr>
                        <td>{{ $travelOrders->firstItem() + $idx }}</td>
                        <td>{{ $to->travel_order_num }}</td>
                        <td>{{ \Carbon\Carbon::parse($to->start_date)->format('M d, Y') }} &mdash; {{ \Carbon\Carbon::parse($to->end_date)->format('M d, Y') }}</td>
                        <td>{{ $to->employee_count }}</td>
                        <td><span class="badge {{ $statusClass }}">{{ $to->status }}</span></td>
                        <td>
                            <button type="button" class="btn-sm btn-view" onclick="viewTravelOrder({{ $to->id }})">View</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="pagination-wrap">
            {{ $travelOrders->appends(['status' => $statusFilter, 'month' => $monthFilter])->links() }}
        </div>
    @endif
</section>

{{-- View Details Modal --}}
<div class="modal-overlay" id="viewModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeViewModal()">&times;</button>
        <h3>Travel Order Details</h3>
        <div id="viewModalContent">Loading...</div>
        <div class="modal-actions" id="modalActions" style="display:none;">
            <button type="button" class="btn-sm btn-approve" id="btnApproveTO">Approve</button>
            <button type="button" class="btn-sm btn-reject" id="btnRejectTO">Reject</button>
        </div>
    </div>
</div>
@endsection

@section('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    var token = document.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value
                || '{{ csrf_token() }}';

    var currentStatus = '{{ $statusFilter }}';
    var currentMonth = '{{ $monthFilter }}';

    function applyFilters() {
        var status = document.getElementById('statusFilter').value;
        var month = document.getElementById('monthFilter').value;
        window.location.href = '{{ route("mayor.travel-order-approvals") }}?status=' + encodeURIComponent(status) + '&month=' + encodeURIComponent(month);
    }

    document.getElementById('statusFilter').addEventListener('change', applyFilters);
    document.getElementById('monthFilter').addEventListener('change', applyFilters);

    var currentOrderId = null;

    window.viewTravelOrder = function(id) {
        currentOrderId = id;
        document.getElementById('viewModalContent').innerHTML = 'Loading...';
        document.getElementById('modalActions').style.display = 'none';
        document.getElementById('viewModal').classList.add('active');

        fetch('{{ url("mayor/travel-orders") }}/' + id, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) { return res.json(); })
        .then(function(result) {
            if (!result.success) {
                document.getElementById('viewModalContent').innerHTML = '<p style="color:#dc2626;">Failed to load travel order details.</p>';
                return;
            }
            var d = result.data;
            var html = '';

            // Details section
            var rows = [
                ['Travel Order #', d.travel_order_num],
                ['Destination', d.destination],
                ['Purpose', d.purpose],
                ['Start Date', formatDate(d.start_date)],
                ['End Date', formatDate(d.end_date)],
                ['Remarks', d.remarks || '-'],
                ['Created By', d.created_by],
                ['Recommender', d.recommender],
                ['Status', d.status],
            ];
            if (d.status === 'Rejected' && d.rejection_note) {
                rows.push(['Rejection Note', d.rejection_note]);
            }
            for (var i = 0; i < rows.length; i++) {
                html += '<div class="detail-row"><strong>' + rows[i][0] + '</strong><span>' + (rows[i][1] || '-') + '</span></div>';
            }

            // Employees section
            html += '<div class="modal-section-title">Employees (' + d.employees.length + ')</div>';
            if (d.employees.length > 0) {
                html += '<table class="emp-list-table"><thead><tr><th>#</th><th>Emp No</th><th>Name</th><th>Designation</th></tr></thead><tbody>';
                for (var j = 0; j < d.employees.length; j++) {
                    var emp = d.employees[j];
                    html += '<tr><td>' + (j + 1) + '</td><td>' + escapeHtml(emp.emp_no) + '</td><td>' + escapeHtml(emp.name) + '</td><td>' + escapeHtml(emp.designation) + '</td></tr>';
                }
                html += '</tbody></table>';
            } else {
                html += '<p style="color:#6b7280;font-size:13px;">No employees linked to this travel order.</p>';
            }

            document.getElementById('viewModalContent').innerHTML = html;

            // Show approve/reject buttons only for Pending Approval
            if (d.status === 'Pending') {
                document.getElementById('modalActions').style.display = 'flex';
            } else {
                document.getElementById('modalActions').style.display = 'none';
            }
        })
        .catch(function() {
            document.getElementById('viewModalContent').innerHTML = '<p style="color:#dc2626;">Error loading travel order.</p>';
        });
    };

    window.closeViewModal = function() {
        document.getElementById('viewModal').classList.remove('active');
        currentOrderId = null;
    };

    document.getElementById('viewModal').addEventListener('click', function(e) {
        if (e.target === this) closeViewModal();
    });

    // Approve button
    document.getElementById('btnApproveTO').addEventListener('click', function() {
        if (!currentOrderId) return;
        window.Swal.fire({
            title: 'Approve Travel Order?',
            text: 'This will approve the travel order.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            confirmButtonText: 'Yes, Approve',
            cancelButtonText: 'Cancel'
        }).then(function(result) {
            if (result.isConfirmed) {
                fetch('{{ url("mayor/travel-orders") }}/' + currentOrderId + '/approve', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({ _token: token })
                })
                .then(function(res) { return res.json().then(function(data) { return { ok: res.ok, data: data }; }); })
                .then(function(result) {
                    if (result.ok && result.data.success) {
                        Swal.fire({ icon: 'success', title: 'Approved', text: result.data.message || 'Travel Order Approved.' }).then(function() {
                            closeViewModal();
                            applyFilters();
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: result.data.message || 'Failed to approve.' });
                    }
                })
                .catch(function(err) {
                    Swal.fire({ icon: 'error', text: 'Failed to approve. ' + (err.message || '') });
                });
            }
        });
    });

    // Reject button
    document.getElementById('btnRejectTO').addEventListener('click', function() {
        if (!currentOrderId) return;
        window.Swal.fire({
            title: 'Reject Travel Order?',
            icon: 'warning',
            input: 'textarea',
            inputLabel: 'Rejection note (required)',
            inputPlaceholder: 'Enter the reason for rejection...',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            confirmButtonText: 'Reject',
            cancelButtonText: 'Cancel',
            inputValidator: function(value) {
                if (!value || !value.trim()) {
                    return 'Rejection note is required.';
                }
                if (value.length > 50) {
                    return 'Rejection note must not exceed 50 characters.';
                }
            }
        }).then(function(result) {
            if (result.isConfirmed) {
                fetch('{{ url("mayor/travel-orders") }}/' + currentOrderId + '/reject', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({ _token: token, rejection_note: result.value })
                })
                .then(function(res) { return res.json().then(function(data) { return { ok: res.ok, data: data }; }); })
                .then(function(result) {
                    if (result.ok && result.data.success) {
                        Swal.fire({ icon: 'success', title: 'Rejected', text: result.data.message || 'Travel Order Rejected.' }).then(function() {
                            closeViewModal();
                            applyFilters();
                        });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Error', text: result.data.message || 'Failed to reject.' });
                    }
                })
                .catch(function(err) {
                    Swal.fire({ icon: 'error', text: 'Failed to reject. ' + (err.message || '') });
                });
            }
        });
    });

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        var d = new Date(dateStr);
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return months[d.getMonth()] + ' ' + String(d.getDate()).padStart(2, '0') + ', ' + d.getFullYear();
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
});
</script>
@endsection
