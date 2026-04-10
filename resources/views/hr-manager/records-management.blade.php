@extends('dashboards.layout', [
    'title' => 'Records Management',
    'subtitle' => 'Manage employee records and HR document lifecycle.',
])

@section('page_head')
    @vite('resources/css/hr_manager.css')
@endsection

@section('content')
    <section class="hrm-module-card">
        <h3>Records Management</h3>
        <p>This module page is ready for employee records workflows, profile audits, and document indexing tools.</p>
    </section>
@endsection
