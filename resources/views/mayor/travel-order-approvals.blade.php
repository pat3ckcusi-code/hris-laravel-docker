@extends('dashboards.layout', [
    'title' => 'Travel Order Approval',
    'subtitle' => 'Review and manage travel order requests.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
    <style>
        a.kpi-card {
            text-decoration: none;
        }
        .kpi-card.active {
            border-color: var(--accent, #ea580c);
            box-shadow: 0 0 0 2px rgba(234, 88, 12, 0.15), 0 24px 48px rgba(2, 6, 23, 0.08);
        }
        .emp-list-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.375rem;
            font-size: 0.8125rem;
        }
        .emp-list-table th,
        .emp-list-table td {
            border: 1px solid #e5e7eb;
            padding: 0.375rem 0.625rem;
            text-align: left;
        }
        .emp-list-table th {
            background: #f9fafb;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        .emp-list-wrapper {
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            margin-top: 0.375rem;
        }
        .emp-list-wrapper .emp-list-table { margin-top: 0; border: none; }
        .emp-list-wrapper .emp-list-table th,
        .emp-list-wrapper .emp-list-table td { border: none; border-bottom: 1px solid #f1f5f9; }

        /* Travel Order Details modal */
        #viewModal .modal-box {
            max-width: 680px;
            padding: 0;
            overflow: hidden;
        }
        .tod-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.1rem 1.5rem;
            background: linear-gradient(90deg, #fff7ed 0%, #fffaf0 100%);
            border-bottom: 1px solid #fdba74;
        }
        .tod-header h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
        }
        .tod-header h3 i { color: var(--accent, #ea580c); margin-right: 0.5rem; }
        .tod-body { padding: 1.25rem 1.5rem 1.5rem; max-height: 70vh; overflow-y: auto; }

        .tod-trip { display: flex; gap: 14px; align-items: center; margin-bottom: 1.1rem; }
        .tod-trip-icon {
            flex: 0 0 auto;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #fff7ed;
            border: 2px solid #fed7aa;
            color: #9a3412;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }
        .tod-trip-title { font-size: 1.05rem; font-weight: 700; color: var(--ink); }
        .tod-trip-sub { font-size: 0.82rem; color: var(--muted); margin-top: 2px; }

        .tod-banner {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0.6rem 0.9rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 1.1rem;
        }
        .tod-banner-pending { background: #fef3c7; color: #92400e; }
        .tod-banner-approved { background: #dcfce7; color: #166534; }
        .tod-banner-rejected { background: #fee2e2; color: #991b1b; }
        .tod-banner-default { background: #f3f4f6; color: #4b5563; }

        .tod-section { margin-bottom: 1.1rem; }
        .tod-section:last-child { margin-bottom: 0; }
        .tod-section-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            margin-bottom: 0.5rem;
        }

        .tod-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        .tod-item-wide { grid-column: 1 / -1; }
        .tod-item .tod-label { font-size: 0.75rem; color: #64748b; margin-bottom: 0.15rem; }
        .tod-item .tod-value { font-size: 0.9rem; color: #0f172a; font-weight: 500; word-break: break-word; }

        .tod-stat {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.6rem 0.5rem;
            text-align: center;
        }
        .tod-stat .tod-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }
        .tod-stat .tod-value { font-size: 1.05rem; font-weight: 700; color: #0f172a; margin-top: 2px; }

        .tod-remarks {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 0.5rem;
            padding: 0.75rem 0.9rem;
            color: #991b1b;
            font-size: 0.875rem;
        }
    </style>
@endsection

@section('tiles')
    @php
        $toTiles = [
            ['key' => 'Pending', 'label' => 'Pending', 'icon' => 'fa-hourglass-half', 'accent' => 'accent-leave', 'meta' => 'Awaiting your decision'],
            ['key' => 'Approved', 'label' => 'Approved', 'icon' => 'fa-circle-check', 'accent' => 'accent-overtime', 'meta' => 'Cleared travel orders'],
            ['key' => 'Rejected', 'label' => 'Rejected', 'icon' => 'fa-circle-xmark', 'accent' => 'accent-eta', 'meta' => 'Rejected travel orders'],
            ['key' => 'All', 'label' => 'All Orders', 'icon' => 'fa-layer-group', 'accent' => 'accent-workforce', 'meta' => 'Full history this month'],
        ];
    @endphp
    @foreach($toTiles as $tile)
        <a href="{{ route('mayor.travel-order-approvals', ['status' => $tile['key'], 'month' => $month, 'year' => $year]) }}"
           class="kpi-card {{ $tile['accent'] }} {{ $statusFilter === $tile['key'] ? 'active' : '' }}">
            <div>
                <div class="kpi-head">
                    <div class="kpi-icon" aria-hidden="true"><i class="fa-solid {{ $tile['icon'] }}"></i></div>
                    <div class="kpi-title">{{ $tile['label'] }}</div>
                </div>
                <div class="kpi-meta">{{ $tile['meta'] }}</div>
            </div>
            <div class="kpi-value">{{ $statusCounts[$tile['key']] ?? 0 }}</div>
        </a>
    @endforeach
@endsection

@section('content')
@php
    $prevDate = (new DateTime())->setDate($year, $month, 1)->modify('-1 month');
    $nextDate = (new DateTime())->setDate($year, $month, 1)->modify('+1 month');
@endphp
<x-hris.table-layout
    title="Travel Orders"
    :subtitle="'Showing ' . ($statusFilter === 'All' ? 'all' : $statusFilter) . ' orders for ' . date('F', mktime(0, 0, 0, $month, 1, $year)) . ' ' . $year"
    :showSearch="false"
    :showMonthFilter="false"
    :paginator="$travelOrders"
>
    <x-slot:filters>
        <div class="hris-filter-left" style="align-items:center;">
            <button type="button" class="month-nav" onclick="window.location='{{ route('mayor.travel-order-approvals', ['status' => $statusFilter, 'month' => $prevDate->format('n'), 'year' => $prevDate->format('Y')]) }}'">&laquo; Prev</button>
            <div class="font-weight-bold">{{ date('F', mktime(0, 0, 0, $month, 1, $year)) }} {{ $year }}</div>
            <button type="button" class="month-nav" onclick="window.location='{{ route('mayor.travel-order-approvals', ['status' => $statusFilter, 'month' => $nextDate->format('n'), 'year' => $nextDate->format('Y')]) }}'">Next &raquo;</button>
        </div>
        <div>
            <button type="button" class="month-nav" onclick="window.location='{{ route('mayor.travel-order-approvals', ['status' => $statusFilter, 'month' => date('n'), 'year' => date('Y')]) }}'">This Month</button>
        </div>
    </x-slot:filters>

    @if($travelOrders->isEmpty())
        <div class="hris-empty-state">
            <div class="hris-empty-state-icon"><i class="fa fa-inbox"></i></div>
            <div class="hris-empty-state-title">No travel orders found</div>
            <div class="hris-empty-state-text">There are no {{ $statusFilter === 'All' ? '' : $statusFilter . ' ' }}travel orders for {{ date('F', mktime(0, 0, 0, $month, 1, $year)) }} {{ $year }}.</div>
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
                    <tr>
                        <td>{{ $travelOrders->firstItem() + $idx }}</td>
                        <td>{{ $to->travel_order_num }}</td>
                        <td>{{ \Carbon\Carbon::parse($to->start_date)->format('M d, Y') }} - {{ \Carbon\Carbon::parse($to->end_date)->format('M d, Y') }}</td>
                        <td>{{ $to->employee_count }}</td>
                        <td><x-hris.status-badge :status="$to->status" /></td>
                        <td>
                            <div class="action-btns">
                                <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="viewTravelOrder({{ $to->id }})"><i class="fa fa-eye"></i> View</button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-hris.table-layout>

{{-- View Details Modal --}}
<div class="modal-overlay" id="viewModal">
    <div class="modal-box">
        <div class="tod-header">
            <h3><i class="fa fa-route"></i>Travel Order Details</h3>
            <button class="modal-close" onclick="closeViewModal()" style="float:none;">&times;</button>
        </div>
        <div class="tod-body" id="viewModalContent">Loading...</div>
        <div class="modal-actions" id="modalActions" style="display:none;padding:0 1.5rem 1.25rem;">
            <button type="button" class="hris-btn hris-btn-primary hris-btn-sm" id="btnApproveTO"><i class="fa fa-check"></i> Approve</button>
            <button type="button" class="hris-btn hris-btn-danger hris-btn-sm" id="btnRejectTO"><i class="fa fa-times"></i> Reject</button>
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
    var currentMonth = '{{ $month }}';
    var currentYear = '{{ $year }}';

    function applyFilters() {
        window.location.href = '{{ route("mayor.travel-order-approvals") }}?status=' + encodeURIComponent(currentStatus) + '&month=' + encodeURIComponent(currentMonth) + '&year=' + encodeURIComponent(currentYear);
    }

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

            var statusMeta = {
                Pending: { icon: 'fa-hourglass-half', label: 'Pending Approval', cls: 'tod-banner-pending' },
                Approved: { icon: 'fa-circle-check', label: 'Approved', cls: 'tod-banner-approved' },
                Rejected: { icon: 'fa-circle-xmark', label: 'Rejected', cls: 'tod-banner-rejected' }
            };
            var meta = statusMeta[d.status] || { icon: 'fa-circle-info', label: (d.status || 'Unknown'), cls: 'tod-banner-default' };

            var html = ''
                + '<div class="tod-trip">'
                +   '<div class="tod-trip-icon"><i class="fa fa-route"></i></div>'
                +   '<div>'
                +     '<div class="tod-trip-title">' + escapeHtml(d.travel_order_num) + '</div>'
                +     '<div class="tod-trip-sub">' + escapeHtml(d.destination) + '</div>'
                +   '</div>'
                + '</div>'
                + '<div class="tod-banner ' + meta.cls + '"><i class="fa ' + meta.icon + '"></i> ' + escapeHtml(meta.label) + '</div>'
                + '<div class="tod-section">'
                +   '<div class="tod-section-title">Trip Details</div>'
                +   '<div class="tod-grid">'
                +     todItem('Purpose', d.purpose, true)
                +     todItem('Remarks', d.remarks, true)
                +   '</div>'
                + '</div>'
                + '<div class="tod-section">'
                +   '<div class="tod-section-title">Duration</div>'
                +   '<div class="tod-grid">'
                +     todStat('Start Date', formatDate(d.start_date))
                +     todStat('End Date', formatDate(d.end_date))
                +   '</div>'
                + '</div>'
                + '<div class="tod-section">'
                +   '<div class="tod-section-title">Approval Chain</div>'
                +   '<div class="tod-grid">'
                +     todItem('Created By', d.created_by)
                +     todItem('Recommender', d.recommender)
                +   '</div>'
                + '</div>';

            if (d.status === 'Rejected' && d.rejection_note) {
                html += '<div class="tod-section">'
                    + '<div class="tod-section-title">Rejection Note</div>'
                    + '<div class="tod-remarks">' + escapeHtml(d.rejection_note) + '</div>'
                    + '</div>';
            }

            html += '<div class="tod-section">'
                + '<div class="tod-section-title">Employees (' + d.employees.length + ')</div>';
            if (d.employees.length > 0) {
                html += '<div class="emp-list-wrapper"><table class="emp-list-table"><thead><tr><th>#</th><th>Emp No</th><th>Name</th><th>Designation</th></tr></thead><tbody>';
                for (var j = 0; j < d.employees.length; j++) {
                    var emp = d.employees[j];
                    html += '<tr><td>' + (j + 1) + '</td><td>' + escapeHtml(emp.emp_no) + '</td><td>' + escapeHtml(emp.name) + '</td><td>' + escapeHtml(emp.designation) + '</td></tr>';
                }
                html += '</tbody></table></div>';
            } else {
                html += '<p style="color:#6b7280;font-size:13px;">No employees linked to this travel order.</p>';
            }
            html += '</div>';

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

    function todItem(label, value, wide) {
        return '<div class="tod-item' + (wide ? ' tod-item-wide' : '') + '">'
            + '<div class="tod-label">' + escapeHtml(label) + '</div>'
            + '<div class="tod-value">' + (escapeHtml(value) || '-') + '</div>'
            + '</div>';
    }

    function todStat(label, value) {
        return '<div class="tod-stat">'
            + '<div class="tod-label">' + escapeHtml(label) + '</div>'
            + '<div class="tod-value">' + (escapeHtml(value) || '-') + '</div>'
            + '</div>';
    }
});
</script>
@endsection
