<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('assets/login/mbs.jpg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page auth-page-plain">
    <main class="card">
        <h1>Set a New Password</h1>
        <p>You logged in with a default password. Please set a new password to continue.</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.force.update') }}">
            @csrf

            <div class="field">
                <label for="password">New Password</label>
                <input id="password" type="password" name="password" minlength="8" autocomplete="new-password" required>
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm New Password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" minlength="8" autocomplete="new-password" required>
            </div>

            <button type="submit" class="btn">Update Password</button>
        </form>
    </main>
</body>
</html>
