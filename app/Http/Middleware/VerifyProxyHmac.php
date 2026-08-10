<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify HMAC-SHA256 signature on inbound requests from the Node proxy.
 *
 * The proxy signs the request body with the shared secret and sends
 * the signature in the X-Signature header. This middleware validates it.
 *
 * Same shared secret as used for outbound config signing in ProxyConfigController.
 */
class VerifyProxyHmac
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.proxy.shared_secret');
        $prevSecret = config('services.proxy.shared_secret_prev');

        // If no secret is configured, skip verification (dev mode)
        if (empty($secret)) {
            return $next($request);
        }

        $signature = $request->header('X-Signature');
        if (!$signature) {
            return response()->json(['error' => 'Missing X-Signature header'], 401);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (hash_equals($expected, $signature)) {
            return $next($request);
        }

        // Check against the previous secret (grace period)
        if (!empty($prevSecret)) {
            $expectedPrev = hash_hmac('sha256', $request->getContent(), $prevSecret);
            if (hash_equals($expectedPrev, $signature)) {
                return $next($request);
            }
        }

        return response()->json(['error' => 'Invalid HMAC signature'], 403);
    }
}
