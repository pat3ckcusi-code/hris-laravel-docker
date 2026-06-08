@extends('dashboards.layout', [
    'title' => 'HR Manager Dashboard',
    'subtitle' => 'Charts and analytics for workforce and HR operations.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-dashboard" data-chart-url="{{ $chartDataUrl }}" data-alerts-url="{{ route('hr-manager.alerts') }}">

        {{-- Alerts Panel --}}
        <div id="hrmAlertStrip" class="hrm-alert-strip" style="display:none;"></div>

        <div class="hrm-summary-grid">
            <article class="hrm-summary-card">
                <p>Total Requests</p>
                <h3 id="summaryTotalRequests">{{ number_format($summary['total_requests']) }}</h3>
            </article>
            <article class="hrm-summary-card">
                <p>Pending</p>
                <h3 id="summaryPending">{{ number_format($summary['pending']) }}</h3>
            </article>
            <article class="hrm-summary-card">
                <p>Approved</p>
                <h3 id="summaryApproved">{{ number_format($summary['approved']) }}</h3>
            </article>
            <article class="hrm-summary-card">
                <p>Completed</p>
                <h3 id="summaryCompleted">{{ number_format($summary['completed']) }}</h3>
            </article>
        </div>

        <div class="hrm-filter-row">
            <label for="departmentFilter">Department</label>
            <select id="departmentFilter" class="hrm-filter-select">
                <option value="">All Departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->Dept_id }}">{{ $department->Dept_name }}</option>
                @endforeach
            </select>
        </div>

        <div class="hrm-chart-grid">
            <article class="hrm-chart-card full-width">
                <h4>Total Workforce</h4>
                <div class="hrm-chart-wrap"><canvas id="totalWorkforceChart"></canvas></div>
            </article>

            <article class="hrm-chart-card">
                <h4>Gender Distribution</h4>
                <div class="hrm-chart-wrap"><canvas id="genderChart"></canvas></div>
            </article>

            <article class="hrm-chart-card">
                <h4>Employee Type</h4>
                <div class="hrm-chart-wrap"><canvas id="employmentStatusChart"></canvas></div>
            </article>

            <article class="hrm-chart-card">
                <h4>Age Group Distribution</h4>
                <div class="hrm-chart-wrap"><canvas id="ageGroupChart"></canvas></div>
            </article>

            <article class="hrm-chart-card">
                <h4>Length of Service</h4>
                <div class="hrm-chart-wrap"><canvas id="lengthOfServiceChart"></canvas></div>
            </article>
        </div>
    </section>
@endsection

@section('page_scripts')
    <script>
        window.hrManagerInitialData = @json($initialChartData);
    </script>
    @vite('resources/js/hr_manager.js')
@endsection
