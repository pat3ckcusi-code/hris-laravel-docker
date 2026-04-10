@extends('dashboards.layout', [
    'title' => 'Mayor Dashboard',
    'subtitle' => 'View high-level HR and workforce status across the organization.',
])

@section('tiles')
    <article class="tile">
        <strong>Executive Snapshot</strong>
        Summary of attendance, staffing, and key HR metrics.
    </article>
    <article class="tile">
        <strong>Critical Approvals</strong>
        Review approvals requiring executive action.
    </article>
    <article class="tile">
        <strong>Department Performance</strong>
        Compare service and staffing indicators.
    </article>
@endsection
