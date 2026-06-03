@extends('dashboards.layout', [
    'title' => 'Travel Order Approval',
    'subtitle' => 'Review and manage travel order requests.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
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
        <table class="hris-table">
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
                            <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="viewTravelOrder({{ $to->id }})">View</button>
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
            <button type="button" class="hris-btn hris-btn-primary hris-btn-sm" id="btnApproveTO">Approve</button>
            <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" id="btnRejectTO">Reject</button>
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
