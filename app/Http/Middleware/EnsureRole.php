<?php

namespace App\Http\Middleware;

use App\Models\OicAssignment;
use App\Support\RoleNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            abort(403, 'Unauthorized.');
        }

        // Flatten comma-separated role strings into individual roles
        $flattenedRoles = [];
        foreach ($roles as $role) {
            $splitRoles = array_map('trim', explode(',', $role));
            $flattenedRoles = array_merge($flattenedRoles, $splitRoles);
        }

        $normalizedUserRole = $this->normalizeRole((string) ($request->user()->access_level ?? ''));
        $normalizedRoles = array_map(fn (string $role): string => $this->normalizeRole($role), $flattenedRoles);

        if (! in_array($normalizedUserRole, $normalizedRoles, true)) {
            $hasOic = OicAssignment::where('user_id', $request->user()->id)
                ->active()
                ->whereIn('role', $normalizedRoles)
                ->exists();

            // An HR Manager is unconditionally also a department head for their own department.
            $hrManagerActingAsDeptHead = $normalizedUserRole === 'hr manager'
                && in_array('department head', $normalizedRoles, true);

            if (! $hasOic && ! $hrManagerActingAsDeptHead) {
                abort(403, 'Unauthorized role access.');
            }
        }

        return $next($request);
    }

    private function normalizeRole(string $role): string
    {
        return RoleNormalizer::normalize($role);
    }
}
