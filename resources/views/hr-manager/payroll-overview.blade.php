@extends('dashboards.layout', [
    'title' => 'Payroll Overview',
    'subtitle' => 'Monitor payroll run status, exceptions, and net pay distribution.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module" data-url="{{ $payrollDataUrl }}" data-resolve-url="{{ $resolveBaseUrl }}" data-csrf="{{ csrf_token() }}">

        {{-- Run Status Cards --}}
        <h4 style="margin-bottom:1rem;">Recent Payroll Runs</h4>
        <div id="payrollRunCards" class="hrm-summary-grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));margin-bottom:2rem;">
            <p style="color:#94a3b8;font-style:italic;grid-column:1/-1;">Loading&hellip;</p>
        </div>

        {{-- Exceptions Table --}}
        <div class="hrm-chart-card" style="margin-bottom:1.5rem;">
            <h4>Unresolved Exceptions <span style="font-size:0.8rem;font-weight:400;color:#64748b;">(current run)</span></h4>
            <div class="hrm-table-wrap">
                <table class="hrm-table" id="payrollExceptionsTable">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="4" style="text-align:center;color:#94a3b8;font-style:italic;">Loading&hellip;</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Net Pay by Department Chart --}}
        <div class="hrm-chart-card">
            <h4>Net Pay by Department <span style="font-size:0.8rem;font-weight:400;color:#64748b;">(latest locked run)</span></h4>
            <div class="hrm-chart-wrap hrm-chart-wrap-sm"><canvas id="deptNetPayChart"></canvas></div>
        </div>

    </section>
@endsection

@section('page_scripts')
    @vite('resources/js/hr_manager.js')
@endsection
