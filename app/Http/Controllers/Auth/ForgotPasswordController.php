<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function showLinkRequestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLinkEmail(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = $request->input('email');

        Log::info('[ForgotPassword] Reset link requested', ['email' => $email, 'ip' => $request->ip()]);

        try {
            $status = Password::sendResetLink(['email' => $email]);
        } catch (\Exception $e) {
            Log::error('[ForgotPassword] Exception while sending reset link', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'A mail error occurred. Please contact the HR office.']);
        }

        if ($status === Password::RESET_LINK_SENT) {
            Log::info('[ForgotPassword] Reset link sent successfully', ['email' => $email]);

            return back()->with('status', __($status));
        }

        Log::warning('[ForgotPassword] Reset link not sent', ['email' => $email, 'status' => $status]);

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => __($status)]);
    }
}
