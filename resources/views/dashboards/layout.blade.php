<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }}</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('assets/login/mbs.jpg') }}">
        <!-- Font Awesome for dashboard icons: prefer local copy, fall back to CDN if missing -->
        @if (file_exists(public_path('assets/fontawesome/css/all.min.css')))
            <link rel="stylesheet" href="{{ asset('assets/fontawesome/css/all.min.css') }}">
        @else
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="" crossorigin="anonymous">
        @endif
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @yield('page_head')
</head>
<body class="bg-gray-50 text-slate-900 min-h-screen">
    <div class="app-shell min-h-screen">
        @include('partials.global-sidebar')

        <main class="card flex-1 max-w-7xl mx-auto mt-6 w-full bg-white p-6 rounded-3xl shadow-xl">
            <div class="top mb-6">
                <h1 class="text-3xl font-semibold text-slate-900">{{ $title ?? 'Dashboard' }}</h1>
                @yield('top_actions')
            </div>

            <p>{{ $subtitle ?? 'Welcome to your dashboard.' }}</p>

            <section class="grid">
                @yield('tiles')
            </section>

            @yield('content')
            @yield('modals')
        </main>
    </div>

    <!-- jQuery + DataTables JS (used by some dashboards) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

    @yield('page_scripts')
    @yield('page_scripts_after')

    @yield('page_scripts')
</body>
</html>
