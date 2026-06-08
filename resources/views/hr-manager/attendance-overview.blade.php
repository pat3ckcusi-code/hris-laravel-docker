@extends('dashboards.layout', [
    'title' => 'Attendance Overview',
    'subtitle' => 'Org-wide tardiness, absences, and attendance trends by month and department.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-url="{{ $attendanceDataUrl }}" data-csrf="{{ csrf_token() }}">

        <div class="hrm-toolbar">
            <input type="month" id="attendanceMonth" value="{{ now()->format('Y-m') }}">
            <select id="attendanceDepartment">
                <option value="">All Departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->Dept_id }}">{{ $department->Dept_name }}</option>
                @endforeach
            </select>
            <button class="hrm-btn" id="attendanceFilterBtn" type="button">Apply Filter</button>
        </div>

        {{-- Summary Cards --}}
        <div class="hrm-summary-grid" style="margin-bottom:2rem;">
            <article class="hrm-summary-card" id="attAbsentDays">
                <p>Absent Days This Month</p>
                <h3>&mdash;</h3>
            </article>
            <article class="hrm-summary-card" id="attTotalLate">
                <p>Total Late Minutes</p>
                <h3>&mdash;</h3>
            </article>
            <article class="hrm-summary-card" id="attTotalUndertime">
                <p>Total Undertime Minutes</p>
                <h3>&mdash;</h3>
            </article>
            <article class="hrm-summary-card" id="attCleanDays">
                <p>Clean Days (0 Absences)</p>
                <h3>&mdash;</h3>
            </article>
        </div>

        <div class="hrm-chart-grid">
            <article class="hrm-chart-card">
                <h4>Daily Absences &mdash; This Month</h4>
                <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="dailyAbsencesChart"></canvas></div>
            </article>
            <article class="hrm-chart-card">
                <h4>Late Minutes by Department</h4>
                <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="deptLateChart"></canvas></div>
            </article>
        </div>

        <div class="hrm-chart-card" style="margin-top:1.5rem;">
            <h4>Top 15 Employees by Tardiness</h4>
            <div class="hrm-table-wrap">
                <table class="hrm-table" id="attTopTable">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Late Min</th>
                            <th>Undertime Min</th>
                            <th>Absences</th>
                            <th>Source</th>
                            <th>DTR</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="7" style="text-align:center;color:#94a3b8;font-style:italic;">Loading&hellip;</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
