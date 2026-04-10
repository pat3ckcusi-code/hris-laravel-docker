@extends('dashboards.layout', [
    'title' => 'User Roles & Access',
    'subtitle' => 'Define access permissions for HR operations.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module-card">
        <h3>User Roles &amp; Access</h3>
        <p>This module page is ready for role matrix management and permission policy updates.</p>
    </section>
@endsection
