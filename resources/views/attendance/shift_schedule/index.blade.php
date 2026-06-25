@extends('dashboards.layout', [
    'title'    => 'Shift Schedule',
    'subtitle' => 'Set per-day shift assignments for employees in rotating or 24/7 departments. Overrides the employee\'s default shift for specific dates.',
])

@section('page_head')
<style>
.ss-toolbar { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; margin:0 0 1rem; }
.ss-toolbar select, .ss-toolbar input { padding:.4rem .55rem; border:1px solid #cbd5e1; border-radius:.4rem; font-size:.82rem; background:#fff; }
.ss-emp-list { max-height:22rem; overflow-y:auto; border:1px solid #e2e8f0; border-radius:.5rem; background:#f8fafc; }
.ss-emp-row { display:flex; align-items:center; gap:.6rem; padding:.5rem .75rem; border-bottom:1px solid #e2e8f0; cursor:pointer; }
.ss-emp-row:last-child { border-bottom:none; }
.ss-emp-row:hover { background:#eff6ff; }
.ss-emp-row.active { background:#dbeafe; font-weight:600; }
.ss-week-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:.5rem; margin-top:1rem; }
.ss-day-card { border:1px solid #e2e8f0; border-radius:.5rem; padding:.6rem .75rem; background:#fff; }
.ss-day-card.is-weekend { background:#fafafa; }
.ss-day-label { font-size:.72rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; margin-bottom:.2rem; }
.ss-day-date { font-size:.82rem; color:#0f172a; margin-bottom:.5rem; }
.ss-day-select { width:100%; padding:.35rem .4rem; border:1px solid #cbd5e1; border-radius:.35rem; font-size:.78rem; background:#fff; }
.ss-day-select.is-rest { background:#f1f5f9; color:#64748b; }
.ss-day-select.is-field-work { background:#f0fdf4; color:#15803d; }
.ss-day-select.is-assigned { background:#eff6ff; color:#1e40af; }
.ss-nav-btn { padding:.35rem .7rem; border:1px solid #cbd5e1; border-radius:.4rem; font-size:.8rem; background:#fff; cursor:pointer; text-decoration:none; color:#0f172a; }
.ss-nav-btn:hover { background:#f1f5f9; }
.ss-week-header { display:flex; align-items:center; gap:.75rem; margin:.75rem 0 .25rem; }
.ss-week-label { font-size:.88rem; font-weight:600; color:#0f172a; }
</style>
@endsection

@section('content')

@if(session('schedule_status'))
    <div class="hris-alert hris-alert-success" style="margin-bottom:1rem;">{{ session('schedule_status') }}</div>
@endif

{{-- Toolbar: dept filter + week navigation --}}
<div class="ss-toolbar">
    <form method="GET" action="{{ route('attendance.shift-schedule.index') }}" id="filter-form" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;">
        <select name="dept_id" onchange="document.getElementById('filter-form').submit()">
            <option value="">All Departments</option>
            @foreach($departments as $dept)
                <option value="{{ $dept->id }}" @selected($dept->id == $deptId)>{{ $dept->Dept_name }}</option>
            @endforeach
        </select>
        @if($employeeId)
            <input type="hidden" name="employee_id" value="{{ $employeeId }}">
        @endif
        @if($weekStart)
            <input type="hidden" name="week_start" value="{{ $weekStart->toDateString() }}">
        @endif
    </form>

    <div class="ss-week-header">
        <a href="{{ route('attendance.shift-schedule.index', ['dept_id' => $deptId, 'employee_id' => $employeeId, 'week_start' => $weekStart->copy()->subWeek()->toDateString()]) }}" class="ss-nav-btn">← Prev</a>
        <span class="ss-week-label">Week of {{ $weekStart->format('M d, Y') }} – {{ $weekStart->copy()->endOfWeek(Carbon\Carbon::SUNDAY)->format('M d, Y') }}</span>
        <a href="{{ route('attendance.shift-schedule.index', ['dept_id' => $deptId, 'employee_id' => $employeeId, 'week_start' => $weekStart->copy()->addWeek()->toDateString()]) }}" class="ss-nav-btn">Next →</a>
        <a href="{{ route('attendance.shift-schedule.index', ['dept_id' => $deptId, 'employee_id' => $employeeId]) }}" class="ss-nav-btn" style="color:#6b7280;">This Week</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:18rem 1fr;gap:1rem;align-items:start;">

    {{-- Employee list --}}
    <div>
        <div style="font-size:.78rem;font-weight:600;color:#475569;margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.04em;">Employees</div>
        <div class="ss-emp-list">
            @forelse($employees as $emp)
                <a href="{{ route('attendance.shift-schedule.index', ['dept_id' => $deptId, 'employee_id' => $emp->id, 'week_start' => $weekStart->toDateString()]) }}"
                   class="ss-emp-row {{ $emp->id == $employeeId ? 'active' : '' }}" style="text-decoration:none;color:inherit;">
                    <span style="font-size:.82rem;">{{ $emp->last_name }}, {{ $emp->first_name }}</span>
                </a>
            @empty
                <div style="padding:.75rem;color:#94a3b8;font-size:.82rem;">No employees found.</div>
            @endforelse
        </div>
        <div style="margin-top:.5rem;">
            <a href="{{ route('attendance.shifts') }}" style="font-size:.78rem;color:#6366f1;">Manage shift templates →</a>
        </div>
    </div>

    {{-- Week grid for selected employee --}}
    <div>
        @if($selectedEmployee)
            <div style="font-size:.85rem;font-weight:600;color:#0f172a;margin-bottom:.25rem;">
                {{ $selectedEmployee->last_name }}, {{ $selectedEmployee->first_name }}
                @if($selectedEmployee->shift_id)
                    <span style="font-size:.75rem;font-weight:400;color:#6b7280;">— default: {{ $shifts->firstWhere('id', $selectedEmployee->shift_id)?->name ?? 'N/A' }}</span>
                @else
                    <span style="font-size:.75rem;font-weight:400;color:#6b7280;">— default: Standard Day</span>
                @endif
            </div>

            <form method="POST" action="{{ route('attendance.shift-schedule.store') }}">
                @csrf
                <input type="hidden" name="user_id" value="{{ $selectedEmployee->id }}">
                <input type="hidden" name="week_start" value="{{ $weekStart->toDateString() }}">
                <input type="hidden" name="dept_id" value="{{ $deptId }}">

                <div class="ss-week-grid">
                    @foreach($weekDays as $day)
                        @php
                            $dateStr = $day->toDateString();
                            $assignment = $existingAssignments->get($dateStr);
                            // Current value: numeric shift_id, 'rest', 'field_work', or 'default'
                            if ($assignment === null) {
                                $currentValue = 'default';
                            } elseif ($assignment->type === 'field_work') {
                                $currentValue = 'field_work';
                            } elseif ($assignment->shift_id === null) {
                                $currentValue = 'rest';
                            } else {
                                $currentValue = (string) $assignment->shift_id;
                            }
                            $isWeekend = $day->isWeekend();
                        @endphp
                        <div class="ss-day-card {{ $isWeekend ? 'is-weekend' : '' }}">
                            <div class="ss-day-label">{{ $day->format('D') }}</div>
                            <div class="ss-day-date">{{ $day->format('M d') }}</div>
                            <select name="assignments[{{ $dateStr }}]"
                                    class="ss-day-select {{ $currentValue === 'rest' ? 'is-rest' : ($currentValue === 'field_work' ? 'is-field-work' : ($currentValue !== 'default' ? 'is-assigned' : '')) }}"
                                    onchange="this.className='ss-day-select '+(this.value==='rest'?'is-rest':(this.value==='field_work'?'is-field-work':(this.value!=='default'?'is-assigned':'')))">
                                <option value="default" @selected($currentValue === 'default')>Default</option>
                                <option value="rest" @selected($currentValue === 'rest')>— Rest Day / Off —</option>
                                <option value="field_work" @selected($currentValue === 'field_work')>— Field Work —</option>
                                @foreach($shifts as $shift)
                                    <option value="{{ $shift->id }}" @selected($currentValue === (string)$shift->id)>{{ $shift->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                <div style="margin-top:1rem;">
                    <button type="submit" class="hris-btn hris-btn-primary">Save Week Schedule</button>
                    <span style="font-size:.78rem;color:#6b7280;margin-left:.75rem;">DTRs for this week will be recomputed on save.</span>
                </div>
            </form>

        @else
            <div style="padding:2rem;text-align:center;color:#94a3b8;font-size:.9rem;border:1px dashed #e2e8f0;border-radius:.5rem;">
                Select an employee from the list to set their weekly shift schedule.
            </div>
        @endif
    </div>

</div>

@endsection
