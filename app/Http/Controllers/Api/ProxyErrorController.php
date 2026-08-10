<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Jobs\ForwardErrorsToImprove;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

class ProxyErrorController extends Controller
{
    /**
     * Receive batched errors from the Node proxy.
     * Uses the same HMAC signature validation as the metrics/config endpoints.
     */
    public function ingest(Request $request)
    {
        $signature = $request->header('X-Signature');
        $secret = config('services.proxy.shared_secret');

        if ($secret && $signature) {
            $computed = hash_hmac('sha256', $request->getContent(), $secret);
            if (!hash_equals($signature, $computed)) {
                Log::warning('[Error Bridge] Invalid signature from proxy error batch', [
                    'ip' => $request->ip()
                ]);
                return response()->json(['error' => 'Invalid signature'], 403);
            }
        }

        $errors = $request->input('errors', []);
        
        if (!empty($errors)) {
            // Filter to ensure site_ids exist (if applicable to the error source)
            $validErrors = array_filter($errors, function ($error) {
                // For browser errors, we check site_id
                if (isset($error['context']['site_id'])) {
                    return \App\Models\Site::where('id', $error['context']['site_id'])->exists();
                }
                return true; // Node proxy internal errors don't have site_id
            });

            if (!empty($validErrors)) {
                ForwardErrorsToImprove::dispatch($validErrors);
            }
        }

        return response()->json(['status' => 'buffered']);
    }
}
