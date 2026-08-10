<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyProxyConfigSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.proxy.shared_secret');
        $prevSecret = config('services.proxy.shared_secret_prev');

        if (empty($secret)) {
            return $next($request);
        }

        $signature = $request->header('X-Proxy-Signature');
        if (!$signature) {
            return response()->json(['error' => 'Missing X-Proxy-Signature header'], 401);
        }

        if (!preg_match('/^[0-9a-f]{64}$/i', $signature)) {
            return response()->json(['error' => 'Malformed proxy signature'], 400);
        }

        $host = strtolower(trim((string) $request->route('host')));

        if ($this->signatureMatches($signature, $host, $secret)) {
            return $next($request);
        }

        if (!empty($prevSecret) && $this->signatureMatches($signature, $host, $prevSecret)) {
            return $next($request);
        }

        return response()->json(['error' => 'Invalid proxy signature'], 403);
    }

    protected function signatureMatches(string $signature, string $host, string $secret): bool
    {
        $expected = hash_hmac('sha256', $host, $secret);

        return hash_equals(strtolower($expected), strtolower($signature));
    }
}
