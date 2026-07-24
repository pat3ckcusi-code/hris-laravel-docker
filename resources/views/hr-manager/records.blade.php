@extends('dashboards.layout', [
    'title' => 'Records Management',
    'subtitle' => 'Employee profile registry with compliance and status tracking.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-module="records" data-planning-url="{{ route('hr-manager.records.planning-data') }}" data-csrf="{{ csrf_token() }}">

        {{-- Workforce Planning Panel (Enhancement 5) --}}
        <div class="hrm-planning-toggle" style="margin-bottom:1.25rem;">
            <button id="togglePlanningBtn" class="hrm-btn-secondary" type="button" style="font-size:0.85rem;">
                <i class="fas fa-chart-line"></i> Hide Workforce Insights
            </button>
        </div>

        <div id="workforcePlanningPanel" style="display:block;margin-bottom:2rem;">
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
                <h4>12-Month Hiring &amp; Separation Trend</h4>
                <small style="color:#64748b;">Click a bar to view the employees behind that month's count.</small>
                <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="hiringTrendChart"></canvas></div>
            </div>
        </div>

        <div class="modal-overlay" id="workforceTrendModal">
            <div class="modal-box wtm-modal-box">
                <button type="button" class="modal-close">&times;</button>
                <div class="wtm-modal-header">
                    <span class="wtm-modal-icon" id="workforceTrendModalIcon"><i class="fas fa-user"></i></span>
                    <div>
                        <h3 id="workforceTrendModalTitle" style="margin:0;"></h3>
                        <p id="workforceTrendModalSubtitle" class="wtm-modal-subtitle"></p>
                    </div>
                </div>
                <div class="hris-table-wrapper">
                    <table class="hris-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody id="workforceTrendModalBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
