@extends('dashboards.layout', [
    'title' => 'Change Password',
    'subtitle' => 'Update your account password.',
])

@section('content')
    <section class="change-password-section" style="max-width: 480px;">
        @if ($errors->any())
            <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: 12px 16px; border-radius: 6px; margin-bottom: 16px;">
                <ul style="margin: 0; padding-left: 20px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('user.change-password') }}" class="record-form">
            @csrf

            <label>
                Current Password
                <input type="password" name="current_password" required autocomplete="current-password">
            </label>

            <label>
                New Password
                <input type="password" name="new_password" required minlength="8" autocomplete="new-password">
            </label>

            <label>
                Confirm New Password
                <input type="password" name="new_password_confirmation" required minlength="8" autocomplete="new-password">
            </label>

            <p class="create-note">Password must be at least 8 characters.</p>

            <button type="submit" class="record-btn">Update Password</button>
        </form>
    </section>
@endsection
