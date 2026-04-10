@extends('dashboards.layout', [
    'title' => 'Administrative Officer Dashboard',
    'subtitle' => 'Coordinate operations and support requests efficiently.',
])

@section('tiles')
    <article class="tile">
        <strong>Document Routing</strong>
        Track incoming and outgoing administrative documents.
    </article>
    <article class="tile">
        <strong>Office Requests</strong>
        Manage logistics and office support tickets.
    </article>
    <article class="tile">
        <strong>Daily Tasks</strong>
        Review and update administrative assignments.
    </article>
@endsection
