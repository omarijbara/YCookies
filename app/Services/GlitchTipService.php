<?php

namespace App\Services;

use App\Models\GlitchTipSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GlitchTip API client — Sentry-compatible REST API.
 *
 * Reads connection config from the glitch_tip_settings DB table
 * (configurable via the admin Error Tracker page).
 */
class GlitchTipService
{
    protected string $baseUrl;
    protected string $token;
    protected string $orgSlug;
    protected string $publicUrl;
    protected bool $active;

    public function __construct()
    {
        $settings = GlitchTipSetting::instance();

        $this->baseUrl   = rtrim($settings->url ?: 'http://glitchtip-web:8000', '/');
        $this->token     = $settings->decrypted_api_token;
        $this->orgSlug   = $settings->org_slug ?: 'default';
        $this->publicUrl = $settings->public_url ?: 'https://sentry.ypsilon.dev';
        $this->active    = $settings->is_active;
    }

    /**
     * Check if GlitchTip integration is configured and active.
     */
    public function isConfigured(): bool
    {
        return $this->active && !empty($this->token) && !empty($this->baseUrl);
    }

    /**
     * Get the public-facing GlitchTip URL for browser links.
     */
    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    /**
     * List recent issues for the organization.
     *
     * @param  int  $limit
     * @param  string  $query  Optional search query
     * @param  array  $projectIds Optional list of project IDs/slugs to filter by
     * @return array{issues: array, error: string|null}
     */
    public function getIssues(int $limit = 25, string $query = '', array $projectIds = []): array
    {
        $projectKey = implode(',', $projectIds);
        $cacheKey = "glitchtip:issues:{$this->orgSlug}:{$limit}:{$projectKey}:" . md5($query);

        return Cache::remember($cacheKey, 60, function () use ($limit, $query, $projectIds) {
            try {
                $params = ['limit' => $limit];
                if (!empty($query)) {
                    $params['query'] = $query;
                }
                if (!empty($projectIds)) {
                    $params['project'] = $projectIds;
                }

                $response = $this->request('GET', "/api/0/organizations/{$this->orgSlug}/issues/", $params);

                if (!$response->successful()) {
                    return [
                        'issues' => [],
                        'error'  => "GlitchTip API returned HTTP {$response->status()}",
                    ];
                }

                $issues = collect($response->json() ?? [])
                    ->map(fn(array $issue) => [
                        'id'          => $issue['id'] ?? null,
                        'title'       => $issue['title'] ?? 'Unknown',
                        'culprit'     => $issue['culprit'] ?? '',
                        'level'       => $issue['level'] ?? 'error',
                        'status'      => $issue['status'] ?? 'unresolved',
                        'count'       => (int) ($issue['count'] ?? 0),
                        'first_seen'  => $issue['firstSeen'] ?? null,
                        'last_seen'   => $issue['lastSeen'] ?? null,
                        'project'     => $issue['project']['name'] ?? 'Unknown',
                        'short_id'    => $issue['shortId'] ?? '',
                        'platform'    => $issue['platform'] ?? '',
                        'is_public'   => $issue['isPublic'] ?? false,
                    ])
                    ->toArray();

                return ['issues' => $issues, 'error' => null];
            } catch (\Throwable $e) {
                Log::warning('GlitchTip API error: ' . $e->getMessage());
                return [
                    'issues' => [],
                    'error'  => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * Get projects for the organization.
     *
     * @return array{projects: array, error: string|null}
     */
    public function getProjects(): array
    {
        $cacheKey = "glitchtip:projects:{$this->orgSlug}";

        return Cache::remember($cacheKey, 300, function () {
            try {
                $response = $this->request('GET', "/api/0/organizations/{$this->orgSlug}/projects/");

                if (!$response->successful()) {
                    return ['projects' => [], 'error' => "HTTP {$response->status()}"];
                }

                $projects = collect($response->json() ?? [])
                    ->map(fn(array $p) => [
                        'id'       => $p['id'] ?? null,
                        'name'     => $p['name'] ?? 'Unknown',
                        'slug'     => $p['slug'] ?? '',
                        'platform' => $p['platform'] ?? '',
                    ])
                    ->toArray();

                return ['projects' => $projects, 'error' => null];
            } catch (\Throwable $e) {
                Log::warning('GlitchTip projects API error: ' . $e->getMessage());
                return ['projects' => [], 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Get summary stats: total issues, unresolved, resolved.
     *
     * @param  array  $projectIds Optional list of project IDs/slugs to filter by
     * @return array{total: int, unresolved: int, resolved: int, error: string|null}
     */
    public function getStats(array $projectIds = []): array
    {
        $result = $this->getIssues(100, '', $projectIds);

        if ($result['error']) {
            return ['total' => 0, 'unresolved' => 0, 'resolved' => 0, 'error' => $result['error']];
        }

        $issues     = collect($result['issues']);
        $total      = $issues->count();
        $unresolved = $issues->where('status', 'unresolved')->count();
        $resolved   = $issues->where('status', 'resolved')->count();

        return [
            'total'      => $total,
            'unresolved' => $unresolved,
            'resolved'   => $resolved,
            'error'      => null,
        ];
    }

    /**
     * Clear cached GlitchTip data.
     */
    public function clearCache(): void
    {
        Cache::forget("glitchtip:issues:{$this->orgSlug}:25:" . md5(''));
        Cache::forget("glitchtip:issues:{$this->orgSlug}:100:" . md5(''));
        Cache::forget("glitchtip:projects:{$this->orgSlug}");
    }

    /**
     * Make an authenticated HTTP request to GlitchTip.
     */
    protected function request(string $method, string $path, array $query = [])
    {
        return Http::withToken($this->token)
            ->timeout(10)
            ->connectTimeout(5)
            ->acceptJson()
            ->baseUrl($this->baseUrl)
            ->withQueryParameters($query)
            ->send($method, $path);
    }
}
