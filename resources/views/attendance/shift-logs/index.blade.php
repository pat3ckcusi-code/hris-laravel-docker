@extends('dashboards.layout', [
    'title' => 'Shift Logs',
    'subtitle' => 'A full log of every shift-related change, company-wide.',
])

@section('content')

{{-- Filter bar --}}
<div class="tile" style="margin-bottom:1rem;">
    <form method="GET" action="{{ route('attendance.shift-logs') }}"
          style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">

        <div style="display:flex;flex-direction:column;gap:0.3rem;">
            <label for="dept_id" style="font-size:0.82rem;font-weight:600;color:#374151;">Department</label>
            <select id="dept_id" name="dept_id"
                    style="padding:0.5rem 0.7rem;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;background:#fff;">
                <option value="">All Departments</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept->Dept_id }}" @selected($deptId === (int) $dept->Dept_id)>{{ $dept->Dept_name }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit"
                style="padding:0.52rem 1.1rem;background:#374151;color:#fff;border:none;border-radius:6px;
                       font-size:0.9rem;font-weight:600;cursor:pointer;">
            View
        </button>
    </form>
</div>

{{-- Shift Change Log: every shift-related action, company-wide --}}
@include('attendance.shift-logs._log_table', [
    'logs' => $logs,
    'title' => 'Shift Change Log',
    'subtitle' => 'Every shift template, assignment, schedule, and access change, most recent first.',
])

@endsection
