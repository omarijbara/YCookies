<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentBlocker;
use App\Models\CookieGroup;
use App\Models\DiscoveredResource;
use App\Models\Domain;
use App\Models\ScriptBlocker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DiscoveryController extends Controller
{
    /**
     * Ingest discovered (blocked) resources reported by client-side manager.js.
     * Fire-and-forget: returns 204 immediately.
     */
    public function beacon(Request $request)
    {
        // Handle text/plain JSON payloads explicitly for CORS bypass
        $payload = is_array($request->json()->all()) && !empty($request->json()->all()) 
            ? $request->json()->all() 
            : json_decode($request->getContent(), true) ?? [];
        
        $request->merge($payload);

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'site_id' => 'required|string',
            'resources' => 'required|array|max:50',
            'resources.*.url' => 'required|string|max:2000',
            'resources.*.type' => 'required|string|in:script,style,service,content',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => 'Invalid payload'], 422);
        }

        $domain = Domain::withoutGlobalScopes()
            ->where('site_id', $request->input('site_id'))
            ->where('is_active', true)
            ->first();

        if (! $domain || ! $domain->group_id) {
            return response()->noContent();
        }

        $now = now();
        $autoBlocking = $domain->auto_blocking ?? [];

        $resources = $request->input('resources');

        foreach ($resources as $resource) {
            $providerHost = $this->extractProviderHost($resource['url'] ?? '');
            if (! $providerHost) {
                continue;
            }

            // Normalize service sub-types (fetch, xhr, beacon) → 'service'
            $type = $resource['type'];

            DiscoveredResource::withoutGlobalScopes()->upsert(
                [
                    'domain_id' => $domain->id,
                    'group_id' => $domain->group_id,
                    'provider_host' => $providerHost,
                    'resource_type' => $type,
                    'sample_url' => mb_substr($resource['url'], 0, 2000),
                    'status' => 'pending',
                    'hit_count' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['domain_id', 'provider_host', 'resource_type'],
                ['hit_count' => DB::raw('hit_count + 1'), 'last_seen_at' => $now, 'updated_at' => $now]
            );

            // Auto-create blockers when the per-type toggle is enabled
            $autoCreateKey = "auto_create_{$type}"; // auto_create_content, auto_create_script, auto_create_style
            if (! empty($autoBlocking[$autoCreateKey])) {
                $this->autoCreateBlocker($domain, $providerHost, $type, $resource['url'], $now);
            }
        }

        return response()->noContent();
    }

    /**
     * Auto-create a blocker for a newly discovered resource.
     * Idempotent: skips if a blocker with the same key already exists.
     */
    private function autoCreateBlocker(Domain $domain, string $providerHost, string $type, string $sampleUrl, $now): void
    {
        try {
            // Find the "uncategorized" cookie group for this tenant
            $uncategorized = CookieGroup::withoutGlobalScopes()
                ->where('group_id', $domain->group_id)
                ->where('key', 'uncategorized')
                ->first();

            if ($type === 'content') {
                $key = 'auto-content-' . Str::slug($providerHost);

                // Skip if already exists
                if (ContentBlocker::withoutGlobalScopes()->where('key', $key)->where('domain_id', $domain->id)->exists()) {
                    return;
                }

                $blocker = ContentBlocker::create([
                    'domain_id' => $domain->id,
                    'group_id' => $domain->group_id,
                    'key' => $key,
                    'name' => ['en' => $providerHost, 'de' => $providerHost],
                    'hosts' => [$providerHost],
                    'is_active' => true,
                    'provider_key' => Str::slug($providerHost),
                    'supports_accept_once' => true,
                    'supports_accept_provider' => true,
                ]);

                // Attach to the uncategorized cookie group if it exists
                if ($uncategorized && method_exists($blocker, 'cookieGroups')) {
                    $blocker->cookieGroups()->syncWithoutDetaching([$uncategorized->id]);
                }

                // Mark the discovered resource as resolved
                DiscoveredResource::withoutGlobalScopes()
                    ->where('domain_id', $domain->id)
                    ->where('provider_host', $providerHost)
                    ->where('resource_type', $type)
                    ->update([
                        'status' => 'resolved',
                        'resolved_at' => $now,
                        'resolved_to_type' => 'content_blocker',
                        'resolved_to_id' => $blocker->id,
                    ]);

                Log::info("[Discovery] Auto-created content blocker '{$key}' for {$domain->name}");

            } elseif (in_array($type, ['script', 'style'])) {
                $blockerType = $type === 'style' ? ScriptBlocker::TYPE_STYLE : ScriptBlocker::TYPE_SCRIPT;
                $key = "auto-{$type}-" . Str::slug($providerHost);

                // Skip if already exists
                if (ScriptBlocker::withoutGlobalScopes()->where('key', $key)->where('domain_id', $domain->id)->exists()) {
                    return;
                }

                $blocker = ScriptBlocker::create([
                    'domain_id' => $domain->id,
                    'group_id' => $domain->group_id,
                    'key' => $key,
                    'name' => ['en' => $providerHost, 'de' => $providerHost],
                    'phrases' => [$providerHost],
                    'is_active' => true,
                    'blocker_type' => $blockerType,
                    'require_group' => $uncategorized ? 'uncategorized' : 'marketing',
                ]);

                // Mark the discovered resource as resolved
                DiscoveredResource::withoutGlobalScopes()
                    ->where('domain_id', $domain->id)
                    ->where('provider_host', $providerHost)
                    ->where('resource_type', $type)
                    ->update([
                        'status' => 'resolved',
                        'resolved_at' => $now,
                        'resolved_to_type' => 'script_blocker',
                        'resolved_to_id' => $blocker->id,
                    ]);

                Log::info("[Discovery] Auto-created {$type} blocker '{$key}' for {$domain->name}");
            }
        } catch (\Throwable $e) {
            Log::warning("[Discovery] Auto-create failed for {$providerHost}: {$e->getMessage()}");
        }
    }

    /**
     * Extract the registrable domain from a URL.
     * e.g. "https://cdn.analytics.sneaky.com/tracker.js" → "sneaky.com"
     */
    private function extractProviderHost(string $url): ?string
    {
        try {
            $src = str_starts_with($url, '//') ? 'https:' . $url : $url;
            $parsed = parse_url($src);
            $host = $parsed['host'] ?? null;
            if (! $host) {
                return null;
            }

            $host = strtolower(preg_replace('/^www\./i', '', $host));
            $parts = explode('.', $host);

            return count($parts) > 2 ? implode('.', array_slice($parts, -2)) : $host;
        } catch (\Throwable) {
            return null;
        }
    }
}
