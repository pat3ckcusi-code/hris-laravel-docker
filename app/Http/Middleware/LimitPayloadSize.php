<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LimitPayloadSize
{
    /**
     * Maximum payload size in bytes (10MB default)
     */
    protected int $maxSize = 10 * 1024 * 1024;

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);

        if ($contentLength > $this->maxSize) {
            Log::warning('Payload size limit exceeded', [
                'ip' => $request->ip(),
                'size' => $contentLength,
                'limit' => $this->maxSize,
                'uri' => $request->getRequestUri(),
                'user_id' => $request->user()?->id,
            ]);

            return response(
                json_encode(['error' => 'Payload too large. Maximum size: ' . ($this->maxSize / 1024 / 1024) . 'MB']),
                413,
                ['Content-Type' => 'application/json']
            );
        }

        return $next($request);
    }
}
