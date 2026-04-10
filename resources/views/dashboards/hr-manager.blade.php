@extends('dashboards.layout', [
    'title' => 'HR Manager Dashboard',
    'subtitle' => 'Supervise employee records, hiring activity, and HR approvals.',
])

@section('tiles')
    <article class="tile">
        <strong>HR Approval Center</strong>
        Handle policy, leave, and personnel approvals.
    </article>
    <article class="tile">
        <strong>Employee Records</strong>
        Review profile completeness and compliance items.
    </article>
    <article class="tile">
        <strong>Recruitment Pipeline</strong>
        Track open positions and applicant progress.
    </article>
@endsection
