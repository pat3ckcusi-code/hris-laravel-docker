<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('assets/login/mbs.jpg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div class="app-shell">
        @include('partials.global-sidebar')

        <main class="card">
            <div class="row">
                <h1>Dashboard</h1>
                <span class="badge">Logged in as {{ auth()->user()->email }}</span>
            </div>
            <p>Welcome back, {{ auth()->user()->name }}. Your account is authenticated successfully.</p>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn">Logout</button>
            </form>
        </main>
    </div>
</body>
</html>
