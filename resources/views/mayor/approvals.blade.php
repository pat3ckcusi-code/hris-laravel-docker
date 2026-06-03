@extends('dashboards.layout', [
    'title' => 'Leave Approvals',
    'subtitle' => 'Review and manage leave requests from Department Heads and HR Managers.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
<x-hris.table-layout :showSearch="false" :showMonthFilter="false" :paginator="$leaveRequests">
    <x-slot:filters>
        <div style="display:flex;gap:8px;align-items:center">
            <label class="hris-filter-label" for="statusFilter">Status</label>
            <select id="statusFilter" class="hris-filter-select" onchange="window.location.href='{{ route('mayor.approvals') }}?status='+this.value">
                <option value="pending" @if(($statusFilter ?? 'pending') === 'pending') selected @endif>Pending</option>
                <option value="approved" @if(($statusFilter ?? '') === 'approved') selected @endif>Approved</option>
                <option value="declined" @if(($statusFilter ?? '') === 'declined') selected @endif>Declined</option>
                <option value="all" @if(($statusFilter ?? '') === 'all') selected @endif>All</option>
            </select>
        </div>
    </x-slot:filters>

    <table class="hris-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Employee No</th>
                <th>Name</th>
                <th>Role</th>
                <th>Leave Type</th>
                <th>Dates</th>
                <th>Total Days</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($leaveRequests as $idx => $lr)
                @php
                    $emp = $lr->user;
                    $empName = trim(($emp->first_name ?? '') . ' ' . ($emp->middle_name ?? '') . ' ' . ($emp->last_name ?? ''));
                    if (empty(trim($empName))) $empName = $emp->name ?? 'N/A';
                @endphp
                <tr>
                    <td>{{ $leaveRequests->firstItem() + $idx }}</td>
                    <td>{{ $emp->EmpNo ?? 'N/A' }}</td>
                    <td>{{ $empName }}</td>
                    <td>{{ $emp->access_level ?? 'N/A' }}</td>
                    <td>{{ $lr->leave_type }}</td>
                    <td>{{ \Carbon\Carbon::parse($lr->start_date)->format('M d, Y') }} &mdash; {{ \Carbon\Carbon::parse($lr->end_date)->format('M d, Y') }}</td>
                    <td>{{ $lr->total_days ?? '-' }}</td>
                    <td><x-hris.status-badge :status="$lr->status" /></td>
                    <td>
                        <div class="action-btns">
                            <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="viewLeaveDetails({{ $lr->id }})">View</button>
                            @if($lr->status === 'pending')
                                <form class="approve-form" action="{{ route('mayor.leave.approve', $lr->id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm">Approve</button>
                                </form>
                                <form class="reject-form" action="{{ route('mayor.leave.reject', $lr->id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="hris-btn hris-btn-danger hris-btn-sm">Reject</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="text-center text-muted">No leave requests found for the selected filter.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</x-hris.table-layout>

{{-- View Details Modal --}}
<div class="modal-overlay" id="viewModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeViewModal()">&times;</button>
        <h3>Leave Request Details</h3>
        <div id="viewModalContent">Loading...</div>
    </div>
</div>
@endsection

@section('page_scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    var token = document.querySelector('meta[name="csrf-token"]')?.content
                || document.querySelector('input[name="_token"]')?.value
                || '{{ csrf_token() }}';

    // Approve via SweetAlert2 confirmation
    document.querySelectorAll('.approve-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            window.Swal.fire({
                title: 'Approve Leave Request?',
                text: 'This will approve the leave request and deduct leave balance.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#16a34a',
                confirmButtonText: 'Yes, Approve',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({ _token: token })
                    })
                    .then(function(res) {
                        return res.json().then(function(data) { return { ok: res.ok, data: data }; });
                    })
                    .then(function(result) {
                        if (result.ok && result.data.success) {
                            Swal.fire({ icon: 'success', title: 'Approved', text: result.data.message || 'Leave request approved.' }).then(function() { location.reload(); });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: result.data.message || 'Failed to approve.' });
                        }
                    })
                    .catch(function(err) {
                        Swal.fire({ icon: 'error', text: 'Failed to approve request. ' + (err.message || '') });
                    });
                }
            });
        });
    });

    // Reject via SweetAlert2 with textarea for rejection notes
    document.querySelectorAll('.reject-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            window.Swal.fire({
                title: 'Reject Leave Request?',
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'Rejection remarks (required)',
                inputPlaceholder: 'Enter the reason for rejection...',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                confirmButtonText: 'Reject',
                cancelButtonText: 'Cancel',
                inputValidator: function(value) {
                    if (!value || !value.trim()) {
                        return 'Rejection remarks are required.';
                    }
                }
            }).then(function(result) {
                if (result.isConfirmed) {
                    fetch(form.action, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: new URLSearchParams({ _token: token, rejection_notes: result.value })
                    })
                    .then(function(res) {
                        return res.json().then(function(data) { return { ok: res.ok, data: data }; });
                    })
                    .then(function(result) {
                        if (result.ok && result.data.success) {
                            var swalData = result.data.swal || { icon: 'success', title: 'Rejected', text: 'Leave request has been rejected.' };
                            Swal.fire(swalData).then(function() { location.reload(); });
                        } else {
                            var errSwal = result.data.swal || { icon: 'error', title: 'Error', text: result.data.message || 'Failed to reject.' };
                            Swal.fire(errSwal);
                        }
                    })
                    .catch(function(err) {
                        Swal.fire({ icon: 'error', text: 'Failed to reject request. ' + (err.message || '') });
                    });
                }
            });
        });
    });
});

// View details modal
@php
    $leaveDataMap = $leaveRequests->getCollection()->mapWithKeys(function ($lr) {
        $emp = $lr->user;
        $empName = trim(($emp->first_name ?? '') . ' ' . ($emp->middle_name ?? '') . ' ' . ($emp->last_name ?? ''));
        if (empty(trim($empName))) $empName = $emp->name ?? 'N/A';
        $deptName = 'N/A';
        if ($emp && !empty($emp->Dept_id)) {
            $dept = \App\Models\Department::find($emp->Dept_id);
            if ($dept) $deptName = $dept->Dept_name ?? 'N/A';
        }
        return [$lr->id => [
            'emp_no' => $emp->EmpNo ?? 'N/A',
            'name' => $empName,
            'role' => $emp->access_level ?? 'N/A',
            'department' => $deptName,
            'leave_type' => $lr->leave_type,
            'date_filed' => \Carbon\Carbon::parse($lr->created_at)->format('M d, Y'),
            'start_date' => \Carbon\Carbon::parse($lr->start_date)->format('M d, Y'),
            'end_date' => \Carbon\Carbon::parse($lr->end_date)->format('M d, Y'),
            'total_days' => $lr->total_days ?? '-',
            'paid_days' => $lr->paid_days ?? '-',
            'lwop_days' => $lr->lwop_days ?? '-',
            'reason' => $lr->reason ?? '-',
            'status' => $lr->status,
            'rejection_notes' => $lr->rejection_notes ?? '-',
        ]];
    });
@endphp
var leaveDataCache = @json($leaveDataMap);

function viewLeaveDetails(id) {
    var data = leaveDataCache[id];
    if (!data) return;
    var html = '';
    var rows = [
        ['Employee No', data.emp_no],
        ['Name', data.name],
        ['Role', data.role],
        ['Department', data.department],
        ['Leave Type', data.leave_type],
        ['Date Filed', data.date_filed],
        ['Start Date', data.start_date],
        ['End Date', data.end_date],
        ['Total Days', data.total_days],
        ['Paid Days', data.paid_days],
        ['LWOP Days', data.lwop_days],
        ['Reason', data.reason],
        ['Status', data.status],
    ];
    if (data.status === 'declined' && data.rejection_notes !== '-') {
        rows.push(['Rejection Remarks', data.rejection_notes]);
    }
    for (var i = 0; i < rows.length; i++) {
        html += '<div class="detail-row"><strong>' + rows[i][0] + '</strong><span>' + (rows[i][1] || '-') + '</span></div>';
    }
    document.getElementById('viewModalContent').innerHTML = html;
    document.getElementById('viewModal').classList.add('active');
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeViewModal();
});
</script>
@endsection
