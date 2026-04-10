<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    /**
     * Redirect authenticated users to the password-change screen
     * when the force_password_change flag is active.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (bool) $user->force_password_change) {
            $allowed = [
                'password.force.edit',
                'password.force.update',
                'logout',
            ];

            if (! $request->routeIs(...$allowed)) {
                return redirect()->route('password.force.edit');
            }
        }

        return $next($request);
    }
}
