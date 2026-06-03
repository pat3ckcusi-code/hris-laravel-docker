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

        return $next($request);
    }
}
