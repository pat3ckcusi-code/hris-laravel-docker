@extends('dashboards.layout', [
    'title' => 'System Settings',
    'subtitle' => 'Configure HR manager workspace defaults and controls.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module-card">
        <h3>System Settings</h3>
        <p>This module page is ready for dashboard preferences, alert settings, and module-level configuration.</p>
    </section>
@endsection
