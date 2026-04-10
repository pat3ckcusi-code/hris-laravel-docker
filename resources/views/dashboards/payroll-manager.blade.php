@extends('dashboards.layout', [
    'title' => 'Payroll Manager Dashboard',
    'subtitle' => 'Prepare payroll runs and verify compensation records.',
])

@section('tiles')
    <article class="tile">
        <strong>Payroll Run Status</strong>
        Monitor current payroll cycle milestones.
    </article>
    <article class="tile">
        <strong>Earnings and Deductions</strong>
        Review salary adjustments and deductions.
    </article>
    <article class="tile">
        <strong>Payroll Exceptions</strong>
        Resolve flagged payroll discrepancies.
    </article>
@endsection
