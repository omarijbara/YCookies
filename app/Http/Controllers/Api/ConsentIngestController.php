<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentLog;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ConsentIngestController extends Controller
{
    /**
     * Log a consent event from the frontend SDK.
     * Called via POST /api/log-consent
     */
    public function log(Request $request)
    {
        // Beacon sends text/plain, so handle raw input
        $data = $request->json()->all() ?: json_decode($request->getContent(), true);

        if (!$data || !isset($data['site_id']) || !isset($data['consent'])) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        // Validate input to prevent abuse
        $validator = validator($data, [
            'site_id' => 'required|string|max:64',
            'uid' => 'nullable|string|max:64',
            'consent' => 'required|array',
            'consent.type' => 'required|string|in:all,essential,custom,explicit,renewed',
            // Widget sends groups as {key: bool} map (e.g. {"essential":true,"analytics":false})
            'consent.groups' => 'nullable|array|max:50',
            'consent.groups.*' => 'boolean',
            'consent.services' => 'nullable|array|max:200',
            'consent.services.*' => 'string|max:100',
            'cookie_version' => 'nullable|integer|min:1|max:10000',
            'tc_string' => 'nullable|string|min:10|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        // Validate Domain
        $domain = Domain::where('site_id', $data['site_id'])->where('is_active', true)->first();
        if (!$domain) {
            return response()->json(['status' => 'error', 'message' => 'Site not found or inactive'], 404);
        }

        // Extract consent details
        $consent = $data['consent'];
        $consentType = $consent['type'] ?? 'explicit'; // 'all', 'essential', 'custom', 'explicit', 'renewed'
        $groupsGranted = $consent['groups'] ?? [];
        $servicesGranted = $consent['services'] ?? [];

        // Build anonymized IP hash (GDPR compliant — never store raw IP)
        $ipHash = hash('sha256', $request->ip() . config('app.key'));

        // Save the consent log using the consolidated model
        ConsentLog::create([
            'domain_id' => $domain->id,
            'consent_uid' => $data['uid'] ?? bin2hex(random_bytes(16)),
            'ip_hash' => $ipHash,
            'user_agent' => substr($request->userAgent() ?? '', 0, 500),
            'consent_type' => $consentType,
            'cookie_version' => $data['cookie_version'] ?? $domain->consent_version ?? 1,
            'consents_granted' => $groupsGranted,
            'services_granted' => $servicesGranted,
            'tc_string' => $data['tc_string'] ?? null,
        ]);

        // Invalidate consent statistics cache
        Cache::forget("consent_stats:{$domain->id}");

        return response()->json(['status' => 'ok']);
    }
}
