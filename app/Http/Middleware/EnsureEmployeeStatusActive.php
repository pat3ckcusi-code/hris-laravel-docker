<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeStatusActive
{
    /**
     * Force-logout an authenticated user whose Status becomes Inactive/Separated
     * mid-session. LoginController already blocks a fresh login attempt for such
     * accounts; this closes the gap for sessions that were already active.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ($user->isInactive() || $user->isSeparated())) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status_terminated', true);
        }

        return $next($request);
    }
}
