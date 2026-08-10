<?php

namespace App\Services;

use App\Models\CoolifySetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service to interact with the Coolify REST API for container health monitoring.
 *
 * Provides methods to fetch application statuses, container logs, and deployment info
 * from a Coolify instance. All responses are cached for 30s to prevent API hammering.
 */
class CoolifyApiService
{
    protected CoolifySetting $settings;
    protected string $baseUrl;
    protected string $token;

    public function __construct()
    {
        $this->settings = CoolifySetting::instance();
        $this->baseUrl = rtrim($this->settings->instance_url, '/') . '/api/v1';
        $this->token = $this->settings->decrypted_api_token;
    }

    /**
     * Check if the service is properly configured.
     */
    public function isConfigured(): bool
    {
        return $this->settings->isConfigured();
    }

    /**
     * Test the API connection.
     *
     * @return array{ok: bool, message: string, version?: string}
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => 'Coolify API is not configured.'];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->get("{$this->baseUrl}/version");

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'message' => 'Connected to Coolify API.',
                    'version' => $response->body(),
                ];
            }

            return [
                'ok' => false,
                'message' => "API returned {$response->status()}: " . $response->body(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Get all applications with their statuses.
     * Cached for 30s.
     *
     * @return array{apps: array, error: ?string}
     */
    public function getApplications(): array
    {
        if (!$this->isConfigured()) {
            return ['apps' => [], 'error' => 'Not configured'];
        }

        return Cache::remember('coolify:applications', 30, function () {
            try {
                $appsResponse = Http::withToken($this->token)
                    ->timeout(15)
                    ->get("{$this->baseUrl}/applications");

                $servicesResponse = Http::withToken($this->token)
                    ->timeout(15)
                    ->get("{$this->baseUrl}/services");

                if (!$appsResponse->successful() && !$servicesResponse->successful()) {
                    return ['apps' => [], 'error' => "HTTP {$appsResponse->status()} / {$servicesResponse->status()}"];
                }

                $combined = array_merge(
                    $appsResponse->successful() ? $appsResponse->json() : [],
                    $servicesResponse->successful() ? $servicesResponse->json() : []
                );

                $apps = collect($combined)
                    ->map(fn ($app) => [
                        'uuid'       => $app['uuid'] ?? '',
                        'name'       => $app['name'] ?? 'Unknown',
                        'status'     => $app['status'] ?? 'unknown',
                        'fqdn'       => $app['fqdn'] ?? null,
                        'last_online' => $app['last_online_at'] ?? null,
                        'git_branch' => $app['git_branch'] ?? null,
                        'build_pack' => $app['build_pack'] ?? null,
                    ])
                    ->toArray();

                return ['apps' => $apps, 'error' => null];
            } catch (\Throwable $e) {
                Log::warning('Coolify API fetch failed: ' . $e->getMessage());
                return ['apps' => [], 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Get raw console logs from a Coolify resource.
     */
    public function getContainerLogs(string $uuid): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->get("{$this->baseUrl}/applications/{$uuid}/logs");

            if ($response->successful() && $response->json('logs')) {
                return $response->json('logs');
            }

            // Fallback for services
            $serviceResponse = Http::withToken($this->token)
                ->timeout(10)
                ->get("{$this->baseUrl}/services/{$uuid}/logs");

            if ($serviceResponse->successful() && $serviceResponse->json('logs')) {
                return $serviceResponse->json('logs');
            }

            return "Could not retrieve logs for {$uuid} (App Status: {$response->status()})";
        } catch (\Throwable $e) {
            Log::warning("Coolify logs fetch failed for {$uuid}: " . $e->getMessage());
            return "Error retrieving logs: " . $e->getMessage();
        }
    }

    /**
     * Get the status of a specific application.
     *
     * @return array{status: string, name: string, ...}|null
     */
    public function getAppStatus(string $uuid): ?array
    {
        $result = $this->getApplications();
        return collect($result['apps'])->firstWhere('uuid', $uuid);
    }

    /**
     * Parse a Coolify status string into a normalized structure.
     *
     * Coolify statuses: "running:healthy", "running:unhealthy", "degraded:unhealthy",
     * "exited:unhealthy", "stopped", etc.
     *
     * @return array{state: string, health: string, color: string, icon: string}
     */
    public static function parseStatus(string $raw): array
    {
        // Coolify returns statuses like "running:healthy", "running", "degraded", etc.
        $raw = strtolower(trim($raw));
        $parts = explode(':', $raw, 2);
        $state = trim($parts[0] ?? 'unknown');
        $health = trim($parts[1] ?? 'unknown');

        $color = match (true) {
            // If running and healthy, it's perfect
            $state === 'running' && $health === 'healthy' => 'emerald',
            // If it's just 'running' with no explicit health status, consider it success too
            $state === 'running' && $health === 'unknown' => 'emerald',
            // If it's running but has some other health status (e.g. unhealthy, starting), warn
            $state === 'running' => 'amber',
            // Errors
            $state === 'degraded' => 'red',
            $state === 'exited' || $state === 'stopped' => 'red',
            default => 'gray',
        };

        $icon = match (true) {
            $color === 'emerald' => '🟢',
            $color === 'amber' => '🟡',
            $color === 'red' => '🔴',
            default => '⚪',
        };

        return compact('state', 'health', 'color', 'icon');
    }

    /**
     * Clear cached data.
     */
    public function clearCache(): void
    {
        Cache::forget('coolify:applications');
    }
}
