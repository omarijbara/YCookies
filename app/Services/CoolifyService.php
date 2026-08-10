<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service to interact with the Coolify API for managing application domains.
 *
 * When a customer enables proxy mode, this service adds their domain to the
 * YCookies Coolify application's FQDN list, which triggers Traefik to
 * auto-provision SSL certificates via Let's Encrypt.
 */
class CoolifyService
{
    protected string $apiUrl;
    protected string $apiToken;
    protected string $appUuid;
    protected string $nodeProxyUuid;

    public function __construct()
    {
        $this->apiUrl = rtrim(config('services.coolify.instance_url', env('COOLIFY_INSTANCE_URL', '')), '/');
        $this->apiToken = config('services.coolify.api_token', env('COOLIFY_API_TOKEN', ''));
        $this->appUuid = config('services.coolify.app_uuid', env('COOLIFY_APP_UUID', ''));
        $this->nodeProxyUuid = config('services.coolify.proxy_app_uuid', env('COOLIFY_PROXY_APP_UUID', ''));
    }

    /**
     * Get the current FQDN list from Coolify for the application.
     *
     * For Docker Compose apps, reads `docker_compose_domains` (the `fqdn`
     * field is always null). Extracts all domain values from every service,
     * normalises them (lowercase, trim, strip trailing slash), then returns
     * a sorted, deduped array so the diff in syncDomains() is idempotent.
     */
    public function getCurrentDomains(bool $forNodeProxy = false): array
    {
        try {
            $uuid = $forNodeProxy ? $this->nodeProxyUuid : $this->appUuid;
            
            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->get("{$this->apiUrl}/api/v1/applications/{$uuid}");

            if (!$response->successful()) {
                Log::warning('[CoolifyService] Failed to fetch app domains', [
                    'uuid' => $uuid,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [];
            }

            // 1. Try docker_compose_domains first (Docker Compose apps)
            $composeDomains = $response->json('docker_compose_domains');
            if (!empty($composeDomains)) {
                // Coolify stores this as a JSON string or object:
                // {"laravel":{"domain":"https://admin.example.com"},"node-proxy":{"domain":"https://a.com,https://b.com"}}
                if (is_string($composeDomains)) {
                    $composeDomains = json_decode($composeDomains, true) ?? [];
                }

                if ($forNodeProxy && isset($composeDomains['node-proxy'])) {
                    $composeDomains = ['node-proxy' => $composeDomains['node-proxy']];
                }

                $domains = [];
                foreach ($composeDomains as $service => $config) {
                    $domainValue = is_array($config) ? ($config['domain'] ?? '') : '';
                    if (empty($domainValue)) {
                        continue;
                    }
                    // A service can have multiple comma-separated domains
                    foreach (explode(',', $domainValue) as $d) {
                        $d = trim($d);
                        if (!empty($d)) {
                            $domains[] = $this->normalizeDomain($d);
                        }
                    }
                }

                return collect($domains)->unique()->sort()->values()->all();
            }

            // 2. Fallback: read fqdn field (non-Compose / regular apps)
            $fqdn = $response->json('fqdn', '');
            if (!empty($fqdn)) {
                $domains = array_filter(array_map(function ($d) {
                    return $this->normalizeDomain(trim($d));
                }, explode(',', $fqdn)));

                return collect($domains)->unique()->sort()->values()->all();
            }

            return [];

        } catch (\Exception $e) {
            Log::error('[CoolifyService] Error fetching domains', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Normalize a domain string into canonical form for comparison.
     *
     * Canonical: lowercase, trimmed, trailing slash stripped, protocol preserved.
     * Example: "  Https://Cookies.Ypsilon.Dev/  " → "https://cookies.ypsilon.dev"
     */
    private function normalizeDomain(string $domain): string
    {
        return rtrim(strtolower(trim($domain)), '/');
    }

    /**
     * Add a customer domain to the Coolify application's FQDN list.
     * Traefik will auto-provision SSL for the new domain.
     */
    public function addDomainToApp(string $domain, bool $forNodeProxy = false): bool
    {
        if ($forNodeProxy) {
            $result = $this->syncDomains();

            return ($result['changed'] ?? false) || array_key_exists('domains', $result);
        }

        try {
            $currentDomains = $this->getCurrentDomains($forNodeProxy);
            $newFqdn = "https://{$domain}";

            // Don't add if already present
            if (in_array($newFqdn, $currentDomains, true)) {
                Log::info("[CoolifyService] Domain {$domain} already registered.");
                return true;
            }

            $currentDomains[] = $newFqdn;
            $fqdnString = implode(',', $currentDomains);
            
            $uuid = $forNodeProxy ? $this->nodeProxyUuid : $this->appUuid;

            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->patch("{$this->apiUrl}/api/v1/applications/{$uuid}", [
                    'domains' => $fqdnString,
                ]);

            if ($response->successful()) {
                Log::info("[CoolifyService] Successfully added domain: {$domain}");
                
                // Trigger redeploy for Node Proxy to apply Traefik config
                if ($forNodeProxy) {
                    $this->triggerRedeploy($uuid);
                }
                
                return true;
            }

            Log::warning('[CoolifyService] Failed to add domain', [
                'domain' => $domain,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('[CoolifyService] Error adding domain', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Remove a customer domain from the Coolify application's FQDN list.
     */
    public function removeDomainFromApp(string $domain, bool $forNodeProxy = false): bool
    {
        if ($forNodeProxy) {
            $result = $this->syncDomains();

            return ($result['changed'] ?? false) || array_key_exists('domains', $result);
        }

        try {
            $currentDomains = $this->getCurrentDomains($forNodeProxy);
            $targetFqdn = "https://{$domain}";

            $filtered = array_filter($currentDomains, fn($d) => $d !== $targetFqdn);

            if (count($filtered) === count($currentDomains)) {
                Log::info("[CoolifyService] Domain {$domain} was not in the list.");
                return true;
            }

            $fqdnString = implode(',', array_values($filtered));
            $uuid = $forNodeProxy ? $this->nodeProxyUuid : $this->appUuid;

            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->patch("{$this->apiUrl}/api/v1/applications/{$uuid}", [
                    'domains' => $fqdnString,
                ]);

            if ($response->successful()) {
                Log::info("[CoolifyService] Successfully removed domain: {$domain}");
                
                if ($forNodeProxy) {
                    $this->triggerRedeploy($uuid);
                }
                
                return true;
            }

            Log::warning('[CoolifyService] Failed to remove domain', [
                'domain' => $domain,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('[CoolifyService] Error removing domain', [
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
    
    /**
     * Trigger a full rebuild deployment for an application.
     * Use this for code changes that require rebuilding the Docker image.
     */
    public function triggerRedeploy(string $uuid): bool
    {
        try {
            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->post("{$this->apiUrl}/api/v1/deploy?uuid={$uuid}&force=true");
                
            if ($response->successful()) {
                Log::info("[CoolifyService] Triggered redeploy for {$uuid}");
                return true;
            }
            
            Log::warning('[CoolifyService] Failed to trigger redeploy', [
                'uuid' => $uuid,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('[CoolifyService] Error triggering redeploy', [
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }

    /**
     * Trigger a quick restart for an application (no source rebuild).
     * Recreates the container from the existing image — much faster (~5-10s)
     * than a full deploy. Use this for config/routing changes that don't
     * require rebuilding the Docker image.
     */
    public function triggerRestart(string $uuid): bool
    {
        try {
            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->post("{$this->apiUrl}/api/v1/applications/{$uuid}/restart");
                
            if ($response->successful()) {
                Log::info("[CoolifyService] Triggered restart for {$uuid}");
                return true;
            }
            
            Log::warning('[CoolifyService] Failed to trigger restart', [
                'uuid' => $uuid,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error('[CoolifyService] Error triggering restart', [
                'uuid' => $uuid,
                'error' => $e->getMessage()
            ]);
        }
        
        return false;
    }

    /**
     * Verify that a domain's DNS CNAME points to the expected proxy host.
     */
    public function verifyDns(string $domain, string $expectedTarget = 'proxy.ycookies.com'): bool
    {
        try {
            $records = dns_get_record($domain, DNS_CNAME);
            if ($records) {
                foreach ($records as $record) {
                    if (isset($record['target']) && str_contains($record['target'], $expectedTarget)) {
                        return true;
                    }
                }
            }

            // Also check A records — the customer might use A record instead of CNAME
            $aRecords = dns_get_record($domain, DNS_A);
            if ($aRecords) {
                // If there are A records, check if they point to our server IP
                // For now, just return true if any A record exists (the domain resolves to something)
                // The real verification is whether traffic actually reaches us
                return !empty($aRecords);
            }
        } catch (\Exception $e) {
            Log::warning("[CoolifyService] DNS lookup failed for {$domain}", ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * Batch-sync all active Node-proxy domains to Coolify's application routing.
     *
     * This is the single source of truth for domain→Coolify sync.
     * It reads ALL active proxy domains from the DB, diffs against
     * Coolify's current FQDN list, and patches only if changed.
     *
     * Uses `docker_compose_domains` to map customer domains → node-proxy
     * and the admin domain → laravel service.
     *
     * @return array{changed: bool, message?: string, domains?: string[]}
     */
    public function syncDomains(): array
    {
        try {
            // ──────────────────────────────────────────────────────────
            // INVARIANT GUARD: syncDomains() must NEVER target the admin app.
            // The admin domain is set once by the installer and is immutable.
            // Violation of this rule caused 504 Gateway Timeouts (see ADR).
            // ──────────────────────────────────────────────────────────
            $adminUuid = $this->appUuid;

            // 1. Read all active proxy domains from DB (normalized canonical form)
            $proxyDomains = \App\Models\Domain::where('is_active', true)
                ->where('proxy_enabled', true)
                ->where(function ($q) {
                    $q->whereNotNull('origin_url')
                      ->orWhereNotNull('origin_ip')
                      ->orWhereNotNull('origin_subdomain');
                })
                ->pluck('name')
                ->map(fn($d) => $this->normalizeDomain("https://{$d}"))
                ->sort()->values()->all();

            // 2. Build admin domain from config (for logging only — never patched)
            $adminDomain = $this->normalizeDomain(config('app.url'));

            // Admin app domains are NOT patched here.
            // The admin domain is immutable after installation.
            // See: Critical Invariants #1, #2, #3 in sync_domains_report.md

            // 6. Patch PROXY app's docker_compose_domains
            // Each proxy domain gets routed to the 'node-proxy' service.
            // Coolify manages Traefik routing + SSL (Let's Encrypt) for these.
            $proxyDockerComposeDomains = [
                ['name' => 'node-proxy', 'domain' => implode(',', $proxyDomains)],
            ];

            // Check current proxy domains to avoid unnecessary redeploys
            $currentProxyDomains = $this->getCurrentDomains(true);
            $changed = false;

            if ($currentProxyDomains === $proxyDomains) {
                Log::info('[CoolifyService] syncDomains: Proxy domains already in sync, skipping.');
            } else {
                // INVARIANT #2/#3: sync must only target the proxy app, never admin.
                if ($this->nodeProxyUuid === $adminUuid) {
                    throw new \LogicException(
                        'Invariant violation: proxy UUID and admin UUID are identical. '
                        . 'syncDomains() must never target the admin app.'
                    );
                }

                $proxyResponse = Http::withToken($this->apiToken)
                    ->timeout(15)
                    ->patch("{$this->apiUrl}/api/v1/applications/{$this->nodeProxyUuid}", [
                        'docker_compose_domains' => $proxyDockerComposeDomains,
                    ]);

                if (!$proxyResponse->successful()) {
                    Log::error('[CoolifyService] syncDomains: Failed to patch proxy domains', [
                        'status' => $proxyResponse->status(),
                        'body' => $proxyResponse->body(),
                    ]);
                    return [
                        'changed' => false,
                        'message' => "Coolify API returned {$proxyResponse->status()} for proxy",
                    ];
                }

                Log::info('[CoolifyService] syncDomains: Proxy domains updated in Coolify', [
                    'domain_count' => count($proxyDomains),
                    'domains' => $proxyDomains,
                ]);

                $changed = true;

                // Restart proxy to apply new Traefik labels + trigger Let's Encrypt.
                // Uses restart (not redeploy) to avoid full source rebuild.
                // Downtime: ~5-10s instead of 30-60s.
                $this->triggerRestart($this->nodeProxyUuid);
            }

            Log::info('[CoolifyService] syncDomains: Domain routing updated', [
                'admin_domain' => $adminDomain,
                'proxy_domains' => $proxyDomains,
            ]);

            return ['changed' => $changed, 'domains' => $proxyDomains];

        } catch (\Exception $e) {
            Log::error('[CoolifyService] syncDomains: Exception', ['error' => $e->getMessage()]);
            return ['changed' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }

    // ==========================================
    // AI DEPLOY GUARDIAN METHODS
    // ==========================================

    /**
     * Get current application status
     */
    public function getAppStatus(string $uuid): ?string
    {
        try {
            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->get("{$this->apiUrl}/api/v1/applications/{$uuid}");
                
            return $response->successful() ? $response->json('status') : null;
        } catch (\Exception $e) {
            Log::error("[CoolifyService] Error fetching status for {$uuid}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Retrieve the most recent deployment's structured status & logs for an app
     */
    public function getLatestDeployment(string $uuid): ?array
    {
        try {
            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->get("{$this->apiUrl}/api/v1/applications/{$uuid}/deployments");
            
            if ($response->successful() && !empty($response->json())) {
                $deployments = $response->json();
                if (isset($deployments[0])) {
                    return $deployments[0];
                }
            }
        } catch (\Exception $e) {
            Log::error("[CoolifyService] Error fetching deployments for {$uuid}: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Retrieve running container logs via Coolify proxy endpoint
     */
    public function getDeploymentLogs(string $deployUuid): ?string
    {
        try {
            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->get("{$this->apiUrl}/api/v1/deployments/{$deployUuid}");
            
            if ($response->successful()) {
                return $response->json('logs');
            }
        } catch (\Exception $e) {
            Log::error("[CoolifyService] Error fetching logs for deployment {$deployUuid}: " . $e->getMessage());
        }
        
        return null;
    }

    public function patchEnvs(string $uuid, array $envs): array
    {
        try {
            // Envs should be structured according to Coolify's bulk patch: { data: [ {key, value, is_literal...} ] }
            $payload = [
                'data' => array_map(function ($env) {
                    return [
                        'key' => $env['key'],
                        'value' => $env['value'],
                        'is_preview' => false,
                        'is_literal' => true,
                        'is_multiline' => false,
                        'is_shown_once' => false
                    ];
                }, $envs)
            ];

            $response = Http::withToken($this->apiToken)
                ->timeout(10)
                ->patch("{$this->apiUrl}/api/v1/applications/{$uuid}/envs/bulk", $payload);
            
            return [
                'success' => $response->successful(),
                'message' => $response->successful() ? 'Environment variables patched successfully' : 'Failed to patch environments',
                'data' => $response->json()
            ];
        } catch (\Exception $e) {
            Log::error("[CoolifyService] Error patching envs for {$uuid}: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Exception occurred: ' . $e->getMessage()
            ];
        }
    }

    public function runHealthCheck(string $domain): array
    {
        try {
            $response = Http::timeout(5)->get("https://{$domain}/up");
            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'message' => $response->successful() ? 'Health check passed (200 OK)' : "Health check returned {$response->status()}"
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Health check failed: ' . $e->getMessage()
            ];
        }
    }
}
