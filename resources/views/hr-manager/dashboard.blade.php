@extends('dashboards.layout', [
    'title' => 'HR Manager Dashboard',
    'subtitle' => 'Charts and analytics for workforce and HR operations.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-dashboard" data-chart-url="{{ $chartDataUrl }}" data-alerts-url="{{ route('hr-manager.alerts') }}" data-export-url="{{ $exportUrl }}">

        {{-- Alerts Panel --}}
        <div id="hrmAlertStrip" class="hrm-alert-strip" style="display:none;"></div>

        <div class="hrm-summary-grid">
            <article class="hrm-summary-card">
                <p>Total Employees</p>
                <h3>{{ number_format($workforceCards['total_employees']) }}</h3>
                <small>Across all departments</small>
            </article>
            <article class="hrm-summary-card" style="cursor:pointer"
                data-filter="award_recipients"
                data-title="Award Recipients {{ date('Y') }}">
                <p>Award Recipients</p>
                <h3>{{ number_format($workforceCards['award_recipients']) }}</h3>
                <small>Service milestones in {{ date('Y') }}</small>
            </article>
            <article class="hrm-summary-card">
                <p>{{ $workforceCards['top_employee_type'] }}</p>
                <h3>{{ number_format($workforceCards['top_employee_type_count']) }}</h3>
                <small>Largest employee type</small>
            </article>
            <article class="hrm-summary-card" style="cursor:pointer"
                data-filter="sixty_plus"
                data-title="Employees Age 60+">
                <p>Employees Age 60+</p>
                <h3>{{ number_format($workforceCards['sixty_plus_count']) }}</h3>
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

            <label for="employeeTypeFilter">Employee Type</label>
            <select id="employeeTypeFilter" class="hrm-filter-select">
                <option value="">All Employee Types</option>
                @foreach($employeeTypes as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </select>

            <button type="button" class="hrm-btn" id="hrmExportCsvBtn">
                Export CSV
            </button>
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
