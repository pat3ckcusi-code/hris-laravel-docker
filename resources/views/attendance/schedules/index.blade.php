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
.sched-btn-exempt  { background:#fff; color:#b45309; border-color:#fcd34d; }
.sched-btn-exempt:hover  { background:#fffbeb; }
.sched-btn-restore { background:#fff; color:#047857; border-color:#6ee7b7; }
.sched-btn-restore:hover { background:#ecfdf5; }

/* ── Exempt row tint ── */
.sched-row-exempt > td { background:#fffdf5; }

.sched-table td { vertical-align:middle; }
.sched-emp-name { font-weight:600; color:#1f2937; }
.sched-resolved-link { display:block; font-size:.7rem; font-weight:600; color:#2563eb; text-decoration:none; margin-top:.15rem; }
.sched-resolved-link:hover { text-decoration:underline; }

/* ── Bulk assign bar ── */
.sched-bulk-bar {
    display:flex; flex-wrap:wrap; gap:.75rem; align-items:flex-end;
    background:#f8fafc; border:1px solid #e2e8f0; border-radius:.6rem;
    padding:.75rem 1rem; margin:0 0 1rem;
}
.sched-bulk-bar label { display:block; font-size:.72rem; color:#475569; margin-bottom:.2rem; }

/* ── Days-of-week checkboxes ── */
.sched-days-group { display:flex; gap:.5rem; flex-wrap:wrap; }
.sched-day-chip { display:flex; align-items:center; gap:.25rem; font-size:.75rem; color:#475569; font-weight:500; cursor:pointer; }
.sched-days-hint { font-size:.72rem; color:#94a3b8; margin:.35rem 0 0; flex-basis:100%; }

/* ── Advanced: split into concurrent shifts ── */
.sched-advanced-split { margin-top:.2rem; flex-basis:100%; }
.sched-advanced-split summary {
    cursor:pointer; font-size:.72rem; font-weight:600; color:#6b7280; list-style:none;
}
.sched-advanced-split summary::-webkit-details-marker { display:none; }
.sched-advanced-split > .sched-days-group,
.sched-advanced-split > p.sched-days-hint { margin-top:.4rem; }

/* ── Per-employee shift list ── */
.sched-shift-empty { font-size:.82rem; color:#94a3b8; }
.sched-shift-list { list-style:none; margin:0; padding:0; font-size:.82rem; color:#1f2937; }
.sched-shift-list li { display:flex; align-items:center; gap:.4rem; line-height:1.6; }
.sched-shift-dates { font-size:.74rem; color:#94a3b8; font-weight:400; }
.sched-override-warning {
    display:inline-block; margin-left:.4rem; font-size:.7rem; font-weight:600;
    color:#b45309; background:#fffbeb; border:1px solid #fde68a; border-radius:.3rem;
    padding:.05rem .4rem; cursor:pointer; text-decoration:none;
}
.sched-override-warning:hover { background:#fef3c7; }
.sched-remove-shift-form { display:inline-flex; }
.sched-remove-btn {
    border:none; background:none; color:#b91c1c; font-size:.95rem; line-height:1;
    cursor:pointer; padding:0 .15rem; border-radius:.25rem;
}
.sched-remove-btn:hover { background:#fef2f2; }

/* ── Add Shift panel ── */
.sched-add-shift { margin-top:.4rem; }
.sched-add-shift summary {
    cursor:pointer; font-size:.75rem; font-weight:600; color:#2563eb; list-style:none;
}
.sched-add-shift summary::-webkit-details-marker { display:none; }
.sched-add-shift-form {
    display:flex; flex-direction:column; gap:.4rem; margin-top:.5rem;
    padding:.6rem; border:1px dashed #cbd5e1; border-radius:.5rem; background:#f8fafc; min-width:14rem;
}
.sched-add-dates { display:flex; gap:.4rem; }
.sched-add-dates input { flex:1; padding:.35rem .45rem; border:1px solid #cbd5e1; border-radius:.35rem; font-size:.78rem; }
.sched-add-submit { align-self:flex-start; padding:.35rem .7rem !important; font-size:.78rem !important; }

/* ── Per-row Edit panel ── */
.sched-edit-shift { display:inline-block; }
.sched-edit-shift summary {
    cursor:pointer; font-size:.72rem; font-weight:600; color:#0369a1; list-style:none;
}
.sched-edit-shift summary::-webkit-details-marker { display:none; }
.sched-edit-shift-form {
    display:flex; flex-direction:column; gap:.4rem; margin-top:.5rem;
    padding:.6rem; border:1px dashed #7dd3fc; border-radius:.5rem; background:#f0f9ff; min-width:14rem;
}
.sched-edit-submit { align-self:flex-start; padding:.35rem .7rem !important; font-size:.78rem !important; background:#0369a1 !important; border-color:#0369a1 !important; }

/* ── History (expired assignments) panel ── */
.sched-history { margin-top:.5rem; }
.sched-history summary {
    cursor:pointer; font-size:.75rem; font-weight:600; color:#6b7280; list-style:none;
}
.sched-history summary::-webkit-details-marker { display:none; }
.sched-history-list { list-style:none; margin:.4rem 0 0; padding:0; font-size:.8rem; color:#6b7280; }
.sched-history-list li { display:flex; align-items:center; gap:.4rem; line-height:1.7; }
.sched-history-link { display:inline-block; margin-top:.4rem; font-size:.75rem; font-weight:600; color:#2563eb; }
.sched-history-link:hover { text-decoration:underline; }

/* ── Select-all-matching (across pages) ── */
.sched-select-all-matching {
    border:none; background:none; padding:0; margin:0 0 .5rem;
    font-size:.78rem; font-weight:600; color:#2563eb; cursor:pointer; text-align:left;
}
.sched-select-all-matching:hover { text-decoration:underline; }
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

@unless ($showExempt)
<form id="bulk-assign-form" method="POST" action="{{ route('attendance.schedules.bulk-assign') }}" class="sched-bulk-bar">
    @csrf
    @method('PUT')
    {{-- Mirror the filters currently applied to this list, so
         select_all_matching=1 (set by JS below) always targets exactly what
         "Select all N matching employees" quoted the user, even if they've
         edited the search box without re-submitting the filter form. --}}
    <input type="hidden" name="search" value="{{ $search }}">
    <input type="hidden" name="dept_id" value="{{ $deptId }}">
    <input type="hidden" name="shift_id" value="{{ $shiftId }}">
    <input type="hidden" name="employee_type" value="{{ $employeeType }}">
    <input type="hidden" name="select_all_matching" id="select_all_matching" value="0">
    <div>
        <label for="assign_shift_id">Bulk assign shift</label>
        <select name="assign_shift_id" id="assign_shift_id" class="sched-shift-select">
            <option value="">Standard Day (default)</option>
            @foreach ($shifts as $s)
                <option value="{{ $s->id }}">{{ $s->name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="bulk_effective_from">Effective from (required)</label>
        <input type="date" name="effective_from" id="bulk_effective_from" class="sched-shift-select" required>
    </div>
    <div>
        <label for="bulk_effective_until">Effective until (required)</label>
        <input type="date" name="effective_until" id="bulk_effective_until" class="sched-shift-select" required>
    </div>
    <div>
        <label>Work Days</label>
        <div class="sched-days-group sched-workdays-group">
            @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow => $label)
                <label class="sched-day-chip">
                    <input type="checkbox" name="work_days[]" value="{{ $dow }}" {{ in_array($dow, [1, 2, 3, 4, 5]) ? 'checked' : '' }}> {{ $label }}
                </label>
            @endforeach
        </div>
    </div>
    <div>
        <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;">
            <input type="checkbox" name="no_break" value="1" style="width:auto;">
            <span>No Break (2-punch)</span>
        </label>
    </div>
    <div>
        <button type="submit" class="hris-btn hris-btn-primary" id="bulk-assign-submit" disabled>
            Assign to selected (<span id="bulk-assign-count">0</span>)
        </button>
    </div>
    <p class="sched-days-hint">
        <b>Work Days</b> sets which days of the week this assignment is scheduled to work (defaults to Mon-Fri).
    </p>
    <details class="sched-advanced-split">
        <summary>Advanced: split into concurrent shifts</summary>
        <div class="sched-days-group">
            @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow => $label)
                <label class="sched-day-chip">
                    <input type="checkbox" name="days_of_week[]" value="{{ $dow }}"> {{ $label }}
                </label>
            @endforeach
        </div>
        <p class="sched-days-hint">
            Only needed to give an employee two concurrent shifts on different days - submit this form once
            per set of days (e.g. Mon/Wed/Fri for one shift, then Tue/Thu for another). While open, Work Days
            above follows this selection, since a day this covers can never be worked under a different pattern.
        </p>
    </details>
</form>
@endunless

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
                <label class="hris-filter-label" for="employee_type">Employee Type</label>
                <select name="employee_type" id="employee_type" class="hris-filter-select" onchange="this.form.submit()">
                    <option value="">All Types</option>
                    <option value="permanent" @selected($employeeType === 'permanent')>Permanent</option>
                    <option value="elected officials" @selected($employeeType === 'elected officials')>Elected Officials</option>
                    <option value="co-terminus" @selected($employeeType === 'co-terminus')>Co-Terminus</option>
                    <option value="casual" @selected($employeeType === 'casual')>Casual</option>
                    <option value="job orders" @selected($employeeType === 'job orders')>Job Orders</option>
                    <option value="contractual" @selected($employeeType === 'contractual')>Contractual</option>
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

    @unless ($showExempt)
        <button type="button" id="select-all-matching-toggle" class="sched-select-all-matching"
                data-total="{{ $employees->total() }}" style="margin:0 1.25rem .5rem;">
            Select all {{ $employees->total() }} matching employees
        </button>
    @endunless

    <div style="overflow-x:auto;">
        <table class="hris-table sched-table" style="width:100%;">
            <thead>
                <tr>
                    @unless ($showExempt)
                        <th style="width:2rem;"><input type="checkbox" id="sched-select-all" style="cursor:pointer;"></th>
                    @endunless
                    <th>Employee</th>
                    <th>{{ $showExempt ? 'Status' : 'Assigned Shift' }}</th>
                    <th style="text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($employees as $emp)
                    @php
                        $empName = trim($emp->last_name.', '.$emp->first_name);
                    @endphp
                    <tr class="{{ $emp->dtr_exempt ? 'sched-row-exempt' : '' }}">
                        @unless ($showExempt)
                            <td>
                                <input type="checkbox" form="bulk-assign-form" name="user_ids[]" value="{{ $emp->id }}" class="sched-row-select" style="cursor:pointer;">
                            </td>
                        @endunless
                        <td>
                            <span class="sched-emp-name">{{ $empName }}</span>
                            <a href="{{ route('attendance.schedules.resolved', $emp) }}" class="sched-resolved-link">View resolved schedule</a>
                        </td>
                        <td>
                            @if ($emp->dtr_exempt)
                                <span class="sched-badge sched-badge-exempt">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.9" y1="4.9" x2="19.1" y2="19.1"/></svg>
                                    Exempt from DTR
                                </span>
                            @else
                                @php $empAssignments = $activeAssignments->get($emp->id, collect()); @endphp
                                @php $empExpiredPreview = $expiredAssignments->get($emp->id, collect()); @endphp
                                @php $empExpiredTotal = $expiredCounts[$emp->id] ?? 0; @endphp
                                @php $existingLabel = $empAssignments->map(fn ($r) => ($r->shift?->name ?? 'Standard Day').' - '.$r->workDaysLabel())->implode('|'); @endphp

                                @php $allStandardDay = $empAssignments->isNotEmpty() && $empAssignments->every(fn ($r) => $r->shift_id === null); @endphp
                                @if ($empAssignments->isEmpty() || $allStandardDay)
                                    <span class="sched-shift-empty">Standard Day (default)</span>
                                @else
                                    <ul class="sched-shift-list">
                                        @foreach ($empAssignments as $row)
                                            @php
                                                $rowShiftLabel = $row->shift?->name ?? 'Standard Day';
                                                $rowDaysLabel = $row->workDaysLabel();
                                                $rowDateLabel = match (true) {
                                                    $row->isSuperseded() => 'superseded before it took effect',
                                                    $row->effective_until !== null => $row->effective_from->toFormattedDateString().' – '.$row->effective_until->toFormattedDateString(),
                                                    default => 'from '.$row->effective_from->toFormattedDateString(),
                                                };
                                                // Only scope the removal to this row's own days when other
                                                // entries remain (a day-scoped combo) - otherwise this is the
                                                // employee's one and only assignment, so removing it should
                                                // fully clear back to plain Standard Day (default), not leave
                                                // behind a day-scoped "Standard Day" row.
                                                $keepDayScope = $empAssignments->count() > 1 && $row->days_of_week;
                                                $conflict = $rowOverrides[$row->id] ?? null;
                                            @endphp
                                            <li>
                                                <span>{{ $rowShiftLabel }} - {{ $rowDaysLabel }} <span class="sched-shift-dates">({{ $rowDateLabel }})</span></span>
                                                @if ($conflict)
                                                    <a href="{{ $conflict['link'] }}" class="sched-override-warning" title="Overridden on the Shift Schedule page for: {{ $conflict['dates'] }}. That override wins over this assignment on those dates.">
                                                        &#9888; overridden on {{ $conflict['dates'] }}
                                                    </a>
                                                @endif
                                                <form method="POST" action="{{ route('attendance.schedules.update', $emp) }}"
                                                      class="sched-remove-shift-form" data-name="{{ $empName }}"
                                                      data-shift="{{ $rowShiftLabel }}" data-days="{{ $rowDaysLabel }}">
                                                    @csrf
                                                    @method('PUT')
                                                    <input type="hidden" name="form_type" value="remove">
                                                    <input type="hidden" name="shift_id" value="">
                                                    @if ($keepDayScope)
                                                        @foreach ($row->days_of_week as $d)
                                                            <input type="hidden" name="days_of_week[]" value="{{ $d }}">
                                                        @endforeach
                                                    @endif
                                                    <button type="submit" class="sched-remove-btn" title="Remove this shift">&times;</button>
                                                </form>
                                                @include('attendance.schedules._edit-shift-form', ['emp' => $emp, 'empName' => $empName, 'row' => $row, 'shifts' => $shifts])
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($empExpiredTotal > 0)
                                    <details class="sched-history">
                                        <summary>History ({{ $empExpiredPreview->count() < $empExpiredTotal ? "showing {$empExpiredPreview->count()} of {$empExpiredTotal}" : $empExpiredTotal }})</summary>
                                        <ul class="sched-history-list">
                                            @foreach ($empExpiredPreview as $row)
                                                @php
                                                    $rowShiftLabel = $row->shift?->name ?? 'Standard Day';
                                                    $rowDaysLabel = $row->workDaysLabel();
                                                    $rowDateLabel = $row->isSuperseded()
                                                        ? 'superseded before it took effect'
                                                        : $row->effective_from->toFormattedDateString().' – '.$row->effective_until->toFormattedDateString();
                                                @endphp
                                                <li>
                                                    <span>{{ $rowShiftLabel }} - {{ $rowDaysLabel }} <span class="sched-shift-dates">({{ $rowDateLabel }})</span></span>
                                                    @include('attendance.schedules._edit-shift-form', ['emp' => $emp, 'empName' => $empName, 'row' => $row, 'shifts' => $shifts])
                                                </li>
                                            @endforeach
                                        </ul>
                                        @if ($empExpiredTotal > $empExpiredPreview->count())
                                            <a href="{{ route('attendance.schedules.history', $emp) }}" class="sched-history-link">View full history ({{ $empExpiredTotal }}) &rarr;</a>
                                        @endif
                                    </details>
                                @endif

                                <details class="sched-add-shift">
                                    <summary>+ Add Shift</summary>
                                    <form method="POST" action="{{ route('attendance.schedules.update', $emp) }}"
                                          class="sched-add-shift-form" data-name="{{ $empName }}" data-existing="{{ $existingLabel }}">
                                        @csrf
                                        @method('PUT')
                                        <input type="hidden" name="form_type" value="add">
                                        <select name="shift_id" class="sched-shift-select">
                                            <option value="">Standard Day</option>
                                            @foreach ($shifts as $s)
                                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                                            @endforeach
                                        </select>
                                        <label style="display:block;font-size:.72rem;color:#475569;margin-top:.3rem;">Work Days</label>
                                        <div class="sched-days-group sched-workdays-group">
                                            @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow => $label)
                                                <label class="sched-day-chip">
                                                    <input type="checkbox" name="work_days[]" value="{{ $dow }}" {{ in_array($dow, [1, 2, 3, 4, 5]) ? 'checked' : '' }}> {{ $label }}
                                                </label>
                                            @endforeach
                                        </div>
                                        <label class="sched-day-chip">
                                            <input type="checkbox" name="no_break" value="1"> No Break (2-punch)
                                        </label>
                                        <details class="sched-advanced-split">
                                            <summary>Advanced: split into concurrent shifts</summary>
                                            <div class="sched-days-group">
                                                @foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow => $label)
                                                    <label class="sched-day-chip">
                                                        <input type="checkbox" name="days_of_week[]" value="{{ $dow }}"> {{ $label }}
                                                    </label>
                                                @endforeach
                                            </div>
                                            <p class="sched-days-hint">
                                                Only needed to give this employee a second, concurrent shift on
                                                different days - submit this form again for the other shift/days.
                                                While open, Work Days above follows this selection.
                                            </p>
                                        </details>
                                        <div class="sched-add-dates">
                                            <input type="date" name="effective_from" title="Effective from (required)" required>
                                            <input type="date" name="effective_until" title="Effective until (required)" required>
                                        </div>
                                        <button type="submit" class="hris-btn hris-btn-primary sched-add-submit">Add</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                        <td>
                            <div class="sched-actions">
                                @if ($canManageExemption)
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
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $showExempt ? 3 : 4 }}" style="text-align:center;color:#94a3b8;padding:2rem;">
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
// Mirrors the "Advanced: split into concurrent shifts" day selection onto
// this same form's Work Days picker and locks it while the disclosure is
// open, since ShiftAssignmentService::assign() always forces work_days to
// equal days_of_week whenever the latter is set - a day this row doesn't
// govern can never be "worked" under it, so letting Work Days show something
// different would just be lying about what gets stored. Closing the
// disclosure clears the split selection so nothing stray gets submitted from
// a panel the user only glanced at.
function bindAdvancedSplit(detailsEl) {
    if (!detailsEl) return;
    var form = detailsEl.closest('form');
    if (!form) return;
    var workDaysBoxes = Array.prototype.slice.call(form.querySelectorAll('.sched-workdays-group input[type=checkbox]'));
    var splitBoxes = Array.prototype.slice.call(detailsEl.querySelectorAll('input[type=checkbox]'));

    function mirror() {
        var checkedValues = splitBoxes.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
        workDaysBoxes.forEach(function (cb) { cb.checked = checkedValues.indexOf(cb.value) !== -1; });
    }

    function applyOpenState() {
        if (detailsEl.open) {
            mirror();
            workDaysBoxes.forEach(function (cb) { cb.disabled = true; });
        } else {
            splitBoxes.forEach(function (cb) { cb.checked = false; });
            workDaysBoxes.forEach(function (cb) { cb.disabled = false; });
        }
    }

    splitBoxes.forEach(function (cb) { cb.addEventListener('change', mirror); });
    detailsEl.addEventListener('toggle', applyOpenState);
    applyOpenState();
}

document.querySelectorAll('.sched-advanced-split').forEach(bindAdvancedSplit);

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

// Confirm before removing one of an employee's shifts.
document.querySelectorAll('.sched-remove-shift-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name  = form.dataset.name || 'this employee';
        var shift = form.dataset.shift || 'this shift';
        var days  = form.dataset.days || 'Every day';
        Swal.fire({
            icon: 'warning',
            title: 'Remove this shift?',
            html: 'Remove <b>' + shift + '</b> (' + days + ') from <b>' + name + '</b>?',
            showCancelButton: true,
            confirmButtonText: 'Yes, remove',
            confirmButtonColor: '#b91c1c',
            cancelButtonColor: '#6b7280',
        }).then(function (res) { if (res.isConfirmed) form.submit(); });
    });
});

// Confirm before adding a shift when the employee already has at least one -
// the new entry may replace any existing one whose days overlap (same
// truncation rule the bulk-assign bar already relies on).
document.querySelectorAll('.sched-add-shift-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var select = form.elements['shift_id'];

        var existingRaw = form.dataset.existing;
        if (!existingRaw) { form.submit(); return; } // nothing on file yet - submit normally.

        var name = form.dataset.name || 'This employee';
        var existing = existingRaw.split('|');
        var newLabel = select && select.selectedIndex >= 0 ? select.options[select.selectedIndex].text : 'Standard Day';

        Swal.fire({
            icon: 'warning',
            title: 'Add this shift?',
            html: '<b>' + name + '</b> currently has:<br>' + existing.join('<br>')
                + '<br><br>Adding <b>' + newLabel + '</b> may replace whichever of these overlap in days.',
            showCancelButton: true,
            confirmButtonText: 'Yes, add',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#6b7280',
        }).then(function (res) { if (res.isConfirmed) form.submit(); });
    });
});

// Confirm before saving a correction to an already-recorded assignment -
// this can retroactively change historical DTR/payroll figures once time
// records for that range are recomputed.
document.querySelectorAll('.sched-edit-shift-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name  = form.dataset.name || 'this employee';
        var dates = form.dataset.dates || 'this period';

        Swal.fire({
            icon: 'warning',
            title: 'Save this correction?',
            html: 'This corrects <b>' + name + '</b>&rsquo;s already-recorded assignment for <b>' + dates
                + '</b>. Existing time records in that range will be recomputed.',
            showCancelButton: true,
            confirmButtonText: 'Yes, save correction',
            confirmButtonColor: '#0369a1',
            cancelButtonColor: '#6b7280',
        }).then(function (res) { if (res.isConfirmed) form.submit(); });
    });
});

var bulkForm = document.getElementById('bulk-assign-form');
var rowCheckboxes = document.querySelectorAll('.sched-row-select');
var selectAllCb = document.getElementById('sched-select-all');
var submitBtn = document.getElementById('bulk-assign-submit');
var countEl = document.getElementById('bulk-assign-count');
var selectAllMatching = false;
var selectAllMatchingInput = document.getElementById('select_all_matching');
var selectAllMatchingToggle = document.getElementById('select-all-matching-toggle');

function stopSelectAllMatching() {
    if (!selectAllMatching) return;
    selectAllMatching = false;
    if (selectAllMatchingInput) selectAllMatchingInput.value = '0';
    rowCheckboxes.forEach(function (cb) { cb.disabled = false; });
    if (selectAllCb) selectAllCb.disabled = false;
}

function updateBulkAssignState() {
    if (selectAllMatching) {
        var total = selectAllMatchingToggle ? parseInt(selectAllMatchingToggle.dataset.total, 10) : 0;
        if (countEl) countEl.textContent = total;
        if (submitBtn) submitBtn.disabled = total === 0;
        if (selectAllCb) selectAllCb.checked = true;
        return;
    }
    var checked = document.querySelectorAll('.sched-row-select:checked').length;
    if (countEl) countEl.textContent = checked;
    if (submitBtn) submitBtn.disabled = checked === 0;
    if (selectAllCb) selectAllCb.checked = checked > 0 && checked === rowCheckboxes.length;
}

rowCheckboxes.forEach(function (cb) {
    cb.addEventListener('change', function () {
        stopSelectAllMatching();
        updateBulkAssignState();
    });
});
if (selectAllCb) {
    selectAllCb.addEventListener('change', function () {
        stopSelectAllMatching();
        rowCheckboxes.forEach(function (cb) { cb.checked = selectAllCb.checked; });
        updateBulkAssignState();
    });
}
if (selectAllMatchingToggle) {
    selectAllMatchingToggle.addEventListener('click', function () {
        selectAllMatching = true;
        if (selectAllMatchingInput) selectAllMatchingInput.value = '1';
        rowCheckboxes.forEach(function (cb) { cb.checked = true; cb.disabled = true; });
        if (selectAllCb) selectAllCb.disabled = true;
        updateBulkAssignState();
    });
}
updateBulkAssignState();

if (bulkForm) {
    bulkForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var select = document.getElementById('assign_shift_id');
        var count = selectAllMatching
            ? (selectAllMatchingToggle ? parseInt(selectAllMatchingToggle.dataset.total, 10) : 0)
            : document.querySelectorAll('.sched-row-select:checked').length;
        if (count === 0) return;

        var shiftLabel = select.options[select.selectedIndex].text;
        var from = document.getElementById('bulk_effective_from').value;
        var until = document.getElementById('bulk_effective_until').value;
        var windowText = until ? (' from <b>' + from + '</b> to <b>' + until + '</b>, reverting to Standard Day afterward')
            : (from ? (' starting <b>' + from + '</b>') : '');
        var dayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        var checkedDays = Array.prototype.slice.call(bulkForm.querySelectorAll('input[name="days_of_week[]"]:checked'))
            .map(function (cb) { return dayLabels[parseInt(cb.value, 10)]; });
        var daysText = checkedDays.length ? (' on <b>' + checkedDays.join('/') + '</b> only') : '';
        var checkedWorkDays = Array.prototype.slice.call(bulkForm.querySelectorAll('input[name="work_days[]"]:checked'))
            .map(function (cb) { return dayLabels[parseInt(cb.value, 10)]; });
        var workDaysText = checkedWorkDays.length ? (', Work Days <b>' + checkedWorkDays.join('/') + '</b>') : '';
        var noBreakText = bulkForm.elements['no_break'] && bulkForm.elements['no_break'].checked ? ', no break' : '';
        var targetText = selectAllMatching
            ? ('all <b>' + count + '</b> employees matching your current filters (across all pages)')
            : ('the <b>' + count + '</b> selected employee(s) (this page)');
        Swal.fire({
            icon: 'warning',
            title: 'Bulk-assign shift?',
            html: 'This will assign <b>' + shiftLabel + '</b> to ' + targetText + daysText + workDaysText + noBreakText + windowText + '.',
            showCancelButton: true,
            confirmButtonText: 'Yes, assign',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#6b7280',
        }).then(function (res) { if (res.isConfirmed) bulkForm.submit(); });
    });
}

@if (session('schedule_status'))
    Swal.fire({ icon: 'success', title: 'Saved', text: @json(session('schedule_status')), confirmButtonColor: '#3b82f6' });
@endif
@if (session('schedule_error'))
    Swal.fire({ icon: 'error', title: 'Action blocked', text: @json(session('schedule_error')), confirmButtonColor: '#3b82f6' });
@endif
@if ($errors->any())
    Swal.fire({ icon: 'error', title: 'Could not save', text: @json($errors->first()), confirmButtonColor: '#3b82f6' });
@endif
</script>
@endsection
