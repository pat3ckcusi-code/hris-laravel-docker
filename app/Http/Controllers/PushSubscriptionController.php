<?php

namespace App\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PushSubscriptionController extends Controller
{
    /**
     * Host suffixes for the push services real browsers actually use. A push
     * endpoint's outbound send is otherwise SSRF-prone (WebPushChannel POSTs
     * straight to whatever endpoint a subscription row holds), so anything
     * outside this list is rejected before it's ever persisted.
     */
    private const ALLOWED_ENDPOINT_HOSTS = [
        'fcm.googleapis.com',                 // Chrome, Edge, Android
        'updates.push.services.mozilla.com',  // Firefox
        'notify.windows.com',                 // Windows/legacy Edge, e.g. wns2-xx1p.notify.windows.com
    ];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url', 'max:500', $this->allowedEndpointRule()],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $request->user()->updatePushSubscription(
            endpoint: $data['endpoint'],
            key: $data['keys']['p256dh'],
            token: $data['keys']['auth'],
        );

        return response()->json(['status' => 'subscribed']);
    }

    private function allowedEndpointRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $scheme = parse_url($value, PHP_URL_SCHEME);
            $host = parse_url($value, PHP_URL_HOST);

            if ($scheme === 'https' && $host !== null && $this->isAllowedHost($host)) {
                return;
            }

            Log::warning('Rejected push subscription with disallowed endpoint', [
                'user_id' => request()->user()?->id,
                'endpoint_scheme' => $scheme,
                'endpoint_host' => $host,
            ]);

            $fail('This push service is not supported.');
        };
    }

    private function isAllowedHost(string $host): bool
    {
        foreach (self::ALLOWED_ENDPOINT_HOSTS as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['status' => 'unsubscribed']);
    }
}
