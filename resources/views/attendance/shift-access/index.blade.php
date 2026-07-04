@extends('dashboards.layout', [
    'title'    => 'Shift Access',
    'subtitle' => 'Grant or revoke a department\'s access to the Shift Management screens.',
])

@section('page_head')
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<style>
.access-dept-icon {
    display:inline-flex; align-items:center; justify-content:center;
    width:2.25rem; height:2.25rem; border-radius:.55rem; flex:0 0 auto;
    background:#eef2ff; color:#4338ca; font-size:.9rem;
}
.access-dept-name { font-weight:600; color:#0f172a; }
.access-dept-code { font-size:.75rem; color:#94a3b8; }
.access-officer { display:flex; align-items:center; gap:.5rem; }
.access-officer-avatar {
    display:inline-flex; align-items:center; justify-content:center;
    width:1.9rem; height:1.9rem; border-radius:50%; flex:0 0 auto;
    background:#f1f5f9; color:#475569; font-size:.68rem; font-weight:700;
}
.access-officer-empty { color:#cbd5e1; font-style:italic; font-size:.82rem; }
.access-badge-granted {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.3rem .7rem; border-radius:9999px; font-size:.72rem; font-weight:600;
    background:#d1fae5; color:#065f46; border:1px solid #6ee7b7;
}
.access-badge-none {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.3rem .7rem; border-radius:9999px; font-size:.72rem; font-weight:600;
    background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0;
}
</style>
@endsection

@section('content')

<div class="hris-table-card">

    <form method="GET" action="{{ route('attendance.shift-access.index') }}">
        <div class="hris-table-filters hris-filters-sticky">
            <div class="hris-filter-left">
                <div class="hris-filter-group">
                    <label class="hris-filter-label">Search</label>
                    <input type="text" name="search" value="{{ $search }}" placeholder="Department name or code"
                           class="hris-filter-select" style="min-width:14rem;">
                </div>
            </div>
            <div style="display:flex;gap:.5rem;align-items:flex-end;">
                <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm">
                    <i class="fas fa-search"></i> Search
                </button>
                @if ($search !== '')
                    <a href="{{ route('attendance.shift-access.index') }}" class="hris-btn hris-btn-secondary hris-btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div style="padding:.6rem 1.5rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:.8rem;color:#64748b;">
        @if ($rows->total() > 0)
            Showing <strong>{{ $rows->firstItem() }}–{{ $rows->lastItem() }}</strong>
            of <strong>{{ $rows->total() }}</strong> department{{ $rows->total() === 1 ? '' : 's' }}
        @else
            No departments found
        @endif
    </div>

    <div class="hris-table-wrapper">
        <table class="hris-table">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Department Head</th>
                    <th>Administrative Officer</th>
                    <th>Status</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $deptName = $row['department']->Dept_name;
                        $initials = $row['head_name'] ? strtoupper(substr($row['head_name'], 0, 1)) : null;
                        $aoInitials = $row['ao_name'] ? strtoupper(substr($row['ao_name'], 0, 1)) : null;
                    @endphp
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:.7rem;">
                                <span class="access-dept-icon"><i class="fas fa-building"></i></span>
                                <div>
                                    <div class="access-dept-name">{{ $deptName }}</div>
                                    @if ($row['department']->DeptCode)
                                        <div class="access-dept-code">{{ $row['department']->DeptCode }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td>
                            @if ($row['head_name'])
                                <div class="access-officer">
                                    <span class="access-officer-avatar">{{ $initials }}</span>
                                    <span>{{ $row['head_name'] }}</span>
                                </div>
                            @else
                                <span class="access-officer-empty">Unassigned</span>
                            @endif
                        </td>
                        <td>
                            @if ($row['ao_name'])
                                <div class="access-officer">
                                    <span class="access-officer-avatar">{{ $aoInitials }}</span>
                                    <span>{{ $row['ao_name'] }}</span>
                                </div>
                            @else
                                <span class="access-officer-empty">Unassigned</span>
                            @endif
                        </td>
                        <td>
                            @if ($row['granted'])
                                <span class="access-badge-granted"><i class="fas fa-check-circle"></i> Granted</span>
                            @else
                                <span class="access-badge-none"><i class="fas fa-minus-circle"></i> Not granted</span>
                            @endif
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            @if ($row['granted'])
                                <form method="POST" action="{{ route('attendance.shift-access.revoke', $row['department']) }}"
                                      class="access-confirm-form" data-action="revoke" data-name="{{ $deptName }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="hris-btn hris-btn-danger hris-btn-sm">
                                        <i class="fas fa-ban"></i> Revoke
                                    </button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('attendance.shift-access.grant', $row['department']) }}"
                                      class="access-confirm-form" data-action="grant" data-name="{{ $deptName }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm">
                                        <i class="fas fa-key"></i> Grant
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">
                            <div class="hris-empty-state">
                                <div class="hris-empty-state-icon"><i class="fas fa-building"></i></div>
                                <div class="hris-empty-state-title">No Departments Found</div>
                                <p class="hris-empty-state-text">
                                    @if ($search !== '')
                                        No departments match your search. Try adjusting or
                                        <a href="{{ route('attendance.shift-access.index') }}" style="color:#ea580c;">clearing it</a>.
                                    @else
                                        No departments are on file yet.
                                    @endif
                                </p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="padding:.75rem 1.25rem;">
        {{ $rows->links() }}
    </div>
</div>
@endsection

@section('page_scripts')
<script>
document.querySelectorAll('.access-confirm-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = form.dataset.name || 'this department';
        var isGrant = form.dataset.action === 'grant';
        Swal.fire({
            icon: isGrant ? 'question' : 'warning',
            title: isGrant ? 'Grant shift access?' : 'Revoke shift access?',
            html: isGrant
                ? 'Give <b>' + name + '</b>\'s Department Head / Administrative Officer access to Shift Templates, Shift Assignment, and Shift Schedule.'
                : 'Remove <b>' + name + '</b>\'s access to the Shift Management screens? Their Department Head / Administrative Officer will immediately lose access.',
            showCancelButton: true,
            confirmButtonText: isGrant ? 'Yes, grant access' : 'Yes, revoke access',
            confirmButtonColor: isGrant ? '#2563eb' : '#b45309',
            cancelButtonColor: '#6b7280',
        }).then(function (res) { if (res.isConfirmed) form.submit(); });
    });
});

@if (session('access_status'))
    Swal.fire({ icon: 'success', title: 'Done', text: @json(session('access_status')), confirmButtonColor: '#3b82f6' });
@endif
</script>
@endsection
