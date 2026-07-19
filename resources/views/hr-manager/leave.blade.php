@extends('dashboards.layout', [
    'title' => 'Leave Management',
    'subtitle' => 'Pending leave queue with approval workflow and leave usage analytics.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-module="leave" data-url="{{ $leaveDataUrl }}" data-action-url="{{ $leaveActionBaseUrl }}" data-analytics-url="{{ $leaveAnalyticsUrl }}" data-notify-url="{{ $leaveNotifyManagerUrl }}" data-csrf="{{ csrf_token() }}" data-pagination='@json($leavePagination)' data-initial-chart='@json($leaveChart)'>

        {{-- Holiday-Leave Overlap Alerts (Enhancement 6) --}}
        @if(!empty($holidayAlerts))
            <div class="hrm-holiday-alerts">
                @foreach($holidayAlerts as $alert)
                    <div class="hrm-holiday-alert-banner">
                        <i class="fas fa-calendar-exclamation"></i>
                        <strong>{{ $alert['count'] }} pending {{ Str::plural('request', $alert['count']) }}</strong>
                        overlap with <strong>{{ $alert['title'] }}</strong>
                        ({{ \Carbon\Carbon::parse($alert['date'])->format('M d, Y') }}{{ $alert['type'] ? ' &mdash; '.ucfirst($alert['type']) : '' }}).
                        Review before approving.
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Month filter --}}
        <div class="hrm-month-filter" style="margin-bottom:1rem;display:flex;align-items:center;gap:0.75rem;">
            <label for="leaveMonthPicker" style="font-size:0.85rem;color:#64748b;font-weight:500;">Month:</label>
            <input type="month" id="leaveMonthPicker" value="{{ $selectedMonth }}"
                style="border:1px solid #e2e8f0;border-radius:6px;padding:0.4rem 0.7rem;font-size:0.9rem;cursor:pointer;">
        </div>

        <div class="hrm-toolbar">
            <select id="leaveDepartment">
                <option value="">All Departments</option>
                @foreach($departments as $department)
                    <option value="{{ $department->Dept_id }}" @selected($leaveFilters['department'] == (string) $department->Dept_id)>{{ $department->Dept_name }}</option>
                @endforeach
            </select>
            <select id="leaveStatus">
                <option value="pending" @selected($leaveFilters['status'] === 'pending')>Pending</option>
                <option value="approved" @selected($leaveFilters['status'] === 'approved')>Approved</option>
                <option value="declined" @selected($leaveFilters['status'] === 'declined')>Rejected</option>
                <option value="all" @selected($leaveFilters['status'] === 'all')>All</option>
            </select>
            <button class="hrm-btn" id="leaveFilterBtn" type="button">Apply Filter</button>
        </div>

        <div class="hrm-chart-card" style="width:100%;">
            <h4>Leave Balances and Usage</h4>
            <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="leaveUsageChart"></canvas></div>
        </div>

        {{-- Leave Analytics Panels (Enhancement 3) --}}
        <div id="leaveAnalyticsPanel" class="hrm-analytics-panels" style="margin-top:2rem;">
            <div class="hrm-chart-card" style="margin-bottom:1.5rem;">
                <h4>Employees with Critical Balances <span style="font-size:0.8rem;font-weight:400;color:#64748b;">(VL &lt; 2 days or SL &lt; 2 days)</span></h4>
                <x-critical-balances-table :balances="$criticalBalances" />
            </div>

            <div class="hrm-chart-card">
                <h4>6-Month Leave Trend (Org-Wide)</h4>
                <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="leaveTrendChart"></canvas></div>
            </div>

            <div class="hrm-chart-card" style="margin-top:1.5rem;">
                <h4>Leave Usage by Department <span style="font-size:0.8rem;font-weight:400;color:#64748b;">(Selected Month)</span></h4>
                <div id="departmentComparisonTable"></div>
            </div>

            <div class="hrm-chart-card" style="margin-top:1.5rem;">
                <h4>AWOL / Overuse Risk</h4>
                <p class="muted" style="font-size:0.82rem;margin-bottom:0.75rem;">
                    Employees currently accumulating unauthorized absence (no attendance, and nothing on file to cover it).
                    Live snapshot as of today - not affected by the month filter above. Only employees with a current streak
                    of 5+ workdays are shown.
                </p>
                <div id="awolRiskTable"></div>
            </div>
        </div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
