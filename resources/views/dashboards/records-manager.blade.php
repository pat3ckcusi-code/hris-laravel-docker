@extends('dashboards.layout', [
    'title' => 'Records Manager Dashboard',
    'subtitle' => 'Statistics and analytics for workforce decisions, data quality monitoring, and access governance.',
])

@section('top_actions')
    <div class="header-actions">
        <a href="{{ route('dashboard.records-manager.employees') }}" class="top-link">Employee Records</a>
        <a href="{{ route('dashboard.records-manager.departments') }}" class="top-link">Department Management</a>
        <a href="{{ route('dashboard.records-manager.access') }}" class="top-link">Access Management</a>
    </div>
@endsection

@section('tiles')
    <article class="tile">
        <strong>Total Workforce</strong>
        {{ $statusSummary['total'] }} total employee records tracked.
    </article>
    <article class="tile">
        <strong>Active Workforce Rate</strong>
        {{ $statusSummary['total'] > 0 ? round(($statusSummary['active'] / $statusSummary['total']) * 100, 1) : 0 }}% currently active.
    </article>
    <article class="tile">
        <strong>Profile Completeness</strong>
        {{ $profileCompletenessRate }}% complete employee profiles.
    </article>
    <article class="tile">
        <strong>Average Data Gap Score</strong>
        {{ $averageGapScore }} missing fields per employee profile.
    </article>
@endsection

@section('content')
    @if (session('status'))
        <div class="notice success">{{ session('status') }}</div>
    @endif

    @if (session('error'))
        <div class="notice error">{{ session('error') }}</div>
    @endif

    <section class="analytics-grid" aria-label="Records analytics">
        <article class="panel wide">
            <header>
                <h2>Workforce Status Mix</h2>
                <p>Use this to assess staffing stability and review separation or inactive trends.</p>
            </header>

            <div class="metric-list">
                @foreach ($statusByGroup as $status => $count)
                    @php
                        $statusPercent = $statusSummary['total'] > 0 ? round(($count / $statusSummary['total']) * 100, 1) : 0;
                    @endphp
                    <div class="metric-row">
                        <div class="metric-head">
                            <span>{{ $status }}</span>
                            <strong>{{ $count }} ({{ $statusPercent }}%)</strong>
                        </div>
                        <div class="progress-track">
                            <span class="progress-fill status-{{ strtolower($status) }}" style="width: {{ $statusPercent }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="panel">
            <header>
                <h2>Data Quality Watchlist</h2>
                <p>Prioritize cleanup actions to improve report reliability.</p>
            </header>

            <ul class="watchlist">
                <li>
                    <span>Missing Agency Employee No.</span>
                    <strong>{{ $dataQuality['missing_emp_no'] }}</strong>
                </li>
                <li>
                    <span>Missing Designation</span>
                    <strong>{{ $dataQuality['missing_designation'] }}</strong>
                </li>
                <li>
                    <span>Missing Department</span>
                    <strong>{{ $dataQuality['missing_department'] }}</strong>
                </li>
                <li>
                    <span>Missing Employee Type</span>
                    <strong>{{ $dataQuality['missing_employee_type'] }}</strong>
                </li>
            </ul>
        </article>

        <article class="panel">
            <header>
                <h2>Access Level Distribution</h2>
                <p>Confirm role assignment spread and identify concentration risk.</p>
            </header>

            <div class="metric-list">
                @forelse ($accessDistribution as $item)
                    <div class="metric-row">
                        <div class="metric-head">
                            <span>{{ $item['role'] }}</span>
                            <strong>{{ $item['count'] }} ({{ $item['percentage'] }}%)</strong>
                        </div>
                        <div class="progress-track">
                            <span class="progress-fill access" style="width: {{ $item['percentage'] }}%"></span>
                        </div>
                    </div>
                @empty
                    <p class="empty-state">No access level data available.</p>
                @endforelse
            </div>
        </article>

        <article class="panel">
            <header>
                <h2>Employee Type Composition</h2>
                <p>Review contract mix to support manpower and budget planning.</p>
            </header>

            <div class="metric-list">
                @forelse ($employeeTypeDistribution as $typeRow)
                    @php
                        $typePercent = $statusSummary['total'] > 0 ? round(($typeRow['count'] / $statusSummary['total']) * 100, 1) : 0;
                    @endphp
                    <div class="metric-row">
                        <div class="metric-head">
                            <span>{{ $typeRow['type'] }}</span>
                            <strong>{{ $typeRow['count'] }} ({{ $typePercent }}%)</strong>
                        </div>
                        <div class="progress-track">
                            <span class="progress-fill type" style="width: {{ $typePercent }}%"></span>
                        </div>
                    </div>
                @empty
                    <p class="empty-state">No employee type distribution available.</p>
                @endforelse
            </div>
        </article>

        <article class="panel wide">
            <header>
                <h2>Top Departments by Headcount</h2>
                <p>Use this ranking to balance workloads and support staffing decisions.</p>
            </header>

            <div class="ranking-table-wrap">
                <table class="ranking-table" aria-label="Top departments by headcount">
                    <thead>
                        <tr>
                            <th>Department</th>
                            <th>Employees</th>
                            <th>Relative Size</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($topDepartments as $dept)
                            @php
                                $barPercent = $largestDepartmentCount > 0 ? round(($dept['count'] / $largestDepartmentCount) * 100, 1) : 0;
                            @endphp
                            <tr>
                                <td>{{ $dept['department'] }}</td>
                                <td>{{ $dept['count'] }}</td>
                                <td>
                                    <div class="relative-track">
                                        <span class="relative-fill" style="width: {{ $barPercent }}%"></span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3">No department allocation data available.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel wide">
            <header>
                <h2>Decision Insights</h2>
                <p>Immediate recommendations based on current records.</p>
            </header>

            <div class="insight-grid">
                <div class="insight">
                    <h3>Profile Completion</h3>
                    <p>
                        @if ($profileCompletenessRate < 70)
                            Profile completeness is below 70%. Prioritize data cleanup for employee number, designation, and department assignment.
                        @elseif ($profileCompletenessRate < 90)
                            Profile completeness is moderate. Focus on remaining gaps to improve analytics confidence.
                        @else
                            Profile completeness is high. Maintain validation controls to keep data quality stable.
                        @endif
                    </p>
                </div>

                <div class="insight">
                    <h3>Workforce Stability</h3>
                    <p>
                        @php
                            $nonActiveRate = $statusSummary['total'] > 0 ? round(($statusSummary['inactive'] / $statusSummary['total']) * 100, 1) : 0;
                        @endphp
                        Non-active profiles are {{ $nonActiveRate }}% of records.
                        @if ($nonActiveRate > 25)
                            Schedule a status audit to verify inactive or separated records.
                        @else
                            Current status distribution looks stable; continue regular monthly review.
                        @endif
                    </p>
                </div>

                <div class="insight">
                    <h3>Department Assignment</h3>
                    <p>
                        {{ $dataQuality['missing_department'] }} employee records have no department assignment.
                        @if ($dataQuality['missing_department'] > 0)
                            Assign departments to improve organization reporting and staffing analysis.
                        @else
                            Department assignment coverage is complete.
                        @endif
                    </p>
                </div>
            </div>
        </article>
    </section>
@endsection

@section('page_scripts')
    <script>
        (function () {
            @if ($errors->any())
            Swal.fire({
                icon: 'error',
                title: 'Unable to save changes',
                text: @json(implode("\n", $errors->all())),
                confirmButtonColor: '#ea580c',
            });
            @endif
        })();
    </script>
@endsection
