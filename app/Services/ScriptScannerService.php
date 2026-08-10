<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainPageSet;
use App\Models\ScriptBlocker;
use App\Services\TemplateLibraryService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ScriptScannerService
{
    /** Max pages to actually scan (after sampling) */
    const MAX_PAGES = 25;

    /** Max pages to discover from sitemaps before sampling */
    const MAX_DISCOVERY = 2000;

    /** Real browser User-Agent to avoid bot detection */
    const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    /** Common subpages to check as fallback */
    const COMMON_PATHS = [
        '/contact', '/about', '/blog', '/privacy', '/impressum',
        '/datenschutz', '/shop', '/products', '/services',
        '/faq', '/team', '/agb', '/terms', '/support',
    ];

    /** All known sitemap URL patterns to try */
    const SITEMAP_PATHS = [
        '/sitemap.xml',
        '/sitemap_index.xml',
        '/sitemap-index.xml',
        '/sitemapindex.xml',
        '/wp-sitemap.xml',
        '/sitemap/sitemap.xml',
        '/sitemap/index.xml',
        '/sitemap/sitemap-index.xml',
        '/sitemap.xml.gz',
        '/sitemap1.xml',
        '/sitemap.php',
        '/sitemap.txt',
        '/sitemap/',
        '/sitemap-news.xml',
        '/sitemap-posts.xml',
        '/sitemap-products.xml',
        '/post-sitemap.xml',
        '/page-sitemap.xml',
        '/category-sitemap.xml',
        '/rss/',
        '/rss.xml',
        '/atom.xml',
        '/feed/',
        '/api/sitemap',
    ];

    /**
     * Background scans should stay lightweight by default.
     */
    public static function scheduledSetChunkSize(): int
    {
        return max(1, min(50, (int) config('services.scanner.scheduled_set_chunk_size', 10)));
    }

    public static function scheduledInterRequestDelayMs(): int
    {
        return max(0, min(5000, (int) config('services.scanner.scheduled_inter_request_delay_ms', 500)));
    }

    public static function scheduledDeepScanEnabled(): bool
    {
        return (bool) config('services.scanner.scheduled_deep_scan_enabled', false);
    }

    protected static function targetSetCount(): int
    {
        return max(50, min(200, (int) config('services.scanner.target_set_count', 100)));
    }

    protected static function minSetSize(): int
    {
        return max(5, min(50, (int) config('services.scanner.min_set_size', 15)));
    }

    protected static function maxSetSize(): int
    {
        return max(static::minSetSize(), min(200, (int) config('services.scanner.max_set_size', 50)));
    }

    public static function pauseBetweenScheduledRequests(?int $delayMs = null): void
    {
        $delayMs ??= static::scheduledInterRequestDelayMs();

        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    /**
     * Split an oversized set into calmer chunks and shift following sets forward.
     * The split is persisted so future cycles also stay lightweight.
     */
    public static function rebalancePageSet(DomainPageSet $set, ?int $maxSetSize = null): int
    {
        $maxSetSize ??= static::scheduledSetChunkSize();
        $pages = array_values($set->pages ?? []);

        if ($maxSetSize < 1 || count($pages) <= $maxSetSize) {
            return 0;
        }

        $chunks = array_chunk($pages, $maxSetSize);
        $extraChunks = count($chunks) - 1;

        DB::transaction(function () use ($set, $chunks, $extraChunks): void {
            DomainPageSet::where('domain_id', $set->domain_id)
                ->where('cycle_number', $set->cycle_number)
                ->where('set_index', '>', $set->set_index)
                ->increment('set_index', $extraChunks);

            $set->update([
                'pages' => $chunks[0],
                'page_count' => count($chunks[0]),
                'last_scanned_at' => null,
                'scan_result_id' => null,
            ]);

            foreach (array_slice($chunks, 1) as $offset => $chunk) {
                DomainPageSet::create([
                    'domain_id' => $set->domain_id,
                    'set_index' => $set->set_index + $offset + 1,
                    'pages' => $chunk,
                    'page_count' => count($chunk),
                    'cycle_number' => $set->cycle_number,
                ]);
            }
        });

        return $extraChunks;
    }

    /**
     * Run a multi-page scan: Discover pages → HTTP scan all → Chrome deep scan homepage.
     * Results are merged and deduplicated across all pages.
     *
     * @return array{protected: array, suggested: array, unknown: array, raw: array, stages: array, discoveredPages: array}
     */
    public static function scan(?Domain $domain, array $manualPages = []): array
    {
        // Increase PHP script time limit for multi-page scans.
        // Note: In FPM context, request_terminate_timeout in www.conf takes precedence.
        // In CLI context (queue workers), this effectively prevents premature timeouts.
        @set_time_limit(300);

        $baseUrl = 'https://' . preg_replace('#^https?://#', '', $domain->name);
        $domainHost = parse_url($baseUrl, PHP_URL_HOST);

        $stages = [];
        $allUrls = [];
        $unblockedUrls = [];

        // ─── Stage 0: Discover pages (or use manual pages) ───
        $discoveredPages = [];
        if (!empty($manualPages)) {
            // Use manually provided pages (max 99)
            $discoveredPages = array_slice($manualPages, 0, 99);
            // Ensure all are full URLs and prevent SSRF
            $discoveredPages = array_map(function ($page) use ($baseUrl, $domainHost) {
                $page = trim($page);
                if (str_starts_with($page, '/')) {
                    return rtrim($baseUrl, '/') . $page;
                }
                if (!str_starts_with($page, 'http')) {
                    $page = rtrim($baseUrl, '/') . '/' . ltrim($page, '/');
                }

                // SSRF Protection: Enforce that the provided URL belongs to the target domain
                $host = parse_url($page, PHP_URL_HOST);
                if (!$host) return null;

                $cleanHost = preg_replace('/^www\./', '', strtolower($host));
                $cleanDomain = preg_replace('/^www\./', '', strtolower($domainHost));

                if ($cleanHost !== $cleanDomain && !str_ends_with($cleanHost, '.' . $cleanDomain)) {
                    \Illuminate\Support\Facades\Log::warning("SSRF Attempt drop in scanner: {$page} does not match {$domainHost}");
                    return null;
                }

                return $page;
            }, $discoveredPages);
            $discoveredPages = array_filter($discoveredPages);
            $stages['discovery'] = [
                'status' => 'success',
                'count' => count($discoveredPages),
                'source' => 'manual',
            ];
        } else {
            try {
                $discoveredPages = static::discoverPages($baseUrl, $domainHost);
                $stages['discovery'] = [
                    'status' => 'success',
                    'count' => count($discoveredPages),
                    'source' => 'auto',
                ];
            } catch (\Exception $e) {
                $discoveredPages = [$baseUrl];
                $stages['discovery'] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'count' => 1,
                ];
                Log::warning("Page discovery failed for {$domain->name}: " . $e->getMessage());
            }
        }

        // ─── Stage 1: Fast HTTP Parser on ALL discovered pages ───
        $httpTotal = 0;
        try {
            foreach ($discoveredPages as $pageUrl) {
                try {
                    $httpUrls = static::httpScan($pageUrl, $domainHost, $unblockedUrls);
                    $allUrls = array_merge($allUrls, $httpUrls);
                    $httpTotal += count($httpUrls);
                } catch (\Exception $e) {
                    Log::debug("HTTP scan skipped for {$pageUrl}: " . $e->getMessage());
                }
            }
            $stages['http'] = [
                'status' => 'success',
                'count' => $httpTotal,
                'pages_scanned' => count($discoveredPages),
            ];
        } catch (\Exception $e) {
            $stages['http'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
                'count' => 0,
            ];
            Log::warning("HTTP scan failed for {$domain->name}: " . $e->getMessage());
        }

        // ─── Stage 2: Deep Headless Chrome on HOMEPAGE only ───
        try {
            $deepUrls = static::deepScan($baseUrl, $domainHost);
            $allUrls = array_merge($allUrls, $deepUrls);
            $stages['deep'] = [
                'status' => 'success',
                'count' => count($deepUrls),
            ];
        } catch (\Exception $e) {
            $stages['deep'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
                'count' => 0,
            ];
            Log::warning("Deep scan failed for {$domain->name}: " . $e->getMessage());
        }

        // Deduplicate
        $allUrls = array_values(array_unique($allUrls));
        $unblockedUrls = array_values(array_unique(array_merge($unblockedUrls, $deepUrls ?? [])));

        $results = static::categorize($domain, $allUrls);
        $results['unblocked'] = $unblockedUrls;
        $results['stages'] = $stages;
        $results['discoveredPages'] = $discoveredPages;

        return $results;
    }

    /**
     * Discover pages on a domain using 3 layers:
     * 1. Sitemap.xml parsing
     * Layer 0: robots.txt → Sitemap directives
     * Layer 1: Sitemap XML parsing (from robots.txt + common locations)
     * Layer 2: Google/Bing site: search for indexed pages
     * Layer 3: Deep internal link crawling (2 levels)
     * Layer 4: Common paths fallback
     */
    public static function discoverPages(string $baseUrl, string $domainHost): array
    {
        $pages = [];

        // ─── Layer 0: Parse robots.txt for Sitemap directives ───
        $sitemapUrls = static::getSitemapUrlsFromRobots($baseUrl);
        Log::info("robots.txt: found " . count($sitemapUrls) . " sitemap URLs for {$domainHost}");

        // ─── Layer 1: Parse sitemaps (discover up to MAX_DISCOVERY) ───
        $sitemapLocations = array_unique(array_merge(
            $sitemapUrls,
            array_map(fn($p) => $baseUrl . $p, static::SITEMAP_PATHS)
        ));

        foreach ($sitemapLocations as $sitemapUrl) {
            if (count($pages) >= static::MAX_DISCOVERY) break;
            try {
                $sitemapPages = static::parseSitemap($sitemapUrl);
                if (!empty($sitemapPages)) {
                    $pages = array_merge($pages, $sitemapPages);
                    Log::info("Sitemap ({$sitemapUrl}): found " . count($sitemapPages) . " pages");
                    break; // Found a working sitemap
                }
            } catch (\Exception $e) {
                // Try next
            }
        }
        Log::info("Sitemap total: " . count($pages) . " pages for {$domainHost}");

        // ─── Layer 2: Google/Bing site: search ───
        if (count($pages) < static::MAX_PAGES) {
            try {
                $searchPages = static::searchEngineDiscovery($domainHost);
                $pages = array_merge($pages, $searchPages);
                Log::info("Search engines: found " . count($searchPages) . " pages for {$domainHost}");
            } catch (\Exception $e) {
                Log::debug("Search engine discovery failed for {$domainHost}: " . $e->getMessage());
            }
        }

        // ─── Layer 3: Crawl internal links (2 levels deep) ───
        if (count($pages) < 10) {
            try {
                $visited = [];
                $crawledPages = static::crawlInternalLinks($baseUrl, $domainHost, 2, $visited);
                $pages = array_merge($pages, $crawledPages);
                Log::info("Link crawl: found " . count($crawledPages) . " pages for {$domainHost}");
            } catch (\Exception $e) {
                Log::debug("Link crawl failed for {$domainHost}: " . $e->getMessage());
            }
        }

        // ─── Layer 4: Common paths fallback ───
        if (count($pages) < 5) {
            $commonPages = static::checkCommonPaths($baseUrl);
            $pages = array_merge($pages, $commonPages);
        }

        // Always include homepage first
        array_unshift($pages, $baseUrl);

        // Normalize: strip fragments, trailing slashes
        $pages = array_map(fn($url) => rtrim(strtok($url, '#'), '/') ?: $url, $pages);
        $pages = array_values(array_unique($pages));

        // Filter to same domain only
        $pages = array_filter($pages, function ($url) use ($domainHost) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host) return false;
            $cleanHost = preg_replace('/^www\./', '', $host);
            $cleanDomain = preg_replace('/^www\./', '', $domainHost);
            return $cleanHost === $cleanDomain || str_ends_with($cleanHost, '.' . $cleanDomain);
        });

        $allPages = array_values($pages);
        $totalDiscovered = count($allPages);
        Log::info("Total discovery for {$domainHost}: {$totalDiscovered} pages");

        // Smart sample if too many pages
        if ($totalDiscovered > static::MAX_PAGES) {
            $sampled = static::smartSample($allPages, $baseUrl);
            Log::info("Smart sampled {$domainHost}: {$totalDiscovered} discovered → " . count($sampled) . " sampled");
            return $sampled;
        }

        return array_slice($allPages, 0, static::MAX_PAGES);
    }

    /**
     * Smart-sample a large list of URLs by grouping by URL path pattern
     * and selecting diverse representatives from each group.
     *
     * Strategy:
     * - Extract path pattern: /blog/my-post-slug → /blog/*
     * - Group all URLs by their pattern
     * - Pick 2 from each group (first + one from middle)
     * - Always include homepage
     * - Cap at MAX_PAGES
     *
     * @param  array  $urls     All discovered URLs
     * @param  string $baseUrl  The homepage URL
     * @return array  Sampled URLs (max MAX_PAGES)
     */
    public static function smartSample(array $urls, string $baseUrl): array
    {
        if (count($urls) <= static::MAX_PAGES) {
            return $urls;
        }

        $groups = [];

        foreach ($urls as $url) {
            $pattern = static::extractPathPattern($url);
            $groups[$pattern][] = $url;
        }

        // Sort groups by size (largest first) so diverse patterns get priority
        uasort($groups, fn($a, $b) => count($b) <=> count($a));

        $sampled = [];
        $homepage = rtrim($baseUrl, '/');

        // Always include homepage first
        $sampled[] = $homepage;

        // Calculate pages per group (at least 1, distribute evenly)
        $groupCount = count($groups);
        $budget = static::MAX_PAGES - 1; // -1 for homepage
        $perGroup = max(1, intval($budget / max(1, $groupCount)));
        // Cap at 3 per group to ensure diversity
        $perGroup = min($perGroup, 3);

        foreach ($groups as $pattern => $groupUrls) {
            if (count($sampled) >= static::MAX_PAGES) break;

            // Remove homepage if already added
            $groupUrls = array_filter($groupUrls, fn($u) => rtrim($u, '/') !== $homepage);
            $groupUrls = array_values($groupUrls);
            if (empty($groupUrls)) continue;

            // Pick first
            $sampled[] = $groupUrls[0];

            // Pick from middle if budget allows and group is large enough
            if ($perGroup >= 2 && count($groupUrls) > 2) {
                $midIdx = intval(count($groupUrls) / 2);
                $sampled[] = $groupUrls[$midIdx];
            }

            // Pick last if budget allows and group has many pages
            if ($perGroup >= 3 && count($groupUrls) > 5) {
                $sampled[] = $groupUrls[count($groupUrls) - 1];
            }
        }

        return array_slice(array_values(array_unique($sampled)), 0, static::MAX_PAGES);
    }

    /**
     * Extract a URL path pattern for grouping.
     * e.g. /blog/my-post-title → /blog/*
     *      /products/shoes/nike-air → /products/shoes/*
     *      /about → /about
     *      / → /
     */
    public static function extractPathPattern(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        $path = rtrim($path, '/');
        if ($path === '' || $path === '/') return '/';

        $segments = explode('/', trim($path, '/'));

        // For URLs with 1 segment, keep as-is: /about → /about
        if (count($segments) <= 1) {
            return '/' . $segments[0];
        }

        // For URLs with 2+ segments, keep first segment(s) as pattern:
        // /blog/post-slug → /blog/*
        // /products/category/item → /products/category/*
        // Keep up to 2 segments as the pattern prefix
        $patternSegments = array_slice($segments, 0, min(2, count($segments) - 1));
        return '/' . implode('/', $patternSegments) . '/*';
    }

    /**
     * Safely fetch a URL using UrlValidator to prevent SSRF and DNS Rebinding.
     * Returns the Laravel HTTP Response or throws an exception on validation error.
     */
    protected static function safeHttpFetch(string $method, string $url, int $timeout = 10, array $headers = [])
    {
        $validator = new \App\Services\UrlValidator();
        $result = $validator->validateForScanner($url);

        if ($result['error']) {
            throw new \Exception("SSRF Validation Failed: " . $result['error']);
        }

        $curlResolve = \App\Services\UrlValidator::buildCurlResolveEntries($result['host'], $result['port'], $result['resolved_ips']);

        $request = Http::timeout($timeout)->withHeaders($headers)->withOptions([
            'curl' => [
                CURLOPT_RESOLVE => $curlResolve
            ]
        ]);

        return strtolower($method) === 'head' ? $request->head($url) : $request->get($url);
    }

    /**
     * Parse robots.txt for Sitemap: directives.
     */
    protected static function getSitemapUrlsFromRobots(string $baseUrl): array
    {
        try {
            $response = static::safeHttpFetch('GET', rtrim($baseUrl, '/') . '/robots.txt', 5, ['User-Agent' => static::BROWSER_UA]);

            if (!$response->successful()) return [];

            $sitemaps = [];
            foreach (explode("\n", $response->body()) as $line) {
                $line = trim($line);
                if (stripos($line, 'Sitemap:') === 0) {
                    $url = trim(substr($line, 8));
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        $sitemaps[] = $url;
                    }
                }
            }
            return $sitemaps;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Parse a sitemap.xml (or sitemap index). Smart-samples across sub-sitemaps for diversity.
     */
    protected static function parseSitemap(string $sitemapUrl, int $maxDepth = 2): array
    {
        if ($maxDepth <= 0) return [];

        $response = static::safeHttpFetch('GET', $sitemapUrl, 10, [
            'User-Agent' => static::BROWSER_UA,
            'Accept' => 'application/xml, text/xml, */*',
        ]);

        if (!$response->successful()) return [];

        $body = $response->body();

        // Handle gzipped sitemaps
        if (str_ends_with($sitemapUrl, '.gz')) {
            $body = @gzdecode($body);
            if (!$body) return [];
        }

        $oldSetting = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($oldSetting);
        if (!$xml) return [];

        $urls = [];
        // Use MAX_DISCOVERY for collection so we can smart-sample later
        $limit = static::MAX_DISCOVERY;

        // Sitemap index → parse ALL sub-sitemaps for full discovery
        if (isset($xml->sitemap)) {
            $subSitemaps = [];
            foreach ($xml->sitemap as $sitemap) {
                $subUrl = (string) $sitemap->loc;
                if ($subUrl) $subSitemaps[] = $subUrl;
            }

            foreach ($subSitemaps as $subUrl) {
                if (count($urls) >= $limit) break;
                try {
                    $subPages = static::parseSitemap($subUrl, $maxDepth - 1);
                    $urls = array_merge($urls, $subPages);
                } catch (\Exception $e) {
                    // Skip broken sub-sitemaps
                }
            }
        }

        // Regular sitemap
        if (isset($xml->url)) {
            foreach ($xml->url as $entry) {
                $loc = (string) $entry->loc;
                if ($loc) $urls[] = $loc;
                if (count($urls) >= $limit) break;
            }
        }

        return array_slice($urls, 0, $limit);
    }

    /**
     * Search Google and Bing for indexed pages via site:domain query.
     */
    protected static function searchEngineDiscovery(string $domainHost): array
    {
        $urls = [];
        $cleanDomain = preg_replace('/^www\./', '', $domainHost);

        // ─── Google ───
        try {
            $query = urlencode("site:{$domainHost}");
            $response = static::safeHttpFetch('GET', "https://www.google.com/search?q={$query}&num=30&hl=en", 10, [
                'User-Agent' => static::BROWSER_UA,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9,de;q=0.8',
                'DNT' => '1',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
            ]);

            if ($response->successful()) {
                $html = $response->body();
                // Google /url?q= format
                if (preg_match_all('/\/url\?q=(https?:\/\/[^&"]+)/i', $html, $m)) {
                    foreach ($m[1] as $url) {
                        $url = urldecode($url);
                        $h = parse_url($url, PHP_URL_HOST);
                        if ($h && preg_replace('/^www\./', '', $h) === $cleanDomain) {
                            $urls[] = $url;
                        }
                    }
                }
                // Direct domain hrefs
                if (preg_match_all('/href="(https?:\/\/(?:www\.)?' . preg_quote($cleanDomain, '/') . '[^"]*)"/', $html, $m2)) {
                    foreach ($m2[1] as $url) {
                        if (!str_contains($url, 'google.com')) $urls[] = $url;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Google search failed: " . $e->getMessage());
        }

        // ─── Bing ───
        try {
            $query = urlencode("site:{$domainHost}");
            $response = static::safeHttpFetch('GET', "https://www.bing.com/search?q={$query}&count=30", 10, [
                'User-Agent' => static::BROWSER_UA,
                'Accept' => 'text/html',
            ]);

            if ($response->successful()) {
                $html = $response->body();
                if (preg_match_all('/href="(https?:\/\/(?:www\.)?' . preg_quote($cleanDomain, '/') . '[^"]*)"/', $html, $m)) {
                    foreach ($m[1] as $url) {
                        if (!str_contains($url, 'bing.com') && filter_var($url, FILTER_VALIDATE_URL)) {
                            $urls[] = $url;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::debug("Bing search failed: " . $e->getMessage());
        }

        return array_values(array_unique($urls));
    }

    /**
     * Crawl internal <a href> links with optional depth (default 2 levels).
     */
    protected static function crawlInternalLinks(string $baseUrl, string $domainHost, int $depth = 1, array &$visited = []): array
    {
        if ($depth <= 0 || count($visited) >= static::MAX_PAGES) return [];

        $response = static::safeHttpFetch('GET', $baseUrl, 8, [
            'User-Agent' => static::BROWSER_UA,
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'en-US,en;q=0.9',
        ]);

        if (!$response->successful()) return [];
        $visited[] = $baseUrl;

        $html = $response->body();
        $urls = [];

        if (preg_match_all('/<a[^>]+\bhref\s*=\s*["\']([^"\'#]+)["\'][^>]*>/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
                    $href = rtrim($baseUrl, '/') . $href;
                }
                if (!str_starts_with($href, 'http')) continue;

                $host = parse_url($href, PHP_URL_HOST);
                if (!$host) continue;
                $cleanHost = preg_replace('/^www\./', '', $host);
                $cleanDomain = preg_replace('/^www\./', '', $domainHost);

                if ($cleanHost === $cleanDomain || str_ends_with($cleanHost, '.' . $cleanDomain)) {
                    $path = parse_url($href, PHP_URL_PATH) ?? '';
                    if (preg_match('/\.(pdf|jpg|jpeg|png|gif|svg|zip|mp4|mp3|css|js|woff|ttf|ico|webp)$/i', $path)) continue;

                    $normalized = rtrim(strtok($href, '#'), '/') ?: $href;
                    if (!in_array($normalized, $urls) && !in_array($normalized, $visited)) {
                        $urls[] = $normalized;
                    }
                }
            }
        }

        $urls = array_slice(array_values(array_unique($urls)), 0, static::MAX_PAGES);

        // Level 2: crawl a few discovered pages
        if ($depth > 1 && count($urls) > 0) {
            $pagesToCrawl = array_slice($urls, 0, 3);
            foreach ($pagesToCrawl as $subPage) {
                if (count($urls) >= static::MAX_PAGES || in_array($subPage, $visited)) continue;
                try {
                    $subUrls = static::crawlInternalLinks($subPage, $domainHost, $depth - 1, $visited);
                    $urls = array_merge($urls, $subUrls);
                } catch (\Exception $e) {
                    // Skip
                }
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, static::MAX_PAGES);
    }

    /**
     * Check well-known common paths with fast HEAD requests.
     */
    protected static function checkCommonPaths(string $baseUrl): array
    {
        $found = [];

        foreach (static::COMMON_PATHS as $path) {
            if ($path === '/') continue;
            try {
                $url = rtrim($baseUrl, '/') . $path;
                $response = static::safeHttpFetch('HEAD', $url, 3, ['User-Agent' => static::BROWSER_UA]);
                if ($response->successful()) {
                    $found[] = $url;
                }
            } catch (\Exception $e) {
                // Skip
            }
        }

        return $found;
    }

    /**
     * Stage 1: Fast HTTP parser — fetches raw HTML and extracts script/iframe sources via regex.
     * Works without Chrome, reliable on any server.
     * @param array &$unblockedOut Passed by reference to collect scripts that do not have type="text/plain" or data-src 
     */
    public static function httpScan(string $url, string $domainHost, array &$unblockedOut = []): array
    {
        $response = static::safeHttpFetch('GET', $url, 8, [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.5',
        ]);

        if (!$response->successful()) {
            throw new \RuntimeException("HTTP {$response->status()} — could not fetch {$url}");
        }

        $html = $response->body();
        $urls = [];

        // Track unblocked dynamically
        if (preg_match_all('/<script\b[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $isBlocked = stripos($tag, 'type="text/plain"') !== false || stripos($tag, "type='text/plain'") !== false
                    || stripos($tag, 'data-src=') !== false || stripos($tag, 'y-src=') !== false
                    || stripos($tag, 'data-cookieconsent=') !== false
                    || stripos($tag, 'data-ycookies-script-blocked') !== false;

                // The YCookies manager script itself is never "unblocked" — it's the consent tool
                $isYCookiesManager = stripos($tag, 'id="ycookies-manager"') !== false
                    || stripos($tag, 'data-ycookies-id=') !== false;

                if (preg_match('/\b(?:data-|y-)?src\s*=\s*["\']([^"\']+)["\']/i', $tag, $m)) {
                    $urls[] = $m[1];
                    if (!$isBlocked && !$isYCookiesManager && preg_match('/\bsrc\s*=\s*/i', $tag)) {
                        $parsed = static::filterExternal([$m[1]], $url, $domainHost);
                        if (!empty($parsed)) $unblockedOut[] = $parsed[0];
                    }
                }
            }
        }

        // Track unblocked iframes
        if (preg_match_all('/<iframe\b[^>]*>/i', $html, $matches)) {
            foreach ($matches[0] as $tag) {
                $isBlocked = stripos($tag, 'data-src=') !== false || stripos($tag, 'y-src=') !== false
                    || stripos($tag, 'data-cookieconsent=') !== false
                    || stripos($tag, 'data-ycookies-content-blocked') !== false;
                if (preg_match('/\b(?:data-|y-)?src\s*=\s*["\']([^"\']+)["\']/i', $tag, $m)) {
                    $urls[] = $m[1];
                    if (!$isBlocked && preg_match('/\bsrc\s*=\s*/i', $tag)) {
                        $parsed = static::filterExternal([$m[1]], $url, $domainHost);
                        if (!empty($parsed)) $unblockedOut[] = $parsed[0];
                    }
                }
            }
        }

        // Match <link rel="stylesheet" href="..."> — also track unblocked stylesheets
        if (preg_match_all('/<link[^>]+\brel\s*=\s*["\']stylesheet["\'][^>]*>/i', $html, $linkMatches)) {
            foreach ($linkMatches[0] as $tag) {
                $isBlocked = stripos($tag, 'data-href=') !== false || stripos($tag, 'y-href=') !== false
                    || stripos($tag, 'data-cookieconsent=') !== false
                    || stripos($tag, 'disabled') !== false
                    || stripos($tag, 'data-ycookies-style-blocked') !== false;

                if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $m)) {
                    $urls[] = $m[1];
                    if (!$isBlocked) {
                        $parsed = static::filterExternal([$m[1]], $url, $domainHost);
                        if (!empty($parsed)) $unblockedOut[] = $parsed[0];
                    }
                }
            }
        }

        // Resolve relative URLs and filter external only
        return static::filterExternal($urls, $url, $domainHost);
    }

    /**
     * Stage 2: Deep headless Chrome scan — catches dynamically loaded scripts.
     * Requires Puppeteer/Chrome to be installed.
     */
    public static function deepScan(string $url, string $domainHost): array
    {
        if (!class_exists(\Spatie\Browsershot\Browsershot::class)) {
            throw new \RuntimeException('Browsershot package not installed.');
        }

        $validator = new \App\Services\UrlValidator();
        $result = $validator->validateForScanner($url);
        if ($result['error']) {
            throw new \RuntimeException('SSRF Validation Failed: ' . $result['error']);
        }
        $resolvedIp = $result['resolved_ips'][0] ?? null;
        if (!$resolvedIp) {
            throw new \RuntimeException('SSRF Validation Failed: DNS resolution returned no IPs.');
        }

        $chromePath = env('CHROME_PATH', '/usr/bin/chromium-browser');

        $jsonSchema = \Spatie\Browsershot\Browsershot::url($url)
            ->setChromePath($chromePath)
            ->addBrowserArgs([
                "--host-rules=MAP {$result['host']} {$resolvedIp}",
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-gpu',
                '--disable-dev-shm-usage',
                '--disable-extensions',
                '--disable-background-networking',
                '--disable-sync',
                '--disable-translate',
                '--no-first-run',
                '--metrics-recording-only',
                '--js-flags=--max-old-space-size=256',
            ])
            ->waitUntilNetworkIdle()
            ->windowSize(1280, 720)
            ->timeout(30)
            ->evaluate('JSON.stringify({
                scripts: Array.from(document.scripts).map(s => s.src).filter(Boolean),
                iframes: Array.from(document.querySelectorAll("iframe")).map(i => i.src).filter(Boolean),
                stylesheets: Array.from(document.querySelectorAll("link[rel=stylesheet]")).map(l => l.href).filter(Boolean)
            })');

        $data = json_decode($jsonSchema, true);
        if (!$data) return [];

        $allUrls = array_merge(
            $data['scripts'] ?? [],
            $data['iframes'] ?? [],
            $data['stylesheets'] ?? []
        );

        return static::filterExternal($allUrls, $url, $domainHost);
    }

    /**
     * Resolve relative URLs and filter to external only.
     */
    protected static function filterExternal(array $urls, string $baseUrl, string $domainHost): array
    {
        $external = [];
        foreach ($urls as $srcUrl) {
            // Resolve protocol-relative URLs
            if (str_starts_with($srcUrl, '//')) {
                $srcUrl = 'https:' . $srcUrl;
            }
            // Skip relative, data:, or javascript: URLs
            if (!str_starts_with($srcUrl, 'http://') && !str_starts_with($srcUrl, 'https://')) {
                continue;
            }

            $host = parse_url($srcUrl, PHP_URL_HOST);
            if (!$host) continue;

            // Remove www prefix for comparison
            $cleanHost = preg_replace('/^www\./', '', $host);
            $cleanDomain = preg_replace('/^www\./', '', $domainHost);

            // Skip same-domain scripts
            if ($cleanHost === $cleanDomain || str_ends_with($cleanHost, '.' . $cleanDomain)) continue;

            $external[$srcUrl] = true;
        }

        return array_keys($external);
    }

    /**
     * Categorize detected scripts against the template library and installed blockers.
     */
    public static function categorize(?Domain $domain, array $scriptUrls): array
    {
        $templates = TemplateLibraryService::getTemplates();
        $installedScriptBlockers = $domain ? ScriptBlocker::where('domain_id', $domain->id)->get() : collect();
        $installedContentBlockers = $domain ? \App\Models\ContentBlocker::where('domain_id', $domain->id)->get() : collect();

        // Build phrase → template key lookup
        $phraseMap = [];
        foreach ($templates as $key => $tpl) {
            $phrases = $tpl['phrases'] ?? [];
            if (isset($tpl['script_blocker']['phrases'])) {
                $phrases = array_merge($phrases, $tpl['script_blocker']['phrases']);
            }
            // Content blockers have 'hosts'
            if (isset($tpl['hosts'])) {
                $phrases = array_merge($phrases, $tpl['hosts']);
            }
            foreach ($phrases as $phrase) {
                $phraseMap[$phrase] = $key;
            }
        }

        // Build installed phrase lookup (combining script phrases and content blocker hosts)
        $installedPhrases = [];
        foreach ($installedScriptBlockers as $blocker) {
            foreach (($blocker->phrases ?? []) as $phrase) {
                // Store array with type so we know where it came from
                $installedPhrases[$phrase] = [
                    'key' => $blocker->key,
                    'name' => $blocker->name,
                    'type' => 'script'
                ];
            }
        }
        foreach ($installedContentBlockers as $blocker) {
            foreach (($blocker->hostnames ?? []) as $host) {
                $installedPhrases[$host] = [
                    'key' => $blocker->key,
                    'name' => $blocker->name,
                    'type' => 'content'
                ];
            }
        }

        $protected = [];
        $suggested = [];
        $unknown = [];
        $matchedKeys = [];
        $seenHosts = [];

        foreach ($scriptUrls as $srcUrl) {
            $matched = false;
            $host = parse_url($srcUrl, PHP_URL_HOST) ?? 'unknown';

            // 1. Check installed blockers
            foreach ($installedPhrases as $phrase => $blockerData) {
                if (stripos($srcUrl, $phrase) !== false) {
                    $blockerKey = $blockerData['key'];
                    if (!isset($seenHosts['protected_' . $blockerKey])) {
                        $protected[] = [
                            'url' => $srcUrl,
                            'host' => $host,
                            'blocker_name' => $blockerData['name'] ?? $blockerKey,
                            'blocker_key' => $blockerKey,
                            'blocker_type' => $blockerData['type'] ?? 'script',
                        ];
                        $seenHosts['protected_' . $blockerKey] = true;
                    }
                    $matched = true;
                    break;
                }
            }
            if ($matched) continue;

            // 2. Check library templates
            foreach ($phraseMap as $phrase => $templateKey) {
                if (stripos($srcUrl, $phrase) !== false) {
                    if (!isset($matchedKeys[$templateKey])) {
                        $tpl = $templates[$templateKey];
                        $suggested[] = [
                            'url' => $srcUrl,
                            'host' => $host,
                            'template_key' => $templateKey,
                            'template_name' => $tpl['name'],
                            'template_type' => $tpl['type'],
                            'provider' => $tpl['provider'] ?? 'Unknown',
                            'purpose' => $tpl['purpose'] ?? '',
                            'group' => $tpl['group'] ?? 'functional',
                        ];
                        $matchedKeys[$templateKey] = true;
                    }
                    $matched = true;
                    break;
                }
            }
            if ($matched) continue;

            // 3. Unknown — deduplicate by host
            if (!isset($seenHosts['unknown_' . $host])) {
                $unknown[] = [
                    'url' => $srcUrl,
                    'host' => $host,
                ];
                $seenHosts['unknown_' . $host] = true;
            }
        }

        return [
            'protected' => $protected,
            'suggested' => $suggested,
            'unknown' => $unknown,
            'raw' => $scriptUrls,
        ];
    }

    /**
     * Generate an email body for reporting unknown scripts.
     */
    public static function generateReportBody(?Domain $domain, array $unknownScripts, string $customDomainName = ''): string
    {
        $domainName = $domain ? $domain->name : $customDomainName;
        $lines = [];
        $lines[] = "=== YCookies Script Report ===";
        $lines[] = "";
        $lines[] = "Domain: {$domainName}";
        $lines[] = "Scanned at: " . now()->format('Y-m-d H:i:s');
        /** @var \App\Models\User|null $user */
        $user = \Illuminate\Support\Facades\Auth::user();
        $lines[] = "User: " . ($user->name ?? 'Unknown');
        $lines[] = "Email: " . ($user->email ?? 'N/A');
        $lines[] = "";
        $lines[] = "Unknown external scripts detected:";
        $lines[] = str_repeat('-', 50);

        foreach ($unknownScripts as $i => $script) {
            $num = $i + 1;
            $lines[] = "{$num}. Host: {$script['host']}";
            $lines[] = "   URL: {$script['url']}";
            $lines[] = "";
        }

        $lines[] = str_repeat('-', 50);
        $lines[] = "Please consider adding these as library templates.";
        $lines[] = "Sent automatically from YCookies Scanner.";

        return implode("\n", $lines);
    }

    /**
     * Generate an HTML email report for a completed scan.
     */
    public static function generateScanReportHtml(?Domain $domain, array $results, string $customDomainName = ''): string
    {
        $domainName = $domain ? $domain->name : $customDomainName;
        $protected = $results['protected'] ?? [];
        $suggested = $results['suggested'] ?? [];
        $unknown = $results['unknown'] ?? [];
        $raw = $results['raw'] ?? [];
        $stages = $results['stages'] ?? [];
        $pages = $results['discoveredPages'] ?? [];

        $totalScripts = count($raw);
        $pagesCount = count($pages);

        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#1a1a2e;color:#e0e0e0;padding:24px;border-radius:12px;">';
        $html .= '<h1 style="color:#f59e0b;margin:0 0 8px;">🔍 Scan Report</h1>';
        $html .= '<p style="color:#9ca3af;margin:0 0 20px;">Domain: <strong style="color:#fff;">' . e($domainName) . '</strong> — ' . now()->format('M d, Y H:i') . '</p>';

        // Summary stats
        $html .= '<div style="display:flex;gap:12px;margin-bottom:20px;">';
        $html .= '<div style="flex:1;background:rgba(255,255,255,.05);padding:12px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:900;color:#fff;">' . $totalScripts . '</div><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Total Scripts</div></div>';
        $html .= '<div style="flex:1;background:rgba(16,185,129,.1);padding:12px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:900;color:#10b981;">' . count($protected) . '</div><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Protected</div></div>';
        $html .= '<div style="flex:1;background:rgba(245,158,11,.1);padding:12px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:900;color:#f59e0b;">' . count($suggested) . '</div><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Suggested</div></div>';
        $html .= '<div style="flex:1;background:rgba(239,68,68,.1);padding:12px;border-radius:8px;text-align:center;"><div style="font-size:24px;font-weight:900;color:#ef4444;">' . count($unknown) . '</div><div style="font-size:10px;color:#9ca3af;text-transform:uppercase;">Unknown</div></div>';
        $html .= '</div>';

        // Pages scanned
        $html .= '<div style="background:rgba(255,255,255,.03);padding:12px;border-radius:8px;margin-bottom:16px;">';
        $html .= '<h3 style="color:#a78bfa;margin:0 0 8px;">📄 Pages Scanned (' . $pagesCount . ')</h3>';
        foreach ($pages as $page) {
            $html .= '<div style="font-size:12px;color:#6b7280;font-family:monospace;">' . e($page) . '</div>';
        }
        $html .= '</div>';

        // Scripts detail
        if (!empty($suggested)) {
            $html .= '<h3 style="color:#f59e0b;margin:16px 0 8px;">💡 Suggested Scripts</h3>';
            foreach ($suggested as $s) {
                $html .= '<div style="padding:8px 12px;background:rgba(245,158,11,.05);border-radius:6px;margin-bottom:4px;font-size:12px;">';
                $html .= '<strong style="color:#fff;">' . e($s['name'] ?? $s['host']) . '</strong>';
                $html .= ' <span style="color:#6b7280;">— ' . e($s['host']) . '</span>';
                $html .= '</div>';
            }
        }

        if (!empty($unknown)) {
            $html .= '<h3 style="color:#ef4444;margin:16px 0 8px;">⚠️ Unknown Scripts</h3>';
            foreach ($unknown as $u) {
                $html .= '<div style="padding:8px 12px;background:rgba(239,68,68,.05);border-radius:6px;margin-bottom:4px;font-size:12px;">';
                $html .= '<strong style="color:#fff;">' . e($u['host']) . '</strong>';
                $html .= ' <span style="color:#6b7280;">— ' . e($u['url']) . '</span>';
                $html .= '</div>';
            }
        }

        $html .= '<hr style="border:none;border-top:1px solid rgba(255,255,255,.06);margin:20px 0;">';
        $html .= '<p style="font-size:11px;color:#6b7280;text-align:center;">Sent automatically by YCookies Scanner</p>';
        $html .= '</div>';

        return $html;
    }

    // ═══════════════════════════════════════════════════════════════
    // Set-Based Full Coverage Discovery
    // ═══════════════════════════════════════════════════════════════

    /**
     * Discover all pages for a domain, detect priority pages,
     * and organize them into page sets for scheduled rotation.
     *
     * @return array{total: int, priority: int, sets: int, set_size: int}
     */
    public static function discoverAndOrganize(Domain $domain): array
    {
        $baseUrl = 'https://' . preg_replace('#^https?://#', '', $domain->name);
        $domainHost = parse_url($baseUrl, PHP_URL_HOST);
        $progressFile = storage_path('app/discovery_' . $domain->id . '.json');

        // Step 1: Discover all pages (up to MAX_DISCOVERY) with progress
        $allPages = static::discoverAllPages($baseUrl, $domainHost, $domain->id);
        $totalDiscovered = count($allPages);

        // Report progress: organizing
        static::writeProgress($progressFile, [
            'status' => 'organizing',
            'message' => "Organizing {$totalDiscovered} pages into sets…",
        ]);

        // Step 2: Detect priority pages
        $autoPriority = static::detectPriorityPages($allPages, $baseUrl);

        // Step 3: Calculate set size
        $setSize = static::calculateSetSize($totalDiscovered);

        // Step 4: Build and save page sets
        $userPriority = $domain->priority_pages ?? [];
        $allPriority = array_values(array_unique(array_merge($autoPriority, $userPriority)));
        $sets = static::buildPageSets($allPages, $setSize, $allPriority);

        // Step 5: Save to database
        \App\Models\DomainPageSet::where('domain_id', $domain->id)->delete();

        $cycle = $domain->current_cycle ?? 1;
        foreach ($sets as $index => $setPages) {
            \App\Models\DomainPageSet::create([
                'domain_id' => $domain->id,
                'set_index' => $index,
                'pages' => $setPages,
                'page_count' => count($setPages),
                'cycle_number' => $cycle,
            ]);
        }

        // Update domain metadata
        $domain->update([
            'auto_priority_pages' => $autoPriority,
            'discovered_pages_count' => $totalDiscovered,
            'current_set_index' => 0,
            'last_discovery_at' => now(),
        ]);

        // Final progress
        static::writeProgress($progressFile, [
            'status' => 'done',
            'total' => $totalDiscovered,
            'priority' => count($allPriority),
            'sets' => count($sets),
            'set_size' => $setSize,
        ]);

        Log::info("Organized {$domain->name}: {$totalDiscovered} pages → " . count($autoPriority) . " priority + " . count($sets) . " sets of ~{$setSize}");

        return [
            'total' => $totalDiscovered,
            'priority' => count($allPriority),
            'sets' => count($sets),
            'set_size' => $setSize,
        ];
    }

    /**
     * Write progress to a JSON file for live-polling from the UI.
     */
    protected static function writeProgress(string $path, array $data): void
    {
        $current = [];
        if (file_exists($path)) {
            $current = json_decode(file_get_contents($path), true) ?? [];
        }
        // Merge layers into the progress
        if (isset($data['layer'])) {
            $current['layers'][$data['layer']] = $data;
        }
        $current = array_merge($current, array_filter($data, fn($k) => $k !== 'layer', ARRAY_FILTER_USE_KEY));
        $current['updated_at'] = now()->toISOString();
        file_put_contents($path, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read discovery progress file.
     */
    public static function readProgress(int $domainId): ?array
    {
        $path = storage_path('app/discovery_' . $domainId . '.json');
        if (!file_exists($path)) return null;
        return json_decode(file_get_contents($path), true);
    }

    /**
     * Discover ALL pages (up to MAX_DISCOVERY) without smart sampling.
     * Reports progress to a JSON file for live UI updates.
     */
    public static function discoverAllPages(string $baseUrl, string $domainHost, ?int $domainId = null): array
    {
        $progressFile = $domainId ? storage_path('app/discovery_' . $domainId . '.json') : null;
        $pages = [];

        // ─── Layer 0: robots.txt ───
        if ($progressFile) {
            static::writeProgress($progressFile, [
                'status' => 'running',
                'message' => 'Checking robots.txt for sitemap references…',
                'layer' => 'robots',
                'label' => 'robots.txt',
                'icon' => '🤖',
                'state' => 'running',
                'count' => 0,
            ]);
        }
        $sitemapUrls = static::getSitemapUrlsFromRobots($baseUrl);
        if ($progressFile) {
            static::writeProgress($progressFile, [
                'layer' => 'robots',
                'label' => 'robots.txt',
                'icon' => '🤖',
                'state' => 'done',
                'count' => count($sitemapUrls),
                'detail' => count($sitemapUrls) > 0
                    ? count($sitemapUrls) . ' sitemap URL(s) found'
                    : 'No sitemap directives',
            ]);
        }

        // ─── Layer 1: Parse all sitemaps (expanded list) ───
        $sitemapLocations = array_unique(array_merge(
            $sitemapUrls,
            array_map(fn($p) => $baseUrl . $p, static::SITEMAP_PATHS)
        ));

        if ($progressFile) {
            static::writeProgress($progressFile, [
                'status' => 'running',
                'message' => 'Trying ' . count($sitemapLocations) . ' sitemap locations…',
                'layer' => 'sitemaps',
                'label' => 'Sitemaps',
                'icon' => '🗺️',
                'state' => 'running',
                'count' => 0,
                'detail' => 'Checking ' . count($sitemapLocations) . ' patterns…',
            ]);
        }

        $sitemapHits = [];
        foreach ($sitemapLocations as $sitemapUrl) {
            if (count($pages) >= static::MAX_DISCOVERY) break;
            try {
                $sitemapPages = static::parseSitemap($sitemapUrl);
                if (!empty($sitemapPages)) {
                    $pages = array_merge($pages, $sitemapPages);
                    $shortName = parse_url($sitemapUrl, PHP_URL_PATH) ?? $sitemapUrl;
                    $sitemapHits[] = $shortName . ' → ' . count($sitemapPages);
                    Log::info("Sitemap ({$sitemapUrl}): found " . count($sitemapPages) . " pages");

                    if ($progressFile) {
                        static::writeProgress($progressFile, [
                            'message' => count(array_unique($pages)) . ' pages from sitemaps…',
                            'layer' => 'sitemaps',
                            'label' => 'Sitemaps',
                            'icon' => '🗺️',
                            'state' => 'running',
                            'count' => count(array_unique($pages)),
                            'detail' => implode(' | ', $sitemapHits),
                        ]);
                    }
                    break; // Found a working sitemap with results
                }
            } catch (\Exception $e) {
                // Try next
            }
        }

        $pages = array_values(array_unique($pages));
        if ($progressFile) {
            static::writeProgress($progressFile, [
                'layer' => 'sitemaps',
                'label' => 'Sitemaps',
                'icon' => '🗺️',
                'state' => count($pages) > 0 ? 'done' : 'empty',
                'count' => count($pages),
                'detail' => !empty($sitemapHits)
                    ? implode(' | ', $sitemapHits)
                    : 'No sitemaps found',
            ]);
        }

        // ─── Layer 2: Search engines (always try if < 200 pages) ───
        if (count($pages) < 200) {
            if ($progressFile) {
                static::writeProgress($progressFile, [
                    'status' => 'running',
                    'message' => 'Searching Google & Bing for indexed pages…',
                    'layer' => 'search',
                    'label' => 'Search Engines',
                    'icon' => '🔎',
                    'state' => 'running',
                    'count' => 0,
                ]);
            }
            try {
                $searchPages = static::searchEngineDiscovery($domainHost);
                $pages = array_merge($pages, $searchPages);
                Log::info("Search engines: found " . count($searchPages) . " pages for {$domainHost}");
                if ($progressFile) {
                    static::writeProgress($progressFile, [
                        'layer' => 'search',
                        'label' => 'Search Engines',
                        'icon' => '🔎',
                        'state' => count($searchPages) > 0 ? 'done' : 'empty',
                        'count' => count($searchPages),
                        'detail' => count($searchPages) . ' pages from Google & Bing',
                    ]);
                }
            } catch (\Exception $e) {
                if ($progressFile) {
                    static::writeProgress($progressFile, [
                        'layer' => 'search',
                        'label' => 'Search Engines',
                        'icon' => '🔎',
                        'state' => 'error',
                        'count' => 0,
                        'detail' => 'Failed: ' . $e->getMessage(),
                    ]);
                }
            }
        }

        // ─── Layer 3: Crawl internal links (always try if < 50 pages) ───
        if (count($pages) < 50) {
            if ($progressFile) {
                static::writeProgress($progressFile, [
                    'status' => 'running',
                    'message' => 'Crawling internal links (2 levels deep)…',
                    'layer' => 'crawl',
                    'label' => 'Internal Links',
                    'icon' => '🕸️',
                    'state' => 'running',
                    'count' => 0,
                ]);
            }
            try {
                $visited = [];
                $crawled = static::crawlInternalLinks($baseUrl, $domainHost, 2, $visited);
                $pages = array_merge($pages, $crawled);
                Log::info("Link crawl: found " . count($crawled) . " pages for {$domainHost}");
                if ($progressFile) {
                    static::writeProgress($progressFile, [
                        'layer' => 'crawl',
                        'label' => 'Internal Links',
                        'icon' => '🕸️',
                        'state' => count($crawled) > 0 ? 'done' : 'empty',
                        'count' => count($crawled),
                        'detail' => count($crawled) . ' pages from 2-level crawl',
                    ]);
                }
            } catch (\Exception $e) {
                if ($progressFile) {
                    static::writeProgress($progressFile, [
                        'layer' => 'crawl',
                        'label' => 'Internal Links',
                        'icon' => '🕸️',
                        'state' => 'error',
                        'count' => 0,
                        'detail' => 'Failed: ' . $e->getMessage(),
                    ]);
                }
            }
        }

        // ─── Layer 4: Common paths fallback ───
        if (count($pages) < 10) {
            if ($progressFile) {
                static::writeProgress($progressFile, [
                    'status' => 'running',
                    'message' => 'Checking common page paths…',
                    'layer' => 'common',
                    'label' => 'Common Paths',
                    'icon' => '📁',
                    'state' => 'running',
                    'count' => 0,
                ]);
            }
            $commonPages = static::checkCommonPaths($baseUrl);
            $pages = array_merge($pages, $commonPages);
            if ($progressFile) {
                static::writeProgress($progressFile, [
                    'layer' => 'common',
                    'label' => 'Common Paths',
                    'icon' => '📁',
                    'state' => count($commonPages) > 0 ? 'done' : 'empty',
                    'count' => count($commonPages),
                    'detail' => count($commonPages) . ' reachable paths',
                ]);
            }
        }

        // Normalize and deduplicate
        array_unshift($pages, $baseUrl);
        $pages = array_map(fn($url) => rtrim(strtok($url, '#'), '/') ?: $url, $pages);
        $pages = array_values(array_unique($pages));

        // Same-domain filter
        $pages = array_filter($pages, function ($url) use ($domainHost) {
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host) return false;
            $cleanHost = preg_replace('/^www\./', '', $host);
            $cleanDomain = preg_replace('/^www\./', '', $domainHost);
            return $cleanHost === $cleanDomain || str_ends_with($cleanHost, '.' . $cleanDomain);
        });

        $total = count($pages);
        if ($progressFile) {
            static::writeProgress($progressFile, [
                'status' => 'running',
                'message' => "Discovered {$total} unique pages. Organizing…",
                'total_discovered' => $total,
            ]);
        }

        return array_values($pages);
    }

    /**
     * Auto-detect important/priority pages from a URL list.
     * These pages should be scanned in every scheduled scan.
     *
     * Priority criteria:
     * - Homepage (always)
     * - Short-path pages (/about, /contact, etc.)
     * - Pages from COMMON_PATHS that exist
     */
    public static function detectPriorityPages(array $urls, string $baseUrl): array
    {
        $homepage = rtrim($baseUrl, '/');
        $priority = [$homepage];

        foreach ($urls as $url) {
            $path = parse_url($url, PHP_URL_PATH) ?? '/';
            $path = rtrim($path, '/');

            // Single-segment pages (e.g., /about, /contact, /pricing)
            if ($path && substr_count(trim($path, '/'), '/') === 0) {
                $priority[] = $url;
            }
        }

        // Also include any COMMON_PATHS that appear in the URL list
        foreach (static::COMMON_PATHS as $commonPath) {
            $commonUrl = rtrim($baseUrl, '/') . $commonPath;
            $commonNormalized = rtrim($commonUrl, '/');
            foreach ($urls as $url) {
                if (rtrim($url, '/') === $commonNormalized) {
                    $priority[] = $url;
                    break;
                }
            }
        }

        // Deduplicate and cap at 30 priority pages
        return array_slice(array_values(array_unique($priority)), 0, 30);
    }

    /**
     * Calculate optimal set size based on total page count.
     * Targets ~50 sets regardless of site size.
     *
     * Examples:
     *   500 pages → set size 25 → 20 sets
     *   2000 pages → set size 40 → 50 sets
     *   5000 pages → set size 100 → 50 sets
     *   10000 pages → set size 200 → 50 sets
     */
    public static function calculateSetSize(int $totalPages): int
    {
        $maxSetSize = static::maxSetSize();
        if ($totalPages <= $maxSetSize) {
            return $totalPages;
        }

        $targetSize = (int) ceil($totalPages / static::targetSetCount());

        return max(static::minSetSize(), min($maxSetSize, $targetSize));
    }

    /**
     * Split URLs into page sets, excluding priority pages.
     *
     * @param  array $allUrls       All discovered URLs
     * @param  int   $setSize       Target pages per set
     * @param  array $priorityPages Pages to exclude from sets (scanned separately)
     * @return array<int, array>    Array of sets, each an array of URLs
     */
    public static function buildPageSets(array $allUrls, int $setSize, array $priorityPages): array
    {
        // Remove priority pages from the main list
        $priorityNormalized = array_map(fn($u) => rtrim($u, '/'), $priorityPages);
        $remaining = array_filter($allUrls, function ($url) use ($priorityNormalized) {
            return !in_array(rtrim($url, '/'), $priorityNormalized);
        });
        $remaining = array_values($remaining);

        if (empty($remaining)) return [];

        // Split into sets
        return array_values(array_chunk($remaining, $setSize));
    }
}
