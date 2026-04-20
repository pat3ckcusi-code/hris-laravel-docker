<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user()) {
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

        if (!in_array($normalizedUserRole, $normalizedRoles, true)) {
            abort(403, 'Unauthorized role access.');
        }

        return $next($request);
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        $normalized = str_replace(['_', '-'], ' ', $normalized);

        return preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
    }
}
