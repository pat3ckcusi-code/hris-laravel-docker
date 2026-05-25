@extends('dashboards.layout', [
    'title' => 'Document Request Control Center',
    'subtitle' => 'Review, filter, print, and update document request workflows from one front desk console.',
])

@section('page_head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/front_desk.css', 'resources/js/front_desk.js'])
@endsection

@section('content')
    <div class="request-control-page">
        <section class="summary-grid" aria-label="Request summary">
            <article class="summary-card summary-total">
                <span class="summary-label">Total Requests</span>
                <strong>{{ $summary['total'] }}</strong>
            </article>
            <article class="summary-card summary-completed">
                <span class="summary-label">Completed</span>
                <strong>{{ $summary['completed'] }}</strong>
            </article>
        </section>

        <section class="tile">
            <div class="tile-header">
                <h2 style="margin: 0;">Dashboard Overview</h2>
            </div>
            <div class="tile-content">
                <p>Use the sidebar navigation to manage document requests:</p>
                <ul style="margin: 20px 0; padding-left: 20px;">
                    <li><strong>Pending Requests</strong> - Review and process new document requests</li>
                    <li><strong>Approved Requests</strong> - Manage accepted and completed requests</li>
                    <li><strong>Document Settings</strong> - Configure document types and settings</li>
                </ul>
            </div>
        </section>
    </div>
@endsection
