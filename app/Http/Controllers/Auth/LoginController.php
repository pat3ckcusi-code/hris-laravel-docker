<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

class LoginController extends Controller
{
    public function show(): Response
    {
        return response()
            ->view('auth.login')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'max:72'],
        ]);

        $user = User::query()
            ->where('email', $credentials['email'])
            ->first();

        if ($user && strtolower((string) $user->Status) === 'inactive') {
            return back()
                ->with('inactive_account', true)
                ->onlyInput('email');
        }

        if ($user && strtolower((string) $user->Status) === 'separated') {
            return back()
                ->with('separated_account', true)
                ->onlyInput('email');
        }

        try {
            $authenticated = Auth::attempt($credentials, $request->boolean('remember'));
        } catch (RuntimeException $exception) {
            // Support existing legacy records that were not stored with bcrypt,
            // then upgrade them to bcrypt after successful verification.
            $authenticated = $this->attemptLegacyLogin(
                $credentials['email'],
                $credentials['password'],
                $request->boolean('remember')
            );
        }

        if ($authenticated) {
            $request->session()->regenerate();

            Log::info('Successful login', ['email' => $credentials['email'], 'ip' => $request->ip()]);

            $user = $request->user();
            if ($user && (bool) $user->force_password_change) {
                return redirect()->route('password.force.edit');
            }

            return redirect()->intended(route('dashboard'));
        }

        Log::warning('Failed login attempt', ['email' => $credentials['email'], 'ip' => $request->ip()]);

        return back()
            ->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])
            ->onlyInput('email');
    }

    private function attemptLegacyLogin(string $email, string $plainPassword, bool $remember): bool
    {
        $user = User::where('email', $email)->first();

        if (! $user) {
            return false;
        }

        $storedPassword = (string) $user->password;

        $isValidHashCheck = false;
        try {
            $isValidHashCheck = Hash::check($plainPassword, $storedPassword);
        } catch (RuntimeException $exception) {
            $isValidHashCheck = false;
        }

        $isValidLegacyPassword = $isValidHashCheck
            || hash_equals($storedPassword, $plainPassword)
            || password_verify($plainPassword, $storedPassword);

        if (! $isValidLegacyPassword) {
            return false;
        }

        $user->forceFill([
            'password' => Hash::make($plainPassword),
        ])->save();

        Auth::login($user, $remember);

        return true;
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function showForcePasswordChange(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! (bool) $user->force_password_change) {
            return redirect()->route('dashboard');
        }

        return view('auth.force-password-change');
    }

    public function updateForcePasswordChange(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! (bool) $user->force_password_change) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'password' => [
                'required',
                'string',
                'min:8',
                'max:72',
                'confirmed',
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    if (Hash::check((string) $value, (string) $user->password)) {
                        $fail('The new password must be different from your default password.');
                    }
                },
            ],
        ]);

        $user->forceFill([
            'password' => Hash::make((string) $validated['password']),
            'force_password_change' => false,
        ])->save();

        return redirect()->route('dashboard')->with('status', 'Password updated successfully.');
    }
}
