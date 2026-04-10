@extends('dashboards.layout', [
    'title' => 'Audit Logs',
    'subtitle' => 'Track critical HR actions and access events.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module-card">
        <h3>Audit Logs</h3>
        <p>This module page is ready for activity logs, approval trails, and change history monitoring.</p>
    </section>
@endsection
