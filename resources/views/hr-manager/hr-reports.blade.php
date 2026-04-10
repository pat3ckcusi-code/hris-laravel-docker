@extends('dashboards.layout', [
    'title' => 'HR Reports',
    'subtitle' => 'Generate and review HR operational reports.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module-card">
        <h3>HR Reports</h3>
        <p>This module page is ready for workforce, attendance, and compliance reporting outputs.</p>
    </section>
@endsection
