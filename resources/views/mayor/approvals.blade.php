@extends('dashboards.layout', [
    'title' => 'Leave Approvals',
    'subtitle' => 'Review and manage leave requests from Department Heads and HR Managers.',
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

        /* Leave Request Details modal */
        #viewModal .modal-box {
            max-width: 640px;
            padding: 0;
            overflow: hidden;
        }
        .lrd-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.1rem 1.5rem;
            background: linear-gradient(90deg, #fff7ed 0%, #fffaf0 100%);
            border-bottom: 1px solid #fdba74;
        }
        .lrd-header h3 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
        }
        .lrd-header h3 i { color: var(--accent, #ea580c); margin-right: 0.5rem; }
        .lrd-body { padding: 1.25rem 1.5rem 1.5rem; }
        .lrd-profile { display: flex; gap: 14px; align-items: center; margin-bottom: 1.1rem; }
        .lrd-profile .profile-avatar { width: 48px; height: 48px; font-size: 1rem; }
        .lrd-profile .profile-name { font-size: 1.05rem; }
        .lrd-profile .profile-position { font-size: 0.82rem; }
        .lrd-profile .profile-meta { margin-top: 8px; }

        .lrd-banner {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 0.6rem 0.9rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 1.1rem;
        }
        .lrd-banner-pending { background: #fef3c7; color: #92400e; }
        .lrd-banner-approved { background: #dcfce7; color: #166534; }
        .lrd-banner-declined { background: #fee2e2; color: #991b1b; }
        .lrd-banner-default { background: #f3f4f6; color: #4b5563; }

        .lrd-section { margin-bottom: 1.1rem; }
        .lrd-section:last-child { margin-bottom: 0; }
        .lrd-section-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #94a3b8;
            margin-bottom: 0.5rem;
        }

        .lrd-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
        .lrd-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .lrd-item-wide { grid-column: 1 / -1; }
        .lrd-item .lrd-label { font-size: 0.75rem; color: #64748b; margin-bottom: 0.15rem; }
        .lrd-item .lrd-value { font-size: 0.9rem; color: #0f172a; font-weight: 500; word-break: break-word; }

        .lrd-stat {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.6rem 0.5rem;
            text-align: center;
        }
        .lrd-stat .lrd-label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; letter-spacing: 0.03em; }
        .lrd-stat .lrd-value { font-size: 1.15rem; font-weight: 700; color: #0f172a; margin-top: 2px; }
        .lrd-stat.tone-good .lrd-value { color: #166534; }
        .lrd-stat.tone-warn { background: #fff7ed; border-color: #fed7aa; }
        .lrd-stat.tone-warn .lrd-value { color: #9a3412; }

        .lrd-remarks {
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
        $tiles = [
            ['key' => 'pending', 'label' => 'Pending', 'icon' => 'fa-hourglass-half', 'accent' => 'accent-leave', 'meta' => 'Awaiting your decision'],
            ['key' => 'approved', 'label' => 'Approved', 'icon' => 'fa-circle-check', 'accent' => 'accent-overtime', 'meta' => 'Cleared for printing'],
            ['key' => 'declined', 'label' => 'Declined', 'icon' => 'fa-circle-xmark', 'accent' => 'accent-eta', 'meta' => 'Rejected requests'],
            ['key' => 'all', 'label' => 'All Requests', 'icon' => 'fa-layer-group', 'accent' => 'accent-workforce', 'meta' => 'Full history'],
        ];
        $activeStatus = $statusFilter ?? 'pending';
    @endphp
    @foreach($tiles as $tile)
        <a href="{{ route('mayor.approvals', ['status' => $tile['key'], 'month' => $month, 'year' => $year]) }}"
           class="kpi-card {{ $tile['accent'] }} {{ $activeStatus === $tile['key'] ? 'active' : '' }}">
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
    title="Leave Requests"
    :subtitle="'Showing ' . ($activeStatus === 'all' ? 'all' : $activeStatus) . ' requests for ' . date('F', mktime(0, 0, 0, $month, 1, $year)) . ' ' . $year"
    :showSearch="false"
    :showMonthFilter="false"
    :paginator="$leaveRequests"
>
    <x-slot:filters>
        <div class="hris-filter-left" style="align-items:center;">
            <button type="button" class="month-nav" onclick="window.location='{{ route('mayor.approvals', ['status' => $statusFilter, 'month' => $prevDate->format('n'), 'year' => $prevDate->format('Y')]) }}'">&laquo; Prev</button>
            <div class="font-weight-bold">{{ date('F', mktime(0, 0, 0, $month, 1, $year)) }} {{ $year }}</div>
            <button type="button" class="month-nav" onclick="window.location='{{ route('mayor.approvals', ['status' => $statusFilter, 'month' => $nextDate->format('n'), 'year' => $nextDate->format('Y')]) }}'">Next &raquo;</button>
        </div>
        <div>
            <button type="button" class="month-nav" onclick="window.location='{{ route('mayor.approvals', ['status' => $statusFilter, 'month' => date('n'), 'year' => date('Y')]) }}'">This Month</button>
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
                    $empInitials = mb_strtoupper(mb_substr($emp->first_name ?: ($emp->name ?? ''), 0, 1) . mb_substr($emp->last_name ?? '', 0, 1));
                @endphp
                <tr>
                    <td>{{ $leaveRequests->firstItem() + $idx }}</td>
                    <td>{{ $emp->EmpNo ?? 'N/A' }}</td>
                    <td>
                        <div class="incumbent-cell">
                            <span class="avatar-sm">{{ $empInitials ?: '?' }}</span>
                            <span>{{ $empName }}</span>
                        </div>
                    </td>
                    <td><span class="chip">{{ $emp->access_level ?? 'N/A' }}</span></td>
                    <td>{{ $lr->leave_type }}</td>
                    <td>{{ $lr->formattedPeriod() }}</td>
                    <td>{{ $lr->total_days ?? '-' }}</td>
                    <td><x-hris.status-badge :status="$lr->status" /></td>
                    <td>
                        <div class="action-btns">
                            <button type="button" class="hris-btn hris-btn-secondary hris-btn-sm" onclick="viewLeaveDetails({{ $lr->id }})"><i class="fa fa-eye"></i> View</button>
                            @if($lr->status === 'pending')
                                <form class="approve-form" action="{{ route('mayor.leave.approve', $lr->id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm"><i class="fa fa-check"></i> Approve</button>
                                </form>
                                <form class="reject-form" action="{{ route('mayor.leave.reject', $lr->id) }}" method="POST" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="hris-btn hris-btn-danger hris-btn-sm"><i class="fa fa-times"></i> Reject</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <div class="hris-empty-state">
                            <div class="hris-empty-state-icon"><i class="fa fa-inbox"></i></div>
                            <div class="hris-empty-state-title">No leave requests found</div>
                            <div class="hris-empty-state-text">There are no {{ $activeStatus === 'all' ? '' : $activeStatus . ' ' }}leave requests for {{ date('F', mktime(0, 0, 0, $month, 1, $year)) }} {{ $year }}.</div>
                        </div>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</x-hris.table-layout>

{{-- View Details Modal --}}
<div class="modal-overlay" id="viewModal">
    <div class="modal-box">
        <div class="lrd-header">
            <h3><i class="fa fa-file-lines"></i>Leave Request Details</h3>
            <button class="modal-close" onclick="closeViewModal()" style="float:none;">&times;</button>
        </div>
        <div class="lrd-body" id="viewModalContent">Loading...</div>
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
        $initials = mb_strtoupper(mb_substr($emp->first_name ?: ($emp->name ?? ''), 0, 1) . mb_substr($emp->last_name ?? '', 0, 1));
        return [$lr->id => [
            'initials' => $initials ?: '?',
            'emp_no' => $emp->EmpNo ?? 'N/A',
            'name' => $empName,
            'role' => $emp->access_level ?? 'N/A',
            'department' => $deptName,
            'leave_type' => $lr->leave_type,
            'leave_dates' => $lr->leaveDatesBreakdown(),
            'date_filed' => \Carbon\Carbon::parse($lr->created_at)->format('M d, Y'),
            'period' => $lr->formattedPeriod(),
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

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(String(str)));
    return div.innerHTML;
}

function lrdItem(label, value, wide) {
    return '<div class="lrd-item' + (wide ? ' lrd-item-wide' : '') + '">'
        + '<div class="lrd-label">' + escapeHtml(label) + '</div>'
        + '<div class="lrd-value">' + (escapeHtml(value) || '-') + '</div>'
        + '</div>';
}

function lrdStat(label, value, tone) {
    return '<div class="lrd-stat' + (tone ? ' tone-' + tone : '') + '">'
        + '<div class="lrd-label">' + escapeHtml(label) + '</div>'
        + '<div class="lrd-value">' + (escapeHtml(value) || '-') + '</div>'
        + '</div>';
}

// Renders either the flat comma-joined leave_type string (via lrdItem, same as any
// other field), or - when a multi-date filing assigned more than one distinct type
// across its dates - a small per-date table (Date | Type | Days) so it's clear which
// date got which type. lrdItem() HTML-escapes its value, so it can't hold a <table>;
// this builds its own markup instead.
function lrdLeaveType(flatType, dates) {
    var types = (dates || []).map(function (d) { return d.leave_type; })
        .filter(function (v, i, arr) { return v && arr.indexOf(v) === i; });
    if (!dates || !dates.length || types.length <= 1) {
        return lrdItem('Leave Type', flatType);
    }
    var rows = dates.map(function (d) {
        return '<tr><td>' + escapeHtml(d.label) + '</td><td>' + escapeHtml(d.leave_type) + '</td><td style="text-align:right">' + d.days + '</td></tr>';
    }).join('');
    return '<div class="lrd-item lrd-item-wide"><div class="lrd-label">Leave Type (by date)</div>'
        + '<table style="width:100%;border-collapse:collapse;font-size:0.85rem;margin-top:4px">'
        + '<thead><tr><th style="text-align:left">Date</th><th style="text-align:left">Type</th><th style="text-align:right">Days</th></tr></thead>'
        + '<tbody>' + rows + '</tbody></table></div>';
}

var LRD_STATUS_META = {
    pending: { icon: 'fa-hourglass-half', label: 'Pending Approval', cls: 'lrd-banner-pending' },
    approved: { icon: 'fa-circle-check', label: 'Approved', cls: 'lrd-banner-approved' },
    declined: { icon: 'fa-circle-xmark', label: 'Declined', cls: 'lrd-banner-declined' }
};

function viewLeaveDetails(id) {
    var data = leaveDataCache[id];
    if (!data) return;

    var meta = LRD_STATUS_META[data.status] || { icon: 'fa-circle-info', label: (data.status || 'Unknown'), cls: 'lrd-banner-default' };
    var lwopDays = parseFloat(data.lwop_days);

    var html = ''
        + '<div class="lrd-profile">'
        +   '<span class="profile-avatar">' + escapeHtml(data.initials || '?') + '</span>'
        +   '<div class="profile-body">'
        +     '<div class="profile-name">' + escapeHtml(data.name) + '</div>'
        +     '<div class="profile-position">' + escapeHtml(data.emp_no) + '</div>'
        +     '<div class="profile-meta">'
        +       '<span class="meta-chip"><i class="fa fa-user-tie"></i>' + escapeHtml(data.role) + '</span>'
        +       '<span class="meta-chip"><i class="fa fa-building"></i>' + escapeHtml(data.department) + '</span>'
        +     '</div>'
        +   '</div>'
        + '</div>'
        + '<div class="lrd-banner ' + meta.cls + '"><i class="fa ' + meta.icon + '"></i> ' + escapeHtml(meta.label) + '</div>'
        + '<div class="lrd-section">'
        +   '<div class="lrd-section-title">Leave Information</div>'
        +   '<div class="lrd-grid">'
        +     lrdLeaveType(data.leave_type, data.leave_dates)
        +     lrdItem('Date Filed', data.date_filed)
        +     lrdItem('Reason', data.reason, true)
        +   '</div>'
        + '</div>'
        + '<div class="lrd-section">'
        +   '<div class="lrd-section-title">Duration</div>'
        +   '<div class="lrd-grid lrd-grid-3">'
        +     lrdStat('Period', data.period)
        +     lrdStat('Total Days', data.total_days)
        +   '</div>'
        + '</div>'
        + '<div class="lrd-section">'
        +   '<div class="lrd-section-title">Balance Impact</div>'
        +   '<div class="lrd-grid">'
        +     lrdStat('Paid Days', data.paid_days, 'good')
        +     lrdStat('LWOP Days', data.lwop_days, lwopDays > 0 ? 'warn' : null)
        +   '</div>'
        + '</div>';

    if (data.status === 'declined' && data.rejection_notes !== '-') {
        html += '<div class="lrd-section">'
            + '<div class="lrd-section-title">Rejection Remarks</div>'
            + '<div class="lrd-remarks">' + escapeHtml(data.rejection_notes) + '</div>'
            + '</div>';
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
