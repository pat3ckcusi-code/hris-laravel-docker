@extends('dashboards.layout', [
    'title' => 'Employee Dashboard',
    'subtitle' => 'Centralized employee workspace for profile status, attendance, and leave actions.',
])

@php
    $displayName = trim((string) (($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
    $displayName = $displayName !== '' ? $displayName : (string) ($user->name ?? 'Employee');
@endphp

@section('tiles')
    <article class="tile metric-tile">
        <span class="metric-label">Agency Employee No.</span>
        <strong>{{ $user->EmpNo ?: 'NOT SET' }}</strong>
        <small>{{ $user->designation ?: 'Designation pending' }}</small>
    </article>

    <article class="tile metric-tile">
        <span class="metric-label">Department</span>
        <strong>{{ $user->Dept_id ? 'Assigned' : 'Unassigned' }}</strong>
        <small>{{ $user->Dept_id ? 'Department ID: ' . $user->Dept_id : 'Contact records office for assignment' }}</small>
    </article>

    <article class="tile metric-tile">
        <span class="metric-label">Access Role</span>
        <strong>{{ ucwords((string) ($role ?? 'Employee')) }}</strong>
        <small>Workspace permissions and modules</small>
    </article>
@endsection

@section('content')
    <section class="employee-dashboard" aria-label="Employee workspace">
        <article class="hero-panel">
            <div class="hero-copy">
                <p class="eyebrow">Employee overview</p>
                <h2>Welcome, {{ $displayName }}</h2>
                <p>
                    Use this dashboard to review your profile details, stay on top of attendance, and keep records up to date.
                </p>

                <div class="hero-actions" role="group" aria-label="Quick actions">
                    <button type="button" class="action-btn primary-action">File Leave Request</button>
                    <button type="button" class="action-btn secondary-action">Open Attendance</button>
                    <button type="button" class="action-btn ghost-action">Edit My Profile</button>
                </div>
            </div>
        </article>
    </section>
@endsection
