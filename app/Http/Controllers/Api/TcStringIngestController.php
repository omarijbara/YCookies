<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentLog;
use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TcStringIngestController extends Controller
{
    /**
     * Record a TC string from the client-side CMP.
     * POST /api/tcf/record
     *
     * This creates an audit trail of every TC string generated,
     * linked to consent logs for GDPR compliance.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->json()->all() ?: json_decode($request->getContent(), true);

        if (!$data) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        $validator = validator($data, [
            'site_id'   => 'required|string|max:64',
            'uid'       => 'nullable|string|max:64',
            'tc_string' => 'required|string|min:10|max:10000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Validate domain
        $domain = Domain::where('site_id', $data['site_id'])
            ->where('is_active', true)
            ->first();

        if (!$domain) {
            return response()->json(['status' => 'error', 'message' => 'Site not found'], 404);
        }

        // Find the latest consent log for this UID and attach TC string
        if (!empty($data['uid'])) {
            $log = ConsentLog::where('consent_uid', $data['uid'])
                ->where('domain_id', $domain->id)
                ->where('is_latest', true)
                ->first();

            if ($log) {
                $log->update(['tc_string' => $data['tc_string']]);

                return response()->json(['status' => 'ok', 'action' => 'updated']);
            }
        }

        // No matching consent log found — create a standalone TC record
        ConsentLog::create([
            'domain_id'        => $domain->id,
            'consent_uid'      => $data['uid'] ?? bin2hex(random_bytes(16)),
            'ip_hash'          => hash('sha256', $request->ip() . config('app.key')),
            'user_agent'       => substr($request->userAgent() ?? '', 0, 500),
            'consent_type'     => 'tcf_record',
            'cookie_version'   => $domain->consent_version ?? 1,
            'consents_granted' => [],
            'services_granted' => [],
            'tc_string'        => $data['tc_string'],
        ]);

        return response()->json(['status' => 'ok', 'action' => 'created']);
    }
}
