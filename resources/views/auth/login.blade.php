<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HRIS Login</title>
    @include('partials.pwa-head')
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
                        class="input"
                        autocomplete="off"
                        autocorrect="off"
                        autocapitalize="none"
                        spellcheck="false"
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

            <div class="link install-app-link">
                <a href="#" id="pwa-install-link">Click here to download the app</a>
            </div>
        </section>
    </main>

    @if (session('inactive_account'))
        <script>
            Swal.fire({
                icon: 'warning',
                title: 'Inactive Account',
                text: 'Your account is inactive. Please contact the {{ $settings?->org_name ?? 'City Human Resource Department' }}{{ $settings?->support_email ? ' at ' . $settings->support_email : '' }}.',
                confirmButtonColor: '#ea580c',
            });
        </script>
    @endif
    @if (session('separated_account'))
        <script>
            Swal.fire({
                icon: 'warning',
                title: 'Separated Account',
                text: 'Your account is separated and can no longer access the system. Please contact the {{ $settings?->org_name ?? 'City Human Resource Department' }}{{ $settings?->support_email ? ' at ' . $settings->support_email : '' }}.',
                confirmButtonColor: '#ea580c',
            });
        </script>
    @endif
    @if (session('status_terminated'))
        <script>
            Swal.fire({
                icon: 'info',
                title: 'Signed Out',
                text: 'Your account status has changed. You have been signed out - please contact the {{ $settings?->org_name ?? 'City Human Resource Department' }}{{ $settings?->support_email ? ' at ' . $settings->support_email : '' }} if you believe this is an error.',
                confirmButtonColor: '#ea580c',
            });
        </script>
    @endif

    <script>
        (function () {
            var installLink = document.getElementById('pwa-install-link');
            if (!installLink) return;

            var isStandalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;

            if (isStandalone) {
                installLink.closest('.install-app-link').hidden = true;
                return;
            }

            var isIOS = /iPad|iPhone|iPod/.test(window.navigator.userAgent);
            var deferredPrompt = null;
            var installRequested = false;

            function beginInstall() {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.finally(function () {
                    deferredPrompt = null;
                    installRequested = false;
                });
            }

            window.addEventListener('beforeinstallprompt', function (event) {
                event.preventDefault();
                deferredPrompt = event;

                if (installRequested) {
                    beginInstall();
                }
            });

            installLink.addEventListener('click', function (event) {
                event.preventDefault();

                Swal.fire({
                    icon: 'question',
                    title: 'Install HRIS?',
                    text: 'This installs HRIS as an app on your device so you can launch it directly, without opening a browser.',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Install',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#ea580c',
                }).then(function (result) {
                    if (!result.isConfirmed) return;

                    if (deferredPrompt) {
                        beginInstall();
                        return;
                    }

                    if (isIOS) {
                        Swal.fire({
                            icon: 'info',
                            title: 'Install HRIS',
                            html: 'Automatic app install is available for <strong>Android</strong> devices only at this time.',
                            confirmButtonColor: '#ea580c',
                        });
                        return;
                    }

                    installRequested = true;
                    Swal.fire({
                        toast: true,
                        position: 'top',
                        icon: 'info',
                        title: 'Getting things ready - the install will start automatically.',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                    });
                });
            });
        })();
    </script>
</body>
</html>
