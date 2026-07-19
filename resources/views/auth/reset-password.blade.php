<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password</title>
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page auth-page-login" style="background-image: url('{{ asset('assets/login/bg.jpg') }}');">
    <main class="card">
        <h2>Reset Password</h2>
        <p>Set your new password below.</p>

        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="field">
                <label for="email">Email</label>
                <input id="email" class="input" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email">
            </div>

            <div class="field">
                <label for="password">New Password</label>
                <input id="password" class="input" type="password" name="password" required autocomplete="new-password">
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm Password</label>
                <input id="password_confirmation" class="input" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button class="btn" type="submit">Reset Password</button>
        </form>
    </main>
</body>
</html>
