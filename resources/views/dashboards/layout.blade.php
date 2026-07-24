<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Dashboard' }}</title>
    @include('partials.pwa-head')
        <!-- Font Awesome for dashboard icons: prefer local copy, fall back to CDN if missing -->
        @if (file_exists(public_path('assets/fontawesome/css/all.min.css')))
            <link rel="stylesheet" href="{{ asset('assets/fontawesome/css/all.min.css') }}">
        @else
            <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
        @endif
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="{{ asset('vendor/datatables/jquery.dataTables.min.css') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/export-job.js'])
    @yield('page_head')
</head>
<body class="bg-gray-50 text-slate-900 min-h-screen">
    <div class="app-shell min-h-screen">
        @include('partials.global-sidebar')

        <main class="card">
            <div class="top mb-6">
                <h1 class="text-3xl font-semibold text-slate-900">{{ $title ?? 'Dashboard' }}</h1>
                @yield('top_actions')
            </div>

            <section class="grid">
                @yield('tiles')
            </section>

            @yield('content')
            @yield('modals')
        </main>
    </div>

    <!-- jQuery + DataTables JS (used by some dashboards) -->
    <script src="{{ asset('vendor/jquery/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('vendor/datatables/jquery.dataTables.min.js') }}"></script>

    @yield('page_scripts')
    @yield('page_scripts_after')
</body>
</html>
