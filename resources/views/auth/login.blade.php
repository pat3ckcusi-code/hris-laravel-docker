<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HRIS Login</title>
    <link rel="icon" type="image/jpeg" href="{{ asset('assets/login/mbs.jpg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="auth-page auth-page-login" style="background-image: url('{{ asset('assets/login/bg.jpg') }}');">
    <main class="login-shell">
        <section class="card">
            <h2>Sign in to your account</h2>
            <p>Use your {{ $settings?->system_name ?? 'HRIS' }} credentials to continue.</p>

            @if ($errors->any())
                <div class="error">{{ $errors->first() }}</div>
            @endif

            @if (session('status'))
                <div class="success">{{ session('status') }}</div>
            @endif

            <form method="POST" action="{{ route('login.submit') }}">
                @csrf

                <div class="field">
                    <label for="email">Email</label>
                    <input
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        class="input"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input
                        id="password"
                        type="password"
                        name="password"
                        class="input"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <div class="meta">
                    <label class="remember" for="remember">
                        <input id="remember" type="checkbox" name="remember" value="1">
                        Remember me
                    </label>
                    <a href="{{ route('password.request') }}">Forgot password?</a>
                </div>

                <button type="submit" class="btn">Login</button>
            </form>

            <div class="logos">
                <img src="{{ asset('assets/login/Calapan_City_Logo.png') }}" alt="Calapan City Logo">
                <img src="{{ asset('assets/login/chrmd1.png') }}" alt="CHRMD Logo">
                <img src="{{ asset('assets/login/mbs.jpg') }}" alt="MBS Logo">
            </div>
        </section>
    </main>

    @if (session('inactive_account'))
        <script>
            Swal.fire({
                icon: 'warning',
                title: 'Inactive Account',
                text: 'Your account is inactive. Please contact the {{ $settings?->org_name ?? 'City Human Resource Department' }}{{ $settings?->support_email ? ' at ' . $__settings->support_email : '' }}.',
                confirmButtonColor: '#ea580c',
            });
        </script>
    @endif
    @if (session('separated_account'))
        <script>
            Swal.fire({
                icon: 'warning',
                title: 'Separated Account',
                text: 'Your account is separated and can no longer access the system. Please contact the {{ $settings?->org_name ?? 'City Human Resource Department' }}{{ $settings?->support_email ? ' at ' . $__settings->support_email : '' }}.',
                confirmButtonColor: '#ea580c',
            });
        </script>
    @endif
</body>
</html>
