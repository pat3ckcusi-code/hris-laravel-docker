@extends('dashboards.layout', [
    'title' => 'Front Desk',
    'subtitle' => 'Coordinate front desk HR requests and service status.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module-card">
        <h3>Front Desk</h3>
        <p>This module page is ready for transaction monitoring, queue health, and request handoff tracking.</p>
    </section>
@endsection
