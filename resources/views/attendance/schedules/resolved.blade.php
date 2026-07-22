@php $empName = trim("{$user->last_name}, {$user->first_name}"); @endphp
@extends('dashboards.layout', [
    'title' => 'Resolved Schedule',
    'subtitle' => "What actually applies each day for {$empName} - Shift Assignment history and Shift Schedule overrides combined.",
])

@section('page_head')
<style>
.sched-toolbar { display:flex; flex-wrap:wrap; gap:.75rem; align-items:center; justify-content:space-between; margin:0 0 1rem; }
.rsc-toolbar {
    display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between;
    margin-bottom: 1rem; padding: .9rem 1.25rem;
    background: #fff; border: 1px solid #e2e8f0; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0,0,0,.06);
}
.rsc-toolbar-left { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.rsc-nav-btn {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .42rem .85rem; border: 1px solid #cbd5e1; border-radius: .4rem;
    font-size: .82rem; font-weight: 500; background: #fff; color: #374151;
    text-decoration: none; cursor: pointer;
}
.rsc-nav-btn:hover { background: #f1f5f9; border-color: #94a3b8; }
.rsc-month-label { font-size: 1rem; font-weight: 700; color: #0f172a; white-space: nowrap; padding: 0 .35rem; min-width: 8.5rem; text-align: center; }

.rsc-legend { display: flex; flex-wrap: wrap; gap: 1.1rem; margin-bottom: 1rem; padding: 0 .1rem; }
.rsc-legend-item { display: flex; align-items: center; gap: .45rem; font-size: .78rem; color: #475569; font-weight: 500; }
.rsc-dot { width: .62rem; height: .62rem; border-radius: 50%; flex: 0 0 auto; }
.rsc-dot-override { background: #d97706; }
.rsc-dot-assignment { background: #2563eb; }
.rsc-dot-default { background: #94a3b8; }

.rsc-card { background: #fff; border: 1px solid #e2e8f0; border-radius: .9rem; box-shadow: 0 1px 3px rgba(0,0,0,.06); padding: 1rem; }
.rsc-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: .55rem; }
.rsc-weekday { text-align: center; font-size: .72rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .05em; padding-bottom: .4rem; }
.rsc-day {
    background: #fff; border: 1px solid #e2e8f0; border-radius: .65rem; min-height: 6.5rem;
    padding: .55rem; display: flex; flex-direction: column; gap: .3rem;
}
.rsc-day.is-blank { background: transparent; border: none; box-shadow: none; }
.rsc-day.is-today { border-color: #3b82f6; box-shadow: 0 0 0 1px #3b82f6 inset; }
.rsc-day.is-rest { background: #f8fafc; }
.rsc-day.is-conflict { border-color: #fde68a; background: #fffbeb; }
.rsc-day-head { display: flex; align-items: center; justify-content: space-between; }
.rsc-day-num { font-size: .8rem; font-weight: 700; color: #0f172a; }
.rsc-today-pill {
    font-size: .58rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    background: #3b82f6; color: #fff; padding: .05rem .4rem; border-radius: 9999px;
}
.rsc-day-label { font-size: .76rem; font-weight: 600; color: #0f172a; line-height: 1.25; }
.rsc-day-hours { font-size: .68rem; color: #94a3b8; }
.rsc-source-tag {
    align-self: flex-start; font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em;
    padding: .1rem .4rem; border-radius: 9999px; margin-top: auto;
}
.rsc-source-override { background: #fef3c7; color: #92400e; }
.rsc-source-assignment { background: #dbeafe; color: #1d4ed8; }
.rsc-source-default { background: #f1f5f9; color: #64748b; }
.rsc-shadow-warning {
    font-size: .66rem; color: #b45309; font-weight: 600; text-decoration: none; display: block;
}
.rsc-shadow-warning:hover { text-decoration: underline; }
.rsc-nobreak-tag {
    align-self: flex-start; font-size: .62rem; font-weight: 700;
    padding: .1rem .4rem; border-radius: 9999px; background: #ede9fe; color: #6d28d9;
}
</style>
@endsection

@php
    $prevDate = $monthStart->copy()->subMonthNoOverflow();
    $nextDate = $monthStart->copy()->addMonthNoOverflow();
    $todayStr = \Illuminate\Support\Carbon::now()->toDateString();
    $leadingBlanks = $monthStart->copy()->startOfMonth()->dayOfWeek;
    $weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
@endphp

@section('content')

<div class="sched-toolbar">
    <a href="{{ route('attendance.schedules', ['search' => $user->last_name]) }}" class="hris-btn">&larr; Back to Shift Assignment</a>
</div>

<h3 style="margin:.75rem 0 1rem;">{{ $empName }}</h3>

<div class="rsc-toolbar">
    <div class="rsc-toolbar-left">
        <a class="rsc-nav-btn" href="{{ route('attendance.schedules.resolved', ['user' => $user, 'month' => $prevDate->month, 'year' => $prevDate->year]) }}">
            <i class="fas fa-chevron-left"></i>
        </a>
        <span class="rsc-month-label">{{ $monthStart->format('F Y') }}</span>
        <a class="rsc-nav-btn" href="{{ route('attendance.schedules.resolved', ['user' => $user, 'month' => $nextDate->month, 'year' => $nextDate->year]) }}">
            <i class="fas fa-chevron-right"></i>
        </a>
        <a class="rsc-nav-btn" href="{{ route('attendance.schedules.resolved', ['user' => $user]) }}">Today</a>
    </div>
</div>

<div class="rsc-legend">
    <span class="rsc-legend-item"><span class="rsc-dot rsc-dot-assignment"></span>Decided by Shift Assignment</span>
    <span class="rsc-legend-item"><span class="rsc-dot rsc-dot-override"></span>Decided by a Shift Schedule override</span>
    <span class="rsc-legend-item"><span class="rsc-dot rsc-dot-default"></span>Default (no shift assigned)</span>
</div>

<div class="rsc-card">
    <div class="rsc-grid">
        @foreach ($weekdayLabels as $wd)
            <div class="rsc-weekday">{{ $wd }}</div>
        @endforeach

        @for ($i = 0; $i < $leadingBlanks; $i++)
            <div class="rsc-day is-blank"></div>
        @endfor

        @foreach ($days as $dateStr => $day)
            @php
                $isConflict = $day['shadowedAssignmentShiftName'] !== null;
            @endphp
            <div class="rsc-day
                {{ $dateStr === $todayStr ? 'is-today' : '' }}
                {{ $day['isRestDay'] ? 'is-rest' : '' }}
                {{ $isConflict ? 'is-conflict' : '' }}">
                <div class="rsc-day-head">
                    <span class="rsc-day-num">{{ $day['date']->day }}</span>
                    @if ($dateStr === $todayStr)
                        <span class="rsc-today-pill">Today</span>
                    @endif
                </div>
                <div class="rsc-day-label">{{ $day['label'] }}</div>
                @if ($day['hours'])
                    <div class="rsc-day-hours">{{ $day['hours'] }}</div>
                @endif
                @if ($day['noBreak'])
                    <span class="rsc-nobreak-tag">No Break (2-punch)</span>
                @endif
                @if ($isConflict)
                    <a class="rsc-shadow-warning"
                       href="{{ route('attendance.shift-schedule.index', ['employee_id' => $user->id, 'week_start' => $day['date']->copy()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->toDateString()]) }}"
                       title="A Shift Schedule override is winning here. The Shift Assignment screen currently says {{ $day['shadowedAssignmentShiftName'] }} for this date, but it's being overridden.">
                        &#9888; Assignment says {{ $day['shadowedAssignmentShiftName'] }}
                    </a>
                @endif
                <span class="rsc-source-tag rsc-source-{{ $day['source'] }}">{{ ucfirst($day['source']) }}</span>
            </div>
        @endforeach
    </div>
</div>

@endsection
