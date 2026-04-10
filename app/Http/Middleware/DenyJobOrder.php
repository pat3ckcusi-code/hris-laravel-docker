<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DenyJobOrder
{
    /**
     * Only allow leave-eligible employee types through.
     *
     * Department Heads and HR Managers are always allowed,
     * regardless of employee_type.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403, 'Unauthorized.');
        }

        $role = strtolower(trim(str_replace(['_', '-'], ' ', (string) ($user->access_level ?? ''))));

        // Department Heads and HR Managers always have access
        if (in_array($role, ['department head', 'hr manager'], true)) {
            return $next($request);
        }

        // Block employees whose type is not leave-eligible
        if (!$user->canFileLeave()) {
            abort(403, 'Your employee type is not eligible for leave requests.');
        }

        return $next($request);
    }
}
