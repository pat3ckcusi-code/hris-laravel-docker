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
            $today = now()->toDateString();
            $hasOic = OicAssignment::where('user_id', $request->user()->id)
                ->whereDate('start_date', '<=', $today)
                ->whereDate('end_date', '>=', $today)
                ->whereIn('role', $normalizedRoles)
                ->exists();

            if (! $hasOic) {
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
