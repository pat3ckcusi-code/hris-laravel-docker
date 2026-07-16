@extends('dashboards.layout', [
    'title'    => 'Shift Schedule',
    'subtitle' => 'Set per-day shift assignments for employees in rotating or 24/7 departments.',
])

@section('page_head')
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<style>
/* ── Layout ─────────────────────────────────────────────── */
.ss-layout {
    display: grid;
    grid-template-columns: 22rem 1fr;
    gap: 1.25rem;
    align-items: start;
}

/* ── Toolbar ────────────────────────────────────────────── */
.ss-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    align-items: center;
    margin-bottom: 1.25rem;
    padding: 1rem 1.25rem;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: .75rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
}

.ss-toolbar-left  { display: flex; align-items: center; gap: .6rem; flex: 1; flex-wrap: wrap; min-width: 0; }
.ss-toolbar-right { display: flex; align-items: center; gap: .5rem; }
.ss-dept-form     { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }

.ss-dept-select {
    padding: .45rem .75rem;
    border: 1px solid #cbd5e1;
    border-radius: .4rem;
    font-size: .83rem;
    background: #f8fafc;
    color: #0f172a;
    min-width: 13rem;
    transition: border-color .15s;
}
.ss-dept-select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }

.ss-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    padding: .42rem .85rem;
    border: 1px solid #cbd5e1;
    border-radius: .4rem;
    font-size: .82rem;
    font-weight: 500;
    background: #fff;
    color: #374151;
    text-decoration: none;
    cursor: pointer;
    transition: background .15s, border-color .15s;
}
.ss-nav-btn:hover   { background: #f1f5f9; border-color: #94a3b8; }
.ss-nav-btn.active  { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }

.ss-week-label {
    font-size: .88rem;
    font-weight: 600;
    color: #0f172a;
    white-space: nowrap;
    padding: 0 .25rem;
}

/* ── Employee panel ─────────────────────────────────────── */
.ss-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: .75rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    overflow: hidden;
}

.ss-panel-header {
    padding: .85rem 1rem;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
}

.ss-panel-title {
    font-size: .78rem;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: .05em;
}

.ss-search {
    width: 100%;
    padding: .4rem .75rem .4rem 2.1rem;
    border: 1px solid #e2e8f0;
    border-radius: .35rem;
    font-size: .8rem;
    background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2394a3b8' stroke-width='2'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' d='M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z'/%3E%3C/svg%3E") .55rem center / 1rem no-repeat;
    color: #0f172a;
    transition: border-color .15s;
}
.ss-search:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.1); }

.ss-emp-list {
    max-height: 28rem;
    overflow-y: auto;
}

.ss-emp-row-wrap {
    display: flex;
    align-items: center;
    gap: .5rem;
    padding: 0 1rem;
    border-bottom: 1px solid #f1f5f9;
}
.ss-emp-row-wrap:last-child { border-bottom: none; }
.ss-emp-checkbox { cursor: pointer; flex-shrink: 0; }

.ss-emp-row {
    display: flex;
    align-items: center;
    gap: .65rem;
    padding: .6rem 0;
    flex: 1;
    min-width: 0;
    text-decoration: none;
    color: inherit;
    transition: background .12s;
    cursor: pointer;
}
.ss-emp-row:hover        { background: #eff6ff; }
.ss-emp-row.active       { background: #dbeafe; }

.ss-emp-avatar {
    width: 2.1rem;
    height: 2.1rem;
    border-radius: 50%;
    background: linear-gradient(135deg, #3b82f6, #6366f1);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .7rem;
    font-weight: 700;
    flex-shrink: 0;
    letter-spacing: .02em;
}
.ss-emp-row.active .ss-emp-avatar { background: linear-gradient(135deg, #1d4ed8, #4f46e5); }

.ss-emp-info { min-width: 0; }
.ss-emp-name { font-size: .82rem; font-weight: 500; color: #0f172a; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ss-emp-dept { font-size: .72rem; color: #94a3b8; }

.ss-emp-row.active .ss-emp-name { color: #1e40af; font-weight: 600; }

.ss-panel-footer {
    padding: .65rem 1rem;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
}

/* ── Week grid panel ────────────────────────────────────── */
.ss-week-panel {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: .75rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.06);
    overflow: hidden;
}

.ss-week-panel-header {
    padding: .9rem 1.25rem;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
}

.ss-emp-selected-name {
    font-size: .95rem;
    font-weight: 700;
    color: #0f172a;
}

.ss-emp-default-shift {
    font-size: .78rem;
    color: #6b7280;
    font-weight: 400;
}

.ss-multi-shift-list { list-style: none; margin: .3rem 0 0; padding: 0; font-size: .74rem; color: #6b7280; }
.ss-multi-shift-list li { line-height: 1.5; }
.ss-shift-dates { color: #94a3b8; }

.ss-week-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: .75rem;
    padding: 1.25rem;
}

/* ── Day cards ──────────────────────────────────────────── */
.ss-day-card {
    border: 1.5px solid #e2e8f0;
    border-radius: .6rem;
    padding: .75rem .7rem;
    background: #fff;
    transition: box-shadow .15s, border-color .15s;
    position: relative;
}
.ss-day-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.07); }

.ss-day-card.is-today {
    border-color: #3b82f6;
    box-shadow: 0 0 0 2px rgba(59,130,246,.15);
}

.ss-day-card.is-weekend { background: #fafafa; }

.ss-day-dow {
    font-size: .68rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: .06em;
    margin-bottom: .1rem;
}
.ss-day-card.is-today .ss-day-dow { color: #2563eb; }

.ss-day-num {
    font-size: 1.1rem;
    font-weight: 700;
    color: #1e293b;
    line-height: 1;
    margin-bottom: .55rem;
}
.ss-day-card.is-today .ss-day-num {
    color: #fff;
    background: #3b82f6;
    width: 1.7rem;
    height: 1.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    font-size: .85rem;
    margin-bottom: .55rem;
}

.ss-day-select {
    width: 100%;
    padding: .38rem .4rem;
    border: 1px solid #cbd5e1;
    border-radius: .35rem;
    font-size: .75rem;
    background: #fff;
    color: #1e293b;
    cursor: pointer;
    transition: border-color .15s, background .15s;
    appearance: auto;
}
.ss-day-select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 2px rgba(59,130,246,.1); }

/* Color states */
.ss-day-select.is-rest      { background: #fef2f2; color: #b91c1c; border-color: #fca5a5; }
.ss-day-select.is-field-work { background: #f0fdf4; color: #15803d; border-color: #86efac; }
.ss-day-select.is-standard  { background: #f1f5f9; color: #334155; border-color: #94a3b8; }
.ss-day-select.is-assigned  { background: #eff6ff; color: #1e40af; border-color: #93c5fd; }

/* State dot indicator */
.ss-state-dot {
    position: absolute;
    top: .5rem;
    right: .55rem;
    width: .48rem;
    height: .48rem;
    border-radius: 50%;
    background: #d1d5db;
}
.ss-day-card.state-rest      .ss-state-dot { background: #ef4444; }
.ss-day-card.state-field-work .ss-state-dot { background: #22c55e; }
.ss-day-card.state-standard  .ss-state-dot { background: #64748b; }
.ss-day-card.state-assigned   .ss-state-dot { background: #3b82f6; }

/* ── Legend ─────────────────────────────────────────────── */
.ss-legend {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: center;
    padding: .75rem 1.25rem;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
}

.ss-legend-item {
    display: flex;
    align-items: center;
    gap: .35rem;
    font-size: .73rem;
    color: #475569;
}

.ss-legend-dot {
    width: .5rem;
    height: .5rem;
    border-radius: 50%;
    flex-shrink: 0;
}

/* ── Bulk rotation bar ──────────────────────────────────── */
.ss-bulk-rotation-panel {
    margin-bottom: 1.25rem;
    padding: .85rem 1.25rem;
    border: 1px dashed #cbd5e1;
    border-radius: .75rem;
    background: #f8fafc;
}
.ss-bulk-bar {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    align-items: end;
    margin-top: .85rem;
}
.ss-bulk-field { display: flex; flex-direction: column; gap: .3rem; font-size: .75rem; font-weight: 600; color: #475569; }
.ss-bulk-field select,
.ss-bulk-field input {
    padding: .4rem .55rem;
    border: 1px solid #cbd5e1;
    border-radius: .35rem;
    font-size: .8rem;
    color: #0f172a;
}
.ss-bulk-submit { flex-direction: row; align-items: center; }

/* ── Rotation generator ─────────────────────────────────── */
.ss-rotation-panel {
    margin: 1.25rem 1.25rem 0;
    padding: .75rem 1rem;
    border: 1px dashed #cbd5e1;
    border-radius: .6rem;
    background: #f8fafc;
}
.ss-rotation-summary {
    cursor: pointer;
    font-size: .82rem;
    font-weight: 600;
    color: #1e40af;
}
.ss-rotation-form {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    align-items: end;
    margin-top: .85rem;
}
.ss-rotation-field {
    display: flex;
    flex-direction: column;
    gap: .3rem;
    font-size: .75rem;
    font-weight: 600;
    color: #475569;
}
.ss-rotation-field select,
.ss-rotation-field input {
    padding: .4rem .55rem;
    border: 1px solid #cbd5e1;
    border-radius: .35rem;
    font-size: .8rem;
    color: #0f172a;
}
.ss-rotation-hint {
    margin: .6rem 0 0;
    font-size: .74rem;
    color: #6b7280;
}

/* ── Rotation on/off preview ────────────────────────────── */
.ss-rotation-preview { margin-top: .85rem; }
.ss-preview-hint { font-size: .78rem; color: #94a3b8; margin: 0; }
.ss-preview-grid { display: flex; flex-wrap: wrap; gap: .3rem; }
.ss-preview-chip {
    width: 2.15rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: .25rem 0;
    border-radius: .35rem;
    font-size: .68rem;
    border: 1px solid transparent;
}
.ss-preview-dow { font-size: .58rem; text-transform: uppercase; opacity: .75; }
.ss-preview-day { font-weight: 700; font-size: .78rem; }
.ss-preview-on  { background: #eff6ff; color: #1e40af; border-color: #93c5fd; }
.ss-preview-off { background: #fef2f2; color: #b91c1c; border-color: #fca5a5; }
.ss-preview-summary { margin: .5rem 0 0; font-size: .75rem; color: #6b7280; }

/* ── Form actions ────────────────────────────────────────── */
.ss-form-actions {
    padding: 1rem 1.25rem;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

/* ── Empty state ─────────────────────────────────────────── */
.ss-empty {
    padding: 3.5rem 2rem;
    text-align: center;
    color: #94a3b8;
}

.ss-empty-icon {
    font-size: 2.5rem;
    margin-bottom: .75rem;
    opacity: .45;
}

.ss-empty-text {
    font-size: .9rem;
    color: #6b7280;
}

/* ── Responsive ──────────────────────────────────────────── */
@media (max-width: 1100px) {
    .ss-layout { grid-template-columns: 1fr; }
    .ss-emp-list { max-height: 14rem; }
    .ss-week-grid { grid-template-columns: repeat(4, 1fr); }
}
@media (max-width: 640px) {
    .ss-week-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
@endsection

@section('content')

{{-- Toolbar ─────────────────────────────────────────────────── --}}
<div class="ss-toolbar">
    <div class="ss-toolbar-left">
        <form method="GET" action="{{ route('attendance.shift-schedule.index') }}" id="filter-form" class="ss-dept-form">
            @if (is_null($lockedDepartments))
                <select name="dept_id" class="ss-dept-select" onchange="this.form.submit()">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->Dept_id }}" @selected($dept->Dept_id == $deptId)>{{ $dept->Dept_name }}</option>
                    @endforeach
                </select>
            @elseif ($lockedDepartments->count() > 1)
                <select name="dept_id" class="ss-dept-select" onchange="this.form.submit()">
                    <option value="">All my departments</option>
                    @foreach($lockedDepartments as $dept)
                        <option value="{{ $dept->Dept_id }}" @selected($dept->Dept_id == $deptId)>{{ $dept->Dept_name }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="dept_id" value="{{ $lockedDepartments->first()->Dept_id ?? '' }}">
                <span class="ss-dept-select" style="display:inline-flex;align-items:center;background:#f1f5f9;">
                    {{ $lockedDepartments->first()->Dept_name ?? 'Your Department' }}
                </span>
            @endif
            @if($employeeId)
                <input type="hidden" name="employee_id" value="{{ $employeeId }}">
            @endif
            @if($weekStart)
                <input type="hidden" name="week_start" value="{{ $weekStart->toDateString() }}">
            @endif
        </form>
    </div>

    <div class="ss-toolbar-right">
        <a href="{{ route('attendance.shift-schedule.index', ['dept_id' => $deptId, 'employee_id' => $employeeId, 'week_start' => $weekStart->copy()->subWeek()->toDateString()]) }}"
           class="ss-nav-btn">&#8592; Prev</a>

        <span class="ss-week-label">
            {{ $weekStart->format('M d') }} &ndash; {{ $weekStart->copy()->endOfWeek(\Carbon\Carbon::SUNDAY)->format('M d, Y') }}
        </span>

        <a href="{{ route('attendance.shift-schedule.index', ['dept_id' => $deptId, 'employee_id' => $employeeId, 'week_start' => $weekStart->copy()->addWeek()->toDateString()]) }}"
           class="ss-nav-btn">Next &#8594;</a>

        <a href="{{ route('attendance.shift-schedule.index', ['dept_id' => $deptId, 'employee_id' => $employeeId]) }}"
           class="ss-nav-btn active">This Week</a>
    </div>
</div>

{{-- Bulk rotation bar ─────────────────────────────────────────── --}}
<details class="ss-bulk-rotation-panel">
    <summary class="ss-rotation-summary">Bulk-generate rotation pattern for selected employees</summary>
    <form id="bulk-rotation-form" method="POST" action="{{ route('attendance.shift-schedule.generate-pattern-bulk') }}" class="ss-bulk-bar">
        @csrf
        <input type="hidden" name="dept_id" value="{{ $deptId }}">
        <div class="ss-bulk-field">
            <label for="bulk_shift_id">Shift</label>
            <select name="shift_id" id="bulk_shift_id" required>
                <option value="">Select shift…</option>
                @foreach($shifts as $shift)
                    <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="ss-bulk-field">
            <label for="bulk_on_days">Days on</label>
            <input type="number" name="on_days" id="bulk_on_days" min="1" value="1" required>
        </div>
        <div class="ss-bulk-field">
            <label for="bulk_off_days">Days off</label>
            <input type="number" name="off_days" id="bulk_off_days" min="0" value="0" required>
        </div>
        <div class="ss-bulk-field ss-bulk-checkbox">
            <label for="bulk_no_break">
                <input type="checkbox" name="no_break" value="1" id="bulk_no_break"> No Break (2-punch)
            </label>
        </div>
        <div class="ss-bulk-field">
            <label for="bulk_start_date">From</label>
            <input type="date" name="start_date" id="bulk_start_date" required>
        </div>
        <div class="ss-bulk-field">
            <label for="bulk_end_date">To</label>
            <input type="date" name="end_date" id="bulk_end_date" required>
        </div>
        <div class="ss-bulk-field ss-bulk-submit">
            <button type="submit" class="hris-btn hris-btn-primary" id="bulk-rotation-submit" disabled>
                Generate for selected (<span id="bulk-rotation-count">0</span>)
            </button>
        </div>
    </form>
    <div class="ss-rotation-preview" id="bulk-rotation-preview"></div>
    <p class="ss-rotation-hint">
        Check employees in the list below, then generate the same on/off cycle for all of them at once.
    </p>
</details>

{{-- Main layout ──────────────────────────────────────────────── --}}
<div class="ss-layout">

    {{-- Employee panel ──────────────────────────────────────── --}}
    <div class="ss-panel">
        <div class="ss-panel-header">
            <span class="ss-panel-title">Employees</span>
            <label style="display:flex;align-items:center;gap:.35rem;font-size:.72rem;color:#475569;cursor:pointer;font-weight:600;">
                <input type="checkbox" id="ss-select-all" style="cursor:pointer;"> Select all
            </label>
        </div>

        {{-- Search ──────────────────────────────────────────── --}}
        <div style="padding:.65rem .85rem;border-bottom:1px solid #e2e8f0;">
            <input type="text" id="emp-search" class="ss-search" placeholder="Search employee…" oninput="filterEmployees(this.value)">
        </div>

        <div class="ss-emp-list" id="emp-list">
            @forelse($employees as $emp)
                @php
                    $initials = strtoupper(substr($emp->first_name, 0, 1) . substr($emp->last_name, 0, 1));
                @endphp
                <div class="ss-emp-row-wrap" data-name="{{ strtolower($emp->last_name . ' ' . $emp->first_name) }}">
                    <input type="checkbox" form="bulk-rotation-form" name="user_ids[]" value="{{ $emp->id }}" class="ss-emp-checkbox">
                    <a href="{{ route('attendance.shift-schedule.index', ['dept_id' => $deptId, 'employee_id' => $emp->id, 'week_start' => $weekStart->toDateString()]) }}"
                       class="ss-emp-row {{ $emp->id == $employeeId ? 'active' : '' }}">
                        <div class="ss-emp-avatar">{{ $initials }}</div>
                        <div class="ss-emp-info">
                            <div class="ss-emp-name">{{ $emp->last_name }}, {{ $emp->first_name }}</div>
                        </div>
                    </a>
                </div>
            @empty
                <div style="padding:1.5rem;color:#94a3b8;font-size:.82rem;text-align:center;">No employees found.</div>
            @endforelse
        </div>

        <div class="ss-panel-footer">
            <a href="{{ route('attendance.shifts') }}" style="font-size:.77rem;color:#6366f1;text-decoration:none;font-weight:500;">
                &#8594; Manage shift templates
            </a>
        </div>
    </div>

    {{-- Week grid panel ─────────────────────────────────────── --}}
    <div class="ss-week-panel">

        @if($selectedEmployee)
            @php $today = \Carbon\Carbon::today()->toDateString(); @endphp

            <div class="ss-week-panel-header">
                @php $initials = strtoupper(substr($selectedEmployee->first_name,0,1).substr($selectedEmployee->last_name,0,1)); @endphp
                <div class="ss-emp-avatar" style="width:2.4rem;height:2.4rem;font-size:.78rem;flex-shrink:0;">{{ $initials }}</div>
                <div>
                    <div class="ss-emp-selected-name">
                        {{ $selectedEmployee->last_name }}, {{ $selectedEmployee->first_name }}
                    </div>
                    <div class="ss-emp-default-shift">
                        Default shift: {{ $selectedEmployee->shift_id ? ($shifts->firstWhere('id', $selectedEmployee->shift_id)?->name ?? 'N/A') : 'Standard Day' }}
                    </div>
                    @if ($activeAssignments->isNotEmpty() && ! $activeAssignments->every(fn ($r) => $r->shift_id === null))
                        <ul class="ss-multi-shift-list">
                            @foreach ($activeAssignments as $row)
                                @php
                                    $rowDateLabel = match (true) {
                                        $row->isSuperseded() => 'superseded before it took effect',
                                        $row->effective_until !== null => $row->effective_from->toFormattedDateString().' – '.$row->effective_until->toFormattedDateString(),
                                        default => 'from '.$row->effective_from->toFormattedDateString(),
                                    };
                                @endphp
                                <li>{{ $row->shift?->name ?? 'Standard Day' }} - {{ $row->workDaysLabel() }} <span class="ss-shift-dates">({{ $rowDateLabel }})</span></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            <details class="ss-rotation-panel">
                <summary class="ss-rotation-summary">Generate rotation pattern (e.g. 24-on/24-off duty)</summary>
                <form method="POST" action="{{ route('attendance.shift-schedule.generate-pattern') }}" class="ss-rotation-form">
                    @csrf
                    <input type="hidden" name="user_id" value="{{ $selectedEmployee->id }}">
                    <input type="hidden" name="dept_id" value="{{ $deptId }}">

                    <label class="ss-rotation-field">
                        Shift
                        <select name="shift_id" required>
                            <option value="">Select shift…</option>
                            @foreach($shifts as $shift)
                                <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="ss-rotation-field">
                        Days on
                        <input type="number" name="on_days" id="rot_on_days" min="1" value="1" required>
                    </label>
                    <label class="ss-rotation-field">
                        Days off
                        <input type="number" name="off_days" id="rot_off_days" min="0" value="0" required>
                    </label>
                    <label class="ss-rotation-field ss-rotation-checkbox">
                        <input type="checkbox" name="no_break" value="1"> No Break (2-punch)
                    </label>
                    <label class="ss-rotation-field">
                        From
                        <input type="date" name="start_date" id="rot_start_date" required>
                    </label>
                    <label class="ss-rotation-field">
                        To
                        <input type="date" name="end_date" id="rot_end_date" required>
                    </label>
                    <button type="submit" class="hris-btn hris-btn-primary">Generate</button>
                </form>
                <div class="ss-rotation-preview" id="rotation-preview"></div>
                <p class="ss-rotation-hint">
                    Sets the selected shift as {{ $selectedEmployee->first_name }}'s ongoing default and marks the
                    off-cycle dates as rest days. DTRs for the range are recomputed automatically.
                </p>
            </details>

            <details class="ss-rotation-panel">
                <summary class="ss-rotation-summary">Apply weekly pattern to a date range</summary>
                <form method="POST" action="{{ route('attendance.shift-schedule.apply-weekly-pattern') }}" class="ss-rotation-form">
                    @csrf
                    <input type="hidden" name="user_id" value="{{ $selectedEmployee->id }}">
                    <input type="hidden" name="dept_id" value="{{ $deptId }}">

                    @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $iso => $dowLabel)
                        <label class="ss-rotation-field">
                            {{ $dowLabel }}
                            <select name="pattern[{{ $iso }}]">
                                <option value="default">Clear / Default</option>
                                <option value="standard">Standard Day</option>
                                <option value="rest" @selected($iso >= 6)>Rest Day / Off</option>
                                <option value="field_work">Field Work</option>
                                @foreach($shifts as $shift)
                                    <option value="{{ $shift->id }}">{{ $shift->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach

                    <label class="ss-rotation-field">
                        From
                        <input type="date" name="start_date" required>
                    </label>
                    <label class="ss-rotation-field">
                        To
                        <input type="date" name="end_date" required>
                    </label>
                    <button type="submit" class="hris-btn hris-btn-primary">Apply Weekly Pattern</button>
                </form>
                <p class="ss-rotation-hint">
                    Repeats this Mon–Sun pattern across every week in the range, matched by actual day of week (a
                    partial first/last week still lines up correctly). DTRs for the range are recomputed automatically.
                </p>
            </details>

            <form method="POST" action="{{ route('attendance.shift-schedule.store') }}" id="week-form"
                  data-single-action="{{ route('attendance.shift-schedule.store') }}"
                  data-bulk-action="{{ route('attendance.shift-schedule.store-bulk') }}">
                @csrf
                <input type="hidden" name="user_id"    value="{{ $selectedEmployee->id }}">
                <input type="hidden" name="week_start"  value="{{ $weekStart->toDateString() }}">
                <input type="hidden" name="dept_id"     value="{{ $deptId }}">

                <div class="ss-week-grid">
                    @foreach($weekDays as $day)
                        @php
                            $dateStr     = $day->toDateString();
                            $assignment  = $existingAssignments->get($dateStr);
                            $isToday     = $dateStr === $today;
                            $isWeekend   = $day->isWeekend();

                            if ($assignment === null) {
                                $resolved     = $resolvedDefaults[$dateStr] ?? ['label' => 'Standard Day', 'value' => 'standard'];
                                $currentValue = $resolved['value'];
                                $stateClass   = '';
                            } elseif ($assignment->type === 'field_work') {
                                $currentValue = 'field_work';
                                $stateClass   = 'state-field-work';
                            } elseif ($assignment->type === 'standard') {
                                $currentValue = 'standard';
                                $stateClass   = 'state-standard';
                            } elseif ($assignment->shift_id === null) {
                                $currentValue = 'rest';
                                $stateClass   = 'state-rest';
                            } else {
                                $currentValue = (string) $assignment->shift_id;
                                $stateClass   = 'state-assigned';
                            }
                        @endphp

                        <div class="ss-day-card {{ $isWeekend ? 'is-weekend' : '' }} {{ $isToday ? 'is-today' : '' }} {{ $stateClass }}" id="card-{{ $dateStr }}">
                            <div class="ss-state-dot" id="dot-{{ $dateStr }}"></div>
                            <div class="ss-day-dow">{{ $day->format('D') }}</div>
                            @if($isToday)
                                <div class="ss-day-num" style="display:inline-flex;margin-bottom:.55rem;">{{ $day->format('j') }}</div>
                            @else
                                <div class="ss-day-num" style="display:block;">{{ $day->format('j') }}</div>
                            @endif
                            <select name="assignments[{{ $dateStr }}]"
                                    class="ss-day-select {{ $currentValue === 'rest' ? 'is-rest' : ($currentValue === 'field_work' ? 'is-field-work' : ($currentValue === 'standard' ? 'is-standard' : ($currentValue !== 'default' ? 'is-assigned' : ''))) }}"
                                    data-date="{{ $dateStr }}"
                                    onchange="onShiftChange(this)">
                                <option value="default"     @selected($currentValue === 'default')>Default ({{ $resolvedDefaults[$dateStr]['label'] ?? 'Standard Day' }})</option>
                                <option value="standard"    @selected($currentValue === 'standard')>Standard Day</option>
                                <option value="rest"        @selected($currentValue === 'rest')>Rest Day / Off</option>
                                <option value="field_work"  @selected($currentValue === 'field_work')>Field Work</option>
                                @foreach($shifts as $shift)
                                    <option value="{{ $shift->id }}" @selected($currentValue === (string)$shift->id)>{{ $shift->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                {{-- Legend ──────────────────────────────────── --}}
                <div class="ss-legend">
                    <div class="ss-legend-item"><span class="ss-legend-dot" style="background:#d1d5db;"></span> Default (no override)</div>
                    <div class="ss-legend-item"><span class="ss-legend-dot" style="background:#3b82f6;"></span> Assigned shift</div>
                    <div class="ss-legend-item"><span class="ss-legend-dot" style="background:#64748b;"></span> Standard Day (forced)</div>
                    <div class="ss-legend-item"><span class="ss-legend-dot" style="background:#ef4444;"></span> Rest day / Off</div>
                    <div class="ss-legend-item"><span class="ss-legend-dot" style="background:#22c55e;"></span> Field work</div>
                    <div class="ss-legend-item"><span class="ss-legend-dot" style="background:#3b82f6;border:2px solid #93c5fd;width:.6rem;height:.6rem;"></span> Today</div>
                </div>

                {{-- Actions ─────────────────────────────────── --}}
                <div class="ss-form-actions">
                    <button type="submit" class="hris-btn hris-btn-primary">Save Week Schedule</button>
                    <span style="font-size:.78rem;color:#6b7280;">DTRs for this week will be recomputed on save.</span>
                    <span id="week-save-bulk-hint" style="font-size:.78rem;color:#1d4ed8;font-weight:600;display:none;"></span>
                </div>
            </form>

        @else
            <div class="ss-empty">
                <div class="ss-empty-icon">&#128197;</div>
                <div class="ss-empty-text">Select an employee from the list to set their weekly shift schedule.</div>
            </div>
        @endif
    </div>

</div>

@endsection

@section('page_scripts')
<script>
/* ── Shift select colour update ──────────────────────────────── */
function onShiftChange(sel) {
    const v    = sel.value;
    const date = sel.dataset.date;
    const card = document.getElementById('card-' + date);
    const dot  = document.getElementById('dot-'  + date);

    sel.className = 'ss-day-select' +
        (v === 'rest'       ? ' is-rest'       :
         v === 'field_work' ? ' is-field-work'  :
         v === 'standard'   ? ' is-standard'    :
         v !== 'default'    ? ' is-assigned'    : '');

    card.className = card.className
        .replace(/\bstate-\S+/g, '')
        .trimEnd();

    if      (v === 'rest')       card.className += ' state-rest';
    else if (v === 'field_work') card.className += ' state-field-work';
    else if (v === 'standard')   card.className += ' state-standard';
    else if (v !== 'default')    card.className += ' state-assigned';
}

/* ── Employee search ─────────────────────────────────────────── */
function filterEmployees(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#emp-list .ss-emp-row-wrap').forEach(row => {
        const name = row.dataset.name || '';
        row.style.display = name.includes(q) ? '' : 'none';
    });
}

/* ── Rotation on/off preview ──────────────────────────────────── */
// Mirrors the exact on/off math ShiftScheduleController::writeRotationForEmployee()
// uses server-side: day 0 of the range is always "on"; the cycle is
// on_days-many "on" days followed by off_days-many "off" days, repeating.
function computeRotationDays(onDays, offDays, startStr, endStr) {
    var cycleLength = onDays + offDays;
    var cur = new Date(startStr + 'T00:00:00');
    var end = new Date(endStr + 'T00:00:00');
    var days = [];
    var i = 0;
    while (cur <= end && days.length < 400) {
        days.push({ date: new Date(cur), isOff: offDays > 0 && (i % cycleLength) >= onDays });
        cur.setDate(cur.getDate() + 1);
        i++;
    }
    return days;
}

function renderRotationPreview(containerId, onDaysId, offDaysId, startId, endId) {
    var container = document.getElementById(containerId);
    if (!container) return;

    var onDaysEl = document.getElementById(onDaysId);
    var offDaysEl = document.getElementById(offDaysId);
    var startEl = document.getElementById(startId);
    var endEl = document.getElementById(endId);
    if (!onDaysEl || !offDaysEl || !startEl || !endEl) return;

    var onDays = parseInt(onDaysEl.value, 10);
    var offDays = parseInt(offDaysEl.value, 10);
    var start = startEl.value;
    var end = endEl.value;

    if (!start || !end || !onDays || onDays < 1 || isNaN(offDays) || offDays < 0 || end < start) {
        container.innerHTML = '<p class="ss-preview-hint">Fill in days on/off and the date range to preview which days will be on vs. off.</p>';
        return;
    }

    var MAX_SHOWN = 62;
    var days = computeRotationDays(onDays, offDays, start, end);
    var shown = days.slice(0, MAX_SHOWN);
    var dow = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    var html = '<div class="ss-preview-grid">';
    shown.forEach(function (d) {
        html += '<div class="ss-preview-chip ' + (d.isOff ? 'ss-preview-off' : 'ss-preview-on') + '" title="' +
            d.date.toDateString() + ': ' + (d.isOff ? 'Off' : 'On') + '">' +
            '<span class="ss-preview-dow">' + dow[d.date.getDay()] + '</span>' +
            '<span class="ss-preview-day">' + d.date.getDate() + '</span>' +
            '</div>';
    });
    html += '</div>';

    var onCount = days.length - days.filter(function (d) { return d.isOff; }).length;
    var offCount = days.length - onCount;
    html += '<p class="ss-preview-summary">' + days.length + ' day(s): <b>' + onCount + ' on</b>, <b>' + offCount + ' off</b>' +
        (days.length > MAX_SHOWN ? ' (showing the first ' + MAX_SHOWN + ' - the pattern just keeps repeating)' : '') + '.</p>';

    container.innerHTML = html;
}

function wireRotationPreview(containerId, onDaysId, offDaysId, startId, endId) {
    var update = function () { renderRotationPreview(containerId, onDaysId, offDaysId, startId, endId); };
    [onDaysId, offDaysId, startId, endId].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', update);
    });
    update();
}

wireRotationPreview('rotation-preview', 'rot_on_days', 'rot_off_days', 'rot_start_date', 'rot_end_date');
wireRotationPreview('bulk-rotation-preview', 'bulk_on_days', 'bulk_off_days', 'bulk_start_date', 'bulk_end_date');

/* ── Bulk rotation selection ──────────────────────────────────── */
var bulkRotationForm = document.getElementById('bulk-rotation-form');
var empCheckboxes    = document.querySelectorAll('.ss-emp-checkbox');
var selectAllCb       = document.getElementById('ss-select-all');
var bulkSubmitBtn     = document.getElementById('bulk-rotation-submit');
var bulkCountEl       = document.getElementById('bulk-rotation-count');
var weekSaveBulkHint  = document.getElementById('week-save-bulk-hint');

function updateBulkRotationState() {
    var checked = document.querySelectorAll('.ss-emp-checkbox:checked').length;
    if (bulkCountEl) bulkCountEl.textContent = checked;
    if (bulkSubmitBtn) bulkSubmitBtn.disabled = checked === 0;
    if (selectAllCb) selectAllCb.checked = checked > 0 && checked === empCheckboxes.length;

    if (weekSaveBulkHint) {
        if (checked > 0) {
            weekSaveBulkHint.textContent = 'Will apply to ' + checked + ' selected employee(s) instead of just this one.';
            weekSaveBulkHint.style.display = '';
        } else {
            weekSaveBulkHint.style.display = 'none';
        }
    }
}

empCheckboxes.forEach(function (cb) { cb.addEventListener('change', updateBulkRotationState); });
if (selectAllCb) {
    selectAllCb.addEventListener('change', function () {
        empCheckboxes.forEach(function (cb) { cb.checked = selectAllCb.checked; });
        updateBulkRotationState();
    });
}
updateBulkRotationState();

if (bulkRotationForm) {
    bulkRotationForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var select = document.getElementById('bulk_shift_id');
        var shiftLabel = select.options[select.selectedIndex].text;
        var count = document.querySelectorAll('.ss-emp-checkbox:checked').length;
        if (count === 0) return;
        var from = document.getElementById('bulk_start_date').value;
        var to = document.getElementById('bulk_end_date').value;
        Swal.fire({
            icon: 'warning',
            title: 'Generate rotation pattern?',
            html: 'This will generate a <b>' + shiftLabel + '</b> rotation for the <b>' + count + '</b> selected employee(s) from <b>' + from + '</b> to <b>' + to + '</b>.',
            showCancelButton: true,
            confirmButtonText: 'Yes, generate',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#6b7280',
        }).then(function (res) { if (res.isConfirmed) bulkRotationForm.submit(); });
    });
}

/* ── Save Week Schedule: broadcast to checked employees ────────── */
var weekForm = document.getElementById('week-form');
if (weekForm) {
    weekForm.addEventListener('submit', function (e) {
        var checked = document.querySelectorAll('.ss-emp-checkbox:checked');

        // Clear any stale user_ids[] left over from a previously cancelled bulk attempt.
        weekForm.querySelectorAll('input[name="user_ids[]"]').forEach(function (el) { el.remove(); });

        if (checked.length === 0) {
            weekForm.action = weekForm.dataset.singleAction;
            return;
        }

        e.preventDefault();

        checked.forEach(function (cb) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'user_ids[]';
            hidden.value = cb.value;
            weekForm.appendChild(hidden);
        });

        var count = checked.length;
        Swal.fire({
            icon: 'warning',
            title: 'Save week schedule for multiple employees?',
            html: 'This will apply the currently displayed week\'s schedule to <b>' + count + '</b> selected employee(s). DTRs for the week will be recomputed for each.',
            showCancelButton: true,
            confirmButtonText: 'Yes, save for all',
            confirmButtonColor: '#2563eb',
            cancelButtonColor: '#6b7280',
        }).then(function (res) {
            if (res.isConfirmed) {
                weekForm.action = weekForm.dataset.bulkAction;
                weekForm.submit();
            }
        });
    });
}

/* ── Notifications ───────────────────────────────────────────── */
@if(session('schedule_status'))
    Swal.fire({
        icon: 'success',
        title: 'Saved',
        text: @json(session('schedule_status')),
        confirmButtonColor: '#3b82f6',
        timer: 3500,
        timerProgressBar: true,
    });
@endif
@if($errors->any())
    Swal.fire({
        icon: 'error',
        title: 'Error',
        text: @json($errors->first()),
        confirmButtonColor: '#3b82f6',
    });
@endif
</script>
@endsection
