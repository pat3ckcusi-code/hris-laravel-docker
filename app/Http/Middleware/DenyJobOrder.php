<?php

namespace App\Http\Middleware;

use App\Support\RoleNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyJobOrder
{
    /**
     * Block non-leave-eligible employee types (e.g. job order) from accessing
     * leave filing routes.
     *
     * Department Heads and HR Managers bypass this check regardless of
     * their employee_type, because they have admin authority over leave.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403, 'Unauthorized.');
        }

        $role = RoleNormalizer::normalize((string) ($user->access_level ?? ''));

        // Admin roles always pass through
        if (in_array($role, ['department head', 'hr manager', 'administrative officer'], true)) {
            return $next($request);
        }

        if (! $user->canFileLeave()) {
            abort(403, 'Your employee type is not eligible to file leave requests.');
        }

        return $next($request);
    }
}
