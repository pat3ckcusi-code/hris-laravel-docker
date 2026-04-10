@extends('dashboards.layout', [
    'title' => 'Time Keeper Dashboard',
    'subtitle' => 'Validate attendance logs and keep time records accurate.',
])

@section('tiles')
    <article class="tile">
        <strong>Attendance Validation</strong>
        Review and verify daily time logs.
    </article>
    <article class="tile">
        <strong>Missing Entries</strong>
        Resolve incomplete or irregular time records.
    </article>
    <article class="tile">
        <strong>Cutoff Monitoring</strong>
        Track deadlines for timesheet completion.
    </article>
@endsection
