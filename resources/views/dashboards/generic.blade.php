@extends('dashboards.layout', [
    'title' => 'Dashboard',
    'subtitle' => 'Your account is active, but no role-specific dashboard is mapped yet.',
])

@section('tiles')
    <article class="tile">
        <strong>Current Access Level</strong>
        {{ $role !== '' ? $role : 'not assigned' }}
    </article>
    <article class="tile">
        <strong>Account</strong>
        {{ $user->name ?? 'User' }}
    </article>
    <article class="tile">
        <strong>Next Step</strong>
        Contact HRIS administration to assign the correct access level.
    </article>
@endsection
