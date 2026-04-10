@extends('dashboards.layout', [
    'title' => 'Leave Management',
    'subtitle' => 'Review, route, and monitor leave operations.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module-card">
        <h3>Leave Management</h3>
        <p>This module page is ready for leave oversight queues, balance checks, and approval audits.</p>
    </section>
@endsection
