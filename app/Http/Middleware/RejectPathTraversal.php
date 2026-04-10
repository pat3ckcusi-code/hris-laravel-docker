<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RejectPathTraversal
{
    public function handle(Request $request, Closure $next): Response
    {
        $uri = rawurldecode($request->getRequestUri());
        $fullUrl = rawurldecode($request->fullUrl());

        // Block path traversal sequences and null bytes
        $patterns = ['../', '..\\', '%2e%2e', '%252e', '\0', '%00'];

        foreach ($patterns as $pattern) {
            if (stripos($uri, $pattern) !== false || stripos($fullUrl, $pattern) !== false) {
                Log::warning('Path traversal attempt blocked', [
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                    'user_id' => $request->user()?->id,
                ]);

                abort(400, 'Bad Request');
            }
        }

        // Block null bytes in all input values
        $allInput = $request->all();
        array_walk_recursive($allInput, function ($value) use ($request) {
            if (is_string($value) && str_contains($value, "\0")) {
                Log::warning('Null byte injection attempt blocked', [
                    'ip' => $request->ip(),
                    'uri' => $request->getRequestUri(),
                    'user_id' => $request->user()?->id,
                ]);

                abort(400, 'Bad Request');
            }
        });

        return $next($request);
    }
}
