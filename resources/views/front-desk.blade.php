@extends('dashboards.layout', [
    'title' => 'Document Request Control Center',
    'subtitle' => 'Review, filter, print, and update document request workflows from one front desk console.',
])

@section('page_head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/front_desk.css', 'resources/js/front_desk.js'])
@endsection

@php
    $fdBadgeClass = fn (string $status) => match ($status) {
        'Requested' => 'badge-requested',
        'Accepted' => 'badge-approved',
        'Completed' => 'badge-completed',
        'Rejected' => 'badge-default',
        default => 'badge-default',
    };
@endphp

@section('content')
    <div class="request-control-page">
        <section class="fd-hero">
            <div class="fd-hero-title">
                <span class="fd-hero-icon"><i class="fas fa-inbox"></i></span>
                <div>
                    <h2>Welcome back, {{ auth()->user()->name ?: 'Front Desk' }}</h2>
                    <p>Here's what's happening with document requests today.</p>
                </div>
            </div>
            <span class="fd-hero-meta"><i class="far fa-calendar"></i> {{ now()->format('l, F j, Y') }}</span>
        </section>

        <section class="summary-grid" aria-label="Request summary">
            <article class="summary-card summary-total">
                <span class="summary-icon"><i class="fas fa-layer-group"></i></span>
                <div>
                    <span class="summary-label">Total Requests</span>
                    <strong>{{ $summary['total'] }}</strong>
                </div>
            </article>
            <article class="summary-card summary-pending">
                <span class="summary-icon"><i class="fas fa-hourglass-half"></i></span>
                <div>
                    <span class="summary-label">Pending</span>
                    <strong>{{ $summary['pending'] }}</strong>
                </div>
            </article>
            <article class="summary-card summary-approved">
                <span class="summary-icon"><i class="fas fa-check-circle"></i></span>
                <div>
                    <span class="summary-label">Approved</span>
                    <strong>{{ $summary['approved'] }}</strong>
                </div>
            </article>
            <article class="summary-card summary-completed">
                <span class="summary-icon"><i class="fas fa-flag-checkered"></i></span>
                <div>
                    <span class="summary-label">Completed</span>
                    <strong>{{ $summary['completed'] }}</strong>
                </div>
            </article>
        </section>

        <section class="fd-quick-actions" aria-label="Quick actions">
            <a href="{{ route('employee.pending-requests') }}" class="fd-quick-action">
                <span class="fd-quick-action-icon"><i class="fas fa-hourglass-half"></i></span>
                <div class="fd-quick-action-body">
                    <strong>Pending Requests</strong>
                    <span>Review and process new document requests.</span>
                </div>
                <i class="fas fa-arrow-right fd-quick-action-arrow"></i>
            </a>
            <a href="{{ route('employee.approved-requests') }}" class="fd-quick-action">
                <span class="fd-quick-action-icon"><i class="fas fa-check-circle"></i></span>
                <div class="fd-quick-action-body">
                    <strong>Approved Requests</strong>
                    <span>Manage accepted and completed requests.</span>
                </div>
                <i class="fas fa-arrow-right fd-quick-action-arrow"></i>
            </a>
            <a href="{{ route('employee.document-settings') }}" class="fd-quick-action">
                <span class="fd-quick-action-icon"><i class="fas fa-cog"></i></span>
                <div class="fd-quick-action-body">
                    <strong>Document Settings</strong>
                    <span>Configure document types and templates.</span>
                </div>
                <i class="fas fa-arrow-right fd-quick-action-arrow"></i>
            </a>
        </section>

        <section class="tile table-tile">
            <div class="table-header-row">
                <h2><i class="fas fa-clock-rotate-left"></i> Recent Activity</h2>
            </div>
            <div class="fd-recent-list">
                @forelse($recentRequests as $request)
                    <div class="fd-recent-row">
                        <span class="fd-recent-name">
                            {{ $request['employee_name'] }}
                            <span class="fd-recent-sub">{{ $request['department'] }}</span>
                        </span>
                        <span class="fd-recent-type">{{ $request['document_type'] }}</span>
                        <span class="request-badge {{ $fdBadgeClass($request['status']) }}">{{ $request['status'] }}</span>
                        <span class="fd-recent-date">{{ $request['requested_on'] }}</span>
                    </div>
                @empty
                    <p class="hris-empty-state">No document requests yet.</p>
                @endforelse
            </div>
        </section>
    </div>
@endsection
