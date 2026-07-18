@extends('dashboards.layout', [
    'title' => 'Workforce Insights',
    'subtitle' => 'Headcount hiring and separation trends, city-wide.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-module="mayor-workforce-insights" data-planning-url="{{ $planningDataUrl }}">
        <div id="workforcePlanningPanel">
            <div class="hrm-chart-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.5rem;">
                <article class="hrm-summary-card" id="planHired">
                    <p>Hired (Last 30 Days)</p>
                    <h3>&mdash;</h3>
                    <small style="color:#64748b;">&mdash;</small>
                </article>
                <article class="hrm-summary-card" id="planSeparated">
                    <p>Separated (Last 30 Days)</p>
                    <h3>&mdash;</h3>
                    <small style="color:#64748b;">&mdash;</small>
                </article>
                <article class="hrm-summary-card" id="planNet">
                    <p>Net Headcount Change</p>
                    <h3>&mdash;</h3>
                </article>
            </div>

            <div class="hrm-chart-card">
                <h4>12-Month Hiring Trend</h4>
                <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="hiringTrendChart"></canvas></div>
            </div>
        </div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
