@extends('dashboards.layout', [
    'title' => 'Access Management',
    'subtitle' => 'Review and maintain role-based access assignments.',
])

@section('tiles')
    <article class="tile">
        <strong>Total Managed Accounts</strong>
        {{ $employees->count() }} users with HRIS access.
    </article>
    <article class="tile">
        <strong>Role Variations</strong>
        {{ $accessSummary->count() }} access levels currently used.
    </article>
    <article class="tile">
        <strong>Confidentiality Control</strong>
        Keep least-privilege access to protect employee records.
    </article>
@endsection

@section('content')
    <section class="security-panel">
        <h2>Access Distribution</h2>
        <div class="distribution-grid">
            @forelse ($accessSummary as $role => $count)
                <article class="distribution-card">
                    <strong>{{ ucwords($role ?: 'unset') }}</strong>
                    <span>{{ $count }} account(s)</span>
                </article>
            @empty
                <p class="empty-state">No access data available.</p>
            @endforelse
        </div>
    </section>

    <section class="security-panel">
        <h2>Employee Type Distribution (Access Level: Employee)</h2>
        <div class="distribution-grid">
            @forelse ($employeeTypeSummary as $type => $count)
                <article class="distribution-card">
                    <strong>{{ $type }}</strong>
                    <span>{{ $count }} employee account(s)</span>
                </article>
            @empty
                <p class="empty-state">No employee-type data available.</p>
            @endforelse
        </div>
    </section>

    <section class="security-panel">
        <h2>Role Change Guidance</h2>
        <ul class="guidance-list">
            <li>Assign the lowest access level needed for the employee's function.</li>
            <li>Review role changes with HR Manager before applying sensitive-level access.</li>
            <li>Set inactive accounts to lower-privilege roles when no longer needed.</li>
        </ul>
    </section>
@endsection
