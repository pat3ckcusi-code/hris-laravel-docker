@extends('dashboards.layout', [
    'title' => 'Attendance Overview',
    'subtitle' => 'Org-wide tardiness, absences, and attendance trends by month and department.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module"
             data-url="{{ $attendanceDataUrl }}"
             data-notify-url="{{ $attendanceNotifyUrl }}"
             data-csrf="{{ csrf_token() }}">

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
            <article class="hrm-summary-card" id="attTotalEmployees">
                <p>Total Employees</p>
                <h3>&mdash;</h3>
            </article>
            <article class="hrm-summary-card" id="attAvgTardiness">
                <p>Avg Tardiness (min)</p>
                <h3>&mdash;</h3>
            </article>
            <article class="hrm-summary-card" id="attAvgUndertime">
                <p>Avg Undertime (min)</p>
                <h3>&mdash;</h3>
            </article>
            <article class="hrm-summary-card" id="attTotalAbsences">
                <p>Total Absences</p>
                <h3>&mdash;</h3>
            </article>
        </div>

        <div class="hrm-chart-grid">
            <article class="hrm-chart-card">
                <h4>3-Month Attendance Trend</h4>
                <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="monthlyTrendChart"></canvas></div>
            </article>
            <article class="hrm-chart-card">
                <h4>Late Minutes by Department</h4>
                <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="deptLateChart"></canvas></div>
            </article>
        </div>

        <div class="hrm-chart-card" style="margin-top:1.5rem;">
            <h4>Employees Exceeding Threshold (&gt;10 Tardiness or Undertime Days)</h4>
            <div class="hrm-table-wrap">
                <table class="hrm-table" id="attDrillTable">
                    <thead>
                        <tr>
                            <th>Emp No</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Tardiness Days</th>
                            <th>Undertime Days</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="6" style="text-align:center;color:#94a3b8;font-style:italic;">Loading&hellip;</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
