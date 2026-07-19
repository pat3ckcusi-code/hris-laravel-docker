<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password</title>
    @include('partials.pwa-head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page auth-page-login" style="background-image: url('{{ asset('assets/login/bg.jpg') }}');">
    <main class="card">
        <h2>Forgot Password</h2>
        <p>Enter your email address and we will send a password reset link.</p>

        @if (session('status'))
            <div class="msg">{{ session('status') }}</div>
        @endif

        @error('email')
            <div class="error">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" class="input" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
            <button class="btn" type="submit">Send Reset Link</button>
        </form>

        <div class="link">
            <a href="{{ route('login') }}">Back to login</a>
        </div>
    </main>
</body>
</html>
