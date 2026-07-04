@extends('dashboards.layout', [
    'title'    => 'Shift Assignment',
    'subtitle' => 'Assign a work-shift template to each employee. Unassigned employees follow the standard day shift.',
])

@section('page_head')
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<style>
/* ── Toolbar ── */
.sched-toolbar { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between; margin:0 0 1rem; }

/* ── Help banner ── */
.sched-help {
    display:flex; gap:.75rem; align-items:flex-start;
    background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a;
    border-radius:.6rem; padding:.75rem 1rem; margin:0 0 1rem; font-size:.82rem; line-height:1.45;
}
.sched-help svg { flex:0 0 auto; margin-top:.1rem; }
.sched-help b { font-weight:700; }

/* ── Inputs ── */
.sched-shift-select { padding:.4rem .55rem; border:1px solid #cbd5e1; border-radius:.4rem; font-size:.82rem; min-width:13rem; background:#fff; }

/* ── Badges ── */
.sched-badge {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.25rem .6rem; border-radius:9999px;
    font-size:.72rem; font-weight:600;
}
.sched-badge-exempt { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; }
.sched-badge-default { background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; }

/* ── Action buttons ── */
.sched-actions { display:flex; gap:.5rem; align-items:center; justify-content:flex-end; flex-wrap:wrap; }
.sched-btn {
    display:inline-flex; align-items:center; gap:.4rem; white-space:nowrap;
    padding:.4rem .7rem; border-radius:.45rem; font-size:.78rem; font-weight:600;
    border:1px solid transparent; cursor:pointer; transition:filter .12s ease, background .12s ease;
}
.sched-btn:hover { filter:brightness(.96); }
.sched-btn svg { flex:0 0 auto; }
.sched-btn-save    { background:#2563eb; color:#fff; }
.sched-btn-exempt  { background:#fff; color:#b45309; border-color:#fcd34d; }
.sched-btn-exempt:hover  { background:#fffbeb; }
.sched-btn-restore { background:#fff; color:#047857; border-color:#6ee7b7; }
.sched-btn-restore:hover { background:#ecfdf5; }

/* ── Exempt row tint ── */
.sched-row-exempt > td { background:#fffdf5; }

.sched-table td { vertical-align:middle; }
.sched-emp-name { font-weight:600; color:#1f2937; }
</style>
@endsection

@section('content')

<div class="sched-toolbar">
    <a href="{{ route('attendance.shifts') }}" class="hris-btn">⚙ Manage Shift Templates →</a>
</div>

<div class="sched-help">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    <span>
        Assign each employee a <b>work shift</b>, or mark them <b>exempt from DTR</b> if they don't use the biometric clock
        (e.g. officials or field staff). Exempt employees are skipped by the biometric import and excluded from Form&nbsp;48.
        Use the <b>View</b> filter to switch between active and exempt employees.
    </span>
</div>

<div class="hris-table-card" id="sched-list-card">

    <div class="hris-table-filters hris-filters-sticky">
        <form method="GET" action="{{ route('attendance.schedules') }}" class="hris-filter-left" style="flex-wrap:wrap;gap:.6rem;align-items:flex-end;">
            <div>
                <label class="hris-filter-label" for="search">Search</label>
                <input type="text" name="search" id="search" value="{{ $search }}" placeholder="Name or employee no."
                       class="hris-filter-select" style="min-width:14rem;">
            </div>
            <div>
                <label class="hris-filter-label" for="dept_id">Department</label>
                @if (is_null($lockedDepartments))
                    <select name="dept_id" id="dept_id" class="hris-filter-select" onchange="this.form.submit()">
                        <option value="">All departments</option>
                        @foreach ($departments as $dept)
                            <option value="{{ $dept->Dept_id }}" @selected($deptId === (int) $dept->Dept_id)>{{ $dept->Dept_name }}</option>
                        @endforeach
                    </select>
                @elseif ($lockedDepartments->count() > 1)
                    <select name="dept_id" id="dept_id" class="hris-filter-select" onchange="this.form.submit()">
                        <option value="">All my departments</option>
                        @foreach ($lockedDepartments as $dept)
                            <option value="{{ $dept->Dept_id }}" @selected($deptId === (int) $dept->Dept_id)>{{ $dept->Dept_name }}</option>
                        @endforeach
                    </select>
                @else
                    <input type="hidden" name="dept_id" value="{{ $lockedDepartments->first()->Dept_id ?? '' }}">
                    <span class="hris-filter-select" style="display:inline-block;background:#f1f5f9;color:#475569;">
                        {{ $lockedDepartments->first()->Dept_name ?? 'Your Department' }}
                    </span>
                @endif
            </div>
            <div>
                <label class="hris-filter-label" for="shift_id">Shift</label>
                <select name="shift_id" id="shift_id" class="hris-filter-select" onchange="this.form.submit()">
                    <option value="">All shifts</option>
                    @foreach ($shifts as $s)
                        <option value="{{ $s->id }}" @selected($shiftId === (int) $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="hris-filter-label" for="show_exempt">View</label>
                <select name="show_exempt" id="show_exempt" class="hris-filter-select" onchange="this.form.submit()">
                    <option value="0" @selected(! $showExempt)>✔ Active employees</option>
                    <option value="1" @selected($showExempt)>⊘ Exempt from DTR</option>
                </select>
            </div>
            <div>
                <button type="submit" class="hris-btn hris-btn-primary">Search</button>
            </div>
        </form>
    </div>

    <div style="overflow-x:auto;">
        <table class="hris-table sched-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>{{ $showExempt ? 'Status' : 'Assigned Shift' }}</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $emp)
                    @php
                        $fid = 'sched-form-'.$emp->id;
                        $empName = trim($emp->last_name.', '.$emp->first_name);
                    @endphp
                    <tr class="{{ $emp->dtr_exempt ? 'sched-row-exempt' : '' }}">
                        <td><span class="sched-emp-name">{{ $empName }}</span></td>
                        <td>
                            @if ($emp->dtr_exempt)
                                <span class="sched-badge sched-badge-exempt">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.9" y1="4.9" x2="19.1" y2="19.1"/></svg>
                                    Exempt from DTR
                                </span>
                            @else
                                <select form="{{ $fid }}" name="shift_id" class="sched-shift-select">
                                    <option value="">Standard Day (default)</option>
                                    @foreach ($shifts as $s)
                                        <option value="{{ $s->id }}" @selected((int) $emp->shift_id === (int) $s->id)>{{ $s->name }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </td>
                        <td>
                            <div class="sched-actions">
                                @unless ($emp->dtr_exempt)
                                    <form id="{{ $fid }}" method="POST" action="{{ route('attendance.schedules.update', $emp) }}">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="sched-btn sched-btn-save" title="Save the selected shift for this employee">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                                            Save Shift
                                        </button>
                                    </form>
                                @endunless
                                <form method="POST" action="{{ route('attendance.schedules.exempt', $emp) }}" class="sched-exempt-form"
                                      data-name="{{ $empName }}" data-exempt="{{ $emp->dtr_exempt ? '1' : '0' }}">
                                    @csrf
                                    @method('PUT')
                                    @if ($emp->dtr_exempt)
                                        <button type="submit" class="sched-btn sched-btn-restore" title="Put this employee back on biometric/DTR tracking">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                                            Restore to DTR
                                        </button>
                                    @else
                                        <button type="submit" class="sched-btn sched-btn-exempt" title="Exempt this employee from biometric/DTR (clears their shift)">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.9" y1="4.9" x2="19.1" y2="19.1"/></svg>
                                            Exempt from DTR
                                        </button>
                                    @endif
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" style="text-align:center;color:#94a3b8;padding:2rem;">
                        {{ $showExempt ? 'No exempt employees.' : 'No employees found.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="pagination-wrap" style="padding:.75rem 1.25rem;">
        {{ $employees->links() }}
    </div>
</div>
@endsection

@section('page_scripts')
<script>
// Confirm before toggling exemption - explain the consequence in plain language.
document.querySelectorAll('.sched-exempt-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name      = form.dataset.name || 'this employee';
        var isExempt  = form.dataset.exempt === '1';
        Swal.fire({
            icon: isExempt ? 'question' : 'warning',
            title: isExempt ? 'Restore to DTR?' : 'Exempt from DTR?',
            html: isExempt
                ? '<b>' + name + '</b> will be tracked by the biometric clock again and appear in DTR / Form&nbsp;48 exports.'
                : '<b>' + name + '</b> will be skipped by the biometric import and excluded from DTR / Form&nbsp;48.<br><small>Any assigned shift will be removed.</small>',
            showCancelButton: true,
            confirmButtonText: isExempt ? 'Yes, restore' : 'Yes, exempt',
            confirmButtonColor: isExempt ? '#047857' : '#b45309',
            cancelButtonColor: '#6b7280',
        }).then(function (res) { if (res.isConfirmed) form.submit(); });
    });
});

@if (session('schedule_status'))
    Swal.fire({ icon: 'success', title: 'Saved', text: @json(session('schedule_status')), confirmButtonColor: '#3b82f6' });
@endif
@if ($errors->any())
    Swal.fire({ icon: 'error', title: 'Could not save', text: @json($errors->first()), confirmButtonColor: '#3b82f6' });
@endif
</script>
@endsection
