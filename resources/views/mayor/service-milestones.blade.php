@extends('dashboards.layout', [
    'title' => 'Service Milestones',
    'subtitle' => 'Employees reaching a 10, 15, 20, 25, or 30+ year service anniversary within this year, city-wide.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-module="service-milestones" data-planning-url="{{ $planningDataUrl }}">
        <div class="ms-cards" id="msCards">
            <button type="button" class="ms-card ms-tier-10" id="msYear10" data-year="10" aria-pressed="false">
                <span class="ms-card-icon"><i class="fas fa-medal"></i></span>
                <p>10 Years</p>
                <h3>&mdash;</h3>
            </button>
            <button type="button" class="ms-card ms-tier-15" id="msYear15" data-year="15" aria-pressed="false">
                <span class="ms-card-icon"><i class="fas fa-award"></i></span>
                <p>15 Years</p>
                <h3>&mdash;</h3>
            </button>
            <button type="button" class="ms-card ms-tier-20" id="msYear20" data-year="20" aria-pressed="false">
                <span class="ms-card-icon"><i class="fas fa-trophy"></i></span>
                <p>20 Years</p>
                <h3>&mdash;</h3>
            </button>
            <button type="button" class="ms-card ms-tier-25" id="msYear25" data-year="25" aria-pressed="false">
                <span class="ms-card-icon"><i class="fas fa-crown"></i></span>
                <p>25 Years</p>
                <h3>&mdash;</h3>
            </button>
            <button type="button" class="ms-card ms-tier-30" id="msYear30" data-year="30" aria-pressed="false">
                <span class="ms-card-icon"><i class="fas fa-gem"></i></span>
                <p>30+ Years</p>
                <h3>&mdash;</h3>
            </button>
        </div>

        <div class="hrm-chart-card ms-table-card">
            <h4>
                <i class="fas fa-star ms-heading-icon" aria-hidden="true"></i> Upcoming Service Milestones
                <span style="font-size:0.8rem;font-weight:400;color:#64748b;">(10, 15, 20, 25, 30+ years - within this year)</span>
                <button type="button" id="msClearFilter" class="ms-clear-filter" style="display:none;">
                    <i class="fas fa-xmark" aria-hidden="true"></i> Clear filter
                </button>
            </h4>
            <div id="milestonesTable">
                <p style="color:#94a3b8;font-style:italic;padding:1rem 0;">Loading&hellip;</p>
            </div>
        </div>
    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
