@extends('dashboards.layout', [
    'title'    => 'Frontline Personnel',
    'subtitle' => 'Mark frontline/essential departments and employees who must keep reporting during every declared work suspension.',
])

@section('page_head')
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<style>
.frontline-icon {
    display:inline-flex; align-items:center; justify-content:center;
    width:2.25rem; height:2.25rem; border-radius:.55rem; flex:0 0 auto;
    background:#fef2f2; color:#b91c1c; font-size:.9rem;
}
.frontline-name { font-weight:600; color:#0f172a; }
.frontline-sub { font-size:.75rem; color:#94a3b8; }
.frontline-badge-on {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.3rem .7rem; border-radius:9999px; font-size:.72rem; font-weight:600;
    background:#fee2e2; color:#991b1b; border:1px solid #fca5a5;
}
.frontline-badge-off {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.3rem .7rem; border-radius:9999px; font-size:.72rem; font-weight:600;
    background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0;
}
</style>
@endsection

@section('content')

<div style="display:flex;align-items:flex-start;gap:.6rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:.5rem;padding:.75rem 1rem;margin-bottom:1.25rem;">
    <i class="fas fa-circle-info" style="color:#2563eb;font-size:.9rem;margin-top:.15rem;"></i>
    <span style="font-size:.85rem;color:#1e40af;line-height:1.5;">
        A department or employee marked frontline/essential is exempt from every current and future work suspension —
        no re-selecting needed each time one is declared. See
        <a href="{{ route('attendance.work-suspensions.index') }}" style="color:#1d4ed8;font-weight:600;">Work Suspensions</a>
        to declare a suspension.
    </span>
</div>

{{-- ═══════════════════════════════════════════════════════════════
     Departments
════════════════════════════════════════════════════════════════ --}}
<div class="hris-table-card" style="margin-bottom:1.5rem;">
    <div style="padding:.9rem 1.5rem;border-bottom:1px solid #e2e8f0;">
        <div style="font-weight:700;font-size:.95rem;color:#0f172a;">
            <i class="fas fa-building" style="color:#b91c1c;margin-right:.4rem;"></i>Frontline Departments
        </div>
    </div>

    <form method="GET" action="{{ route('attendance.frontline-personnel.index') }}">
        @if ($empSearch !== '')
            <input type="hidden" name="emp_search" value="{{ $empSearch }}">
        @endif
        <div class="hris-table-filters hris-filters-sticky">
            <div class="hris-filter-left">
                <div class="hris-filter-group">
                    <label class="hris-filter-label">Search</label>
                    <input type="text" name="dept_search" value="{{ $deptSearch }}" placeholder="Department name or code"
                           class="hris-filter-select" style="min-width:14rem;">
                </div>
            </div>
            <div style="display:flex;gap:.5rem;align-items:flex-end;">
                <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm">
                    <i class="fas fa-search"></i> Search
                </button>
                @if ($deptSearch !== '')
                    <a href="{{ route('attendance.frontline-personnel.index', ['emp_search' => $empSearch]) }}" class="hris-btn hris-btn-secondary hris-btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div class="hris-table-wrapper">
        <table class="hris-table">
            <thead>
                <tr>
                    <th>Department</th>
                    <th>Status</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($departments as $dept)
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:.7rem;">
                                <span class="frontline-icon"><i class="fas fa-building"></i></span>
                                <div>
                                    <div class="frontline-name">{{ $dept->Dept_name }}</div>
                                    @if ($dept->DeptCode)
                                        <div class="frontline-sub">{{ $dept->DeptCode }}</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td>
                            @if ($dept->is_frontline)
                                <span class="frontline-badge-on"><i class="fas fa-shield-heart"></i> Frontline</span>
                            @else
                                <span class="frontline-badge-off"><i class="fas fa-minus-circle"></i> Not marked</span>
                            @endif
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <form method="POST" action="{{ route('attendance.frontline-personnel.departments.toggle', $dept) }}"
                                  class="frontline-confirm-form" data-action="{{ $dept->is_frontline ? 'unmark' : 'mark' }}"
                                  data-name="{{ $dept->Dept_name }}" style="display:inline;">
                                @csrf
                                @method('PUT')
                                @if ($dept->is_frontline)
                                    <button type="submit" class="hris-btn hris-btn-danger hris-btn-sm">
                                        <i class="fas fa-ban"></i> Unmark
                                    </button>
                                @else
                                    <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm">
                                        <i class="fas fa-shield-heart"></i> Mark Frontline
                                    </button>
                                @endif
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">
                            <div class="hris-empty-state">
                                <div class="hris-empty-state-icon"><i class="fas fa-building"></i></div>
                                <div class="hris-empty-state-title">No Departments Found</div>
                                <p class="hris-empty-state-text">
                                    @if ($deptSearch !== '')
                                        No departments match your search.
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
        {{ $departments->links() }}
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════
     Individual Employees
════════════════════════════════════════════════════════════════ --}}
<div class="hris-table-card">
    <div style="padding:.9rem 1.5rem;border-bottom:1px solid #e2e8f0;">
        <div style="font-weight:700;font-size:.95rem;color:#0f172a;">
            <i class="fas fa-user-shield" style="color:#b91c1c;margin-right:.4rem;"></i>Frontline Employees
        </div>
        <div style="font-size:.78rem;color:#64748b;margin-top:.15rem;">
            For essential personnel outside an otherwise non-frontline department.
        </div>
    </div>

    <form method="GET" action="{{ route('attendance.frontline-personnel.index') }}">
        @if ($deptSearch !== '')
            <input type="hidden" name="dept_search" value="{{ $deptSearch }}">
        @endif
        <div class="hris-table-filters hris-filters-sticky">
            <div class="hris-filter-left">
                <div class="hris-filter-group">
                    <label class="hris-filter-label">Search</label>
                    <input type="text" name="emp_search" value="{{ $empSearch }}" placeholder="Employee name"
                           class="hris-filter-select" style="min-width:14rem;">
                </div>
            </div>
            <div style="display:flex;gap:.5rem;align-items:flex-end;">
                <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm">
                    <i class="fas fa-search"></i> Search
                </button>
                @if ($empSearch !== '')
                    <a href="{{ route('attendance.frontline-personnel.index', ['dept_search' => $deptSearch]) }}" class="hris-btn hris-btn-secondary hris-btn-sm">
                        <i class="fas fa-times"></i> Clear
                    </a>
                @endif
            </div>
        </div>
    </form>

    <div class="hris-table-wrapper">
        <table class="hris-table">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $emp)
                    <tr>
                        <td>
                            <span class="frontline-name">{{ $emp->last_name }}, {{ $emp->first_name }}</span>
                        </td>
                        <td>
                            <span class="frontline-sub">{{ $emp->department?->Dept_name ?? '—' }}</span>
                        </td>
                        <td>
                            @if ($emp->is_frontline)
                                <span class="frontline-badge-on"><i class="fas fa-shield-heart"></i> Frontline</span>
                            @else
                                <span class="frontline-badge-off"><i class="fas fa-minus-circle"></i> Not marked</span>
                            @endif
                        </td>
                        <td style="text-align:right;white-space:nowrap;">
                            <form method="POST" action="{{ route('attendance.frontline-personnel.employees.toggle', $emp) }}"
                                  class="frontline-confirm-form" data-action="{{ $emp->is_frontline ? 'unmark' : 'mark' }}"
                                  data-name="{{ trim($emp->first_name.' '.$emp->last_name) }}" style="display:inline;">
                                @csrf
                                @method('PUT')
                                @if ($emp->is_frontline)
                                    <button type="submit" class="hris-btn hris-btn-danger hris-btn-sm">
                                        <i class="fas fa-ban"></i> Unmark
                                    </button>
                                @else
                                    <button type="submit" class="hris-btn hris-btn-primary hris-btn-sm">
                                        <i class="fas fa-shield-heart"></i> Mark Frontline
                                    </button>
                                @endif
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <div class="hris-empty-state">
                                <div class="hris-empty-state-icon"><i class="fas fa-user-shield"></i></div>
                                <div class="hris-empty-state-title">No Employees Found</div>
                                <p class="hris-empty-state-text">
                                    @if ($empSearch !== '')
                                        No employees match your search.
                                    @else
                                        No employees are on file yet.
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
        {{ $employees->links() }}
    </div>
</div>
@endsection

@section('page_scripts')
<script>
document.querySelectorAll('.frontline-confirm-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = form.dataset.name || 'this record';
        var isMark = form.dataset.action === 'mark';
        Swal.fire({
            icon: isMark ? 'question' : 'warning',
            title: isMark ? 'Mark as frontline?' : 'Unmark as frontline?',
            html: isMark
                ? '<b>' + name + '</b> will be exempt from every current and future work suspension and must keep reporting normally.'
                : '<b>' + name + '</b> will no longer be automatically exempt from work suspensions.',
            showCancelButton: true,
            confirmButtonText: isMark ? 'Yes, mark frontline' : 'Yes, unmark',
            confirmButtonColor: isMark ? '#b91c1c' : '#6b7280',
            cancelButtonColor: '#6b7280',
        }).then(function (res) { if (res.isConfirmed) form.submit(); });
    });
});

@if (session('frontline_status'))
    Swal.fire({ icon: 'success', title: 'Done', text: @json(session('frontline_status')), confirmButtonColor: '#3b82f6' });
@endif
</script>
@endsection
