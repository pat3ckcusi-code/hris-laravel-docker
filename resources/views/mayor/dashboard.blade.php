@extends('dashboards.layout', [
    'title' => 'Mayor Dashboard',
    'subtitle' => 'Executive overview and analytics for the Mayor’s Office.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-dashboard"
        data-chart-url="{{ $chartDataUrl ?? route('mayor.chart-data') }}"
        data-alerts-url="{{ route('mayor.alerts') }}">

        {{-- Alerts Panel --}}
        <div id="hrmAlertStrip" class="hrm-alert-strip" style="display:none;"></div>

        <div class="hrm-summary-grid">
            <a href="{{ route('mayor.employees') }}" class="hrm-summary-card">
                <p>Total Employees</p>
                <h3 id="summaryTotalEmployees">{{ number_format($summary['total_employees'] ?? 0) }}</h3>
            </a>

            <a href="{{ route('mayor.service-milestones') }}" class="hrm-summary-card">
                <p>Award Recipients</p>
                <h3>{{ number_format($summary['award_recipients'] ?? 0) }}</h3>
                <small>Service milestones in {{ date('Y') }}</small>
            </a>

            <article class="hrm-summary-card" style="cursor:pointer"
                data-filter="sixty_plus"
                data-title="Employees Age 60+">
                <p>Employees Age 60+</p>
                <h3>{{ number_format($summary['sixty_plus_count'] ?? 0) }}</h3>
                <small>Nearing retirement age</small>
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
        window.hrManagerInitialData = @json($initialChartData ?? []);
    </script>
    @vite('resources/js/hr_manager.js')
@endsection
