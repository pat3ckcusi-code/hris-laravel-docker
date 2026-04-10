@extends('dashboards.layout', [
    'title' => 'Leave Manager Dashboard',
    'subtitle' => 'Oversee leave applications, balances, and policy compliance.',
])

@section('tiles')
    <article class="tile">
        <strong>Pending Leave Requests</strong>
        Process pending leave applications.
    </article>
    <article class="tile">
        <strong>Leave Balances</strong>
        Review employee leave credits and usage.
    </article>
    <article class="tile">
        <strong>Policy Alerts</strong>
        Monitor leave policy exceptions and escalations.
    </article>
@endsection
