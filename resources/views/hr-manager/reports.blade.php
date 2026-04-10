@extends('dashboards.layout', [
    'title' => 'HR Reports',
    'subtitle' => 'Workforce analytics, distributions, and demographics with export options.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module hrm-dashboard" data-module="reports" data-chart-url="{{ $reportsChartUrl }}">
        <div class="hrm-toolbar">
            <select id="departmentFilter">
                <option value="">All Departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->Dept_id }}">{{ $department->Dept_name }}</option>
                @endforeach
            </select>
            <a href="{{ $exportPdfUrl }}" class="hrm-btn" target="_blank" rel="noopener">Export PDF</a>
            <a href="{{ $exportExcelUrl }}" class="hrm-btn" target="_blank" rel="noopener">Export Excel</a>
        </div>

        <div class="hrm-chart-grid">
            <article class="hrm-chart-card full-width">
                <h4>Total Workforce</h4>
                <div class="hrm-chart-wrap"><canvas id="totalWorkforceChart"></canvas></div>
            </article>
            <article class="hrm-chart-card">
                <h4>Gender Demographics</h4>
                <div class="hrm-chart-wrap"><canvas id="genderChart"></canvas></div>
            </article>
            <article class="hrm-chart-card">
                <h4>Employee Type</h4>
                <div class="hrm-chart-wrap"><canvas id="employmentStatusChart"></canvas></div>
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
