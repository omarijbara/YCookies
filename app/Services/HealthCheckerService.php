<?php

namespace App\Services;

use App\Models\Domain;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HealthCheckerService
{
    /** Maximum total run time for all checks (seconds). */
    protected const MAX_TOTAL_TIMEOUT = 60;

    /** Per-check HTTP timeout (seconds). */
    protected const CHECK_TIMEOUT = 8;

    /** Cached response for multiple checks. */
    protected ?\Illuminate\Http\Client\Response $cachedResponse = null;
    protected ?string $cachedUrl = null;
    protected ?string $cachedHtml = null;
    protected ?float $cachedPageLoadTime = null;

    /** Minimum interval between runs for same domain (minutes). */
    protected const MIN_INTERVAL_MINUTES = 5;

    /** Severity per check name. */
    protected const SEVERITIES = [
        'domain_reachable'       => 'critical',
        'proxy_header'           => 'critical',
        'script_injected'        => 'critical',
        'config_endpoint'        => 'critical',
        'config_api'             => 'critical',
        'response_status'        => 'warning',
        'origin_reachable'       => 'warning',
        'consent_endpoint'       => 'warning',
        'no_duplicate_injection' => 'warning',
        'headers_correct'        => 'informational',
        'no_csp_block'           => 'informational',
        'page_load_time'         => 'informational',
        'ssl_validity'           => 'informational',
        'dns_resolution'         => 'informational',
    ];

    /** Human labels per check name. */
    protected const LABELS = [
        'domain_reachable'       => 'Domain reachable through proxy',
        'response_status'        => 'Page returns 2xx status',
        'proxy_header'           => 'Proxy header present',
        'origin_reachable'       => 'Origin server reachable',
        'config_endpoint'        => 'Proxy config endpoint',
        'script_injected'        => 'YCookies script injected',
        'no_duplicate_injection' => 'No duplicate injection',
        'config_api'             => 'Consent config API',
        'consent_endpoint'       => 'Consent logging endpoint',
        'headers_correct'        => 'Response headers correct',
        'no_csp_block'           => 'No CSP blocking',
        'page_load_time'         => 'Page load time',
        'ssl_validity'           => 'SSL certificate',
        'dns_resolution'         => 'DNS resolution',
    ];

    /**
     * Run all health checks for a domain.
     *
     * @return array{status: string, checks: array, response_times: array, headers: array, evidence: array, duration_ms: int, checks_total: int, checks_passed: int, checks_warned: int, checks_failed: int, checks_expected: int}
     */
    public function run(Domain $domain, ?callable $onCheckComplete = null, ?callable $onCheckStart = null): array
    {
        $startTime = microtime(true);
        $checks = [];
        $responseTimes = [];
        $collectedHeaders = [];
        $evidence = [];

        $domainUrl = 'https://' . ltrim($domain->name, 'https://');
        $overrides = $domain->health_check_overrides ?? [];

        // ── Run each check ──────────────────────────────────────────
        $checksToRun = [
            'domain_reachable'      => fn () => $this->checkDomainReachable($domainUrl),
            'response_status'       => fn () => $this->checkResponseStatus($domainUrl),
            'proxy_header'          => fn () => $this->checkProxyHeader($domainUrl),
            'origin_reachable'      => fn () => $this->checkOriginReachable($domain),
            'config_endpoint'       => fn () => $this->checkConfigEndpoint($domain),
            'script_injected'       => fn () => $this->checkScriptInjected($domainUrl),
            'no_duplicate_injection'=> fn () => $this->checkNoDuplicateInjection($domainUrl),
            'config_api'            => fn () => $this->checkConfigApi($domain),
            'consent_endpoint'      => fn () => $this->checkConsentEndpoint(),
            'headers_correct'       => fn () => $this->checkHeadersCorrect($domainUrl),
            'no_csp_block'          => fn () => $this->checkNoCspBlock($domainUrl),
            'page_load_time'        => fn () => $this->checkPageLoadTime($domainUrl),
            'ssl_validity'          => fn () => $this->checkSslValidity($domain->name),
            'dns_resolution'        => fn () => $this->checkDnsResolution($domain->name),
        ];

        // Checks that require a successful domain probe to make sense
        $requiresProbing = [
            'response_status', 'proxy_header', 'script_injected', 'no_duplicate_injection',
            'headers_correct', 'no_csp_block', 'page_load_time'
        ];

        $abortProbing = false;

        foreach ($checksToRun as $name => $runner) {
            // Check for explicit disable via overrides
            if (($overrides[$name] ?? null) === 'disabled') {
                $checks[] = $this->buildResult($name, 'skipped', 'Check disabled in domain settings');
                if ($onCheckComplete) $onCheckComplete($name, 'skipped');
                continue;
            }

            // Abort probing checks if domain is unreachable
            if ($abortProbing && in_array($name, $requiresProbing)) {
                $checks[] = $this->buildResult($name, 'skipped', 'Skipped: Domain is not reachable');
                if ($onCheckComplete) $onCheckComplete($name, 'skipped');
                continue;
            }
            // Abort if total timeout exceeded
            if ((microtime(true) - $startTime) > self::MAX_TOTAL_TIMEOUT) {
                $checks[] = $this->buildResult($name, 'skipped', 'Aborted: total timeout exceeded');
                if ($onCheckComplete) $onCheckComplete($name, 'skipped');
                continue;
            }

            // Notify before check starts
            if ($onCheckStart) $onCheckStart($name);

            try {
                $checkStart = microtime(true);
                $result = $runner();
                $durationMs = (int) ((microtime(true) - $checkStart) * 1000);

                $result['duration_ms'] = $durationMs;
                $responseTimes[$name] = $durationMs;

                // Collect headers evidence
                if (!empty($result['headers'])) {
                    $collectedHeaders[$name] = $result['headers'];
                    unset($result['headers']);
                }

                // Collect detailed evidence
                if (!empty($result['evidence'])) {
                    $evidence[$name] = $result['evidence'];
                    unset($result['evidence']);
                }

                // ── Apply per-domain overrides ──────────────────────
                $result = $this->applyOverride($result, $overrides[$name] ?? null);

                $checks[] = $result;

                // Stop further probing if the domain cannot be reached at all
                if ($name === 'domain_reachable' && $result['status'] === 'fail') {
                    $abortProbing = true;
                }

                // Notify after check completes
                if ($onCheckComplete) $onCheckComplete($name, $result['status']);
            } catch (\Throwable $e) {
                $durationMs = (int) ((microtime(true) - ($checkStart ?? microtime(true))) * 1000);
                $result = $this->buildResult($name, 'fail', 'Exception: ' . $e->getMessage(), $durationMs);
                $result = $this->applyOverride($result, $overrides[$name] ?? null);
                $checks[] = $result;
                $responseTimes[$name] = $durationMs;
                $evidence[$name] = ['exception' => $e->getMessage()];
                Log::warning("Health check '{$name}' exception for {$domain->name}: " . $e->getMessage());

                // Notify after check completes (even on failure)
                if ($onCheckComplete) $onCheckComplete($name, $result['status']);
            }
        }

        // ── Severity-aware aggregation ──────────────────────────────
        $aggregation = $this->aggregateResults($checks);
        $totalDuration = (int) ((microtime(true) - $startTime) * 1000);

        return [
            'status' => $aggregation['status'],
            'checks' => $checks,
            'response_times' => $responseTimes,
            'headers' => $collectedHeaders,
            'evidence' => $evidence,
            'duration_ms' => $totalDuration,
            'checks_total' => $aggregation['total'],
            'checks_passed' => $aggregation['passed'],
            'checks_warned' => $aggregation['warned'],
            'checks_failed' => $aggregation['failed'],
            'checks_expected' => $aggregation['expected'],
        ];
    }

    /**
     * Check if the domain can be run now (respects min interval).
     */
    public function canRunNow(Domain $domain): bool
    {
        if (!$domain->last_health_check_at) {
            return true;
        }

        return $domain->last_health_check_at->diffInMinutes(now()) >= self::MIN_INTERVAL_MINUTES;
    }

    // ─── Override Application ───────────────────────────────────────

    /**
     * Apply a per-domain override to a check result.
     *
     * Overrides: 'expected' | 'ignored' | 'warn_only' | null
     */
    protected function applyOverride(array $result, ?string $override): array
    {
        if (!$override) {
            return $result;
        }

        $result['override'] = $override;

        switch ($override) {
            case 'expected':
                // Mark as expected regardless of outcome
                if (in_array($result['status'], ['fail', 'warn'])) {
                    $result['original_status'] = $result['status'];
                    $result['status'] = 'expected';
                    $result['message'] = $result['message'] . ' (expected)';
                }
                break;

            case 'ignored':
                // Mark as ignored — still runs but excluded from scoring
                $result['original_status'] = $result['status'];
                $result['status'] = 'ignored';
                break;

            case 'warn_only':
                // Downgrade fail to warn
                if ($result['status'] === 'fail') {
                    $result['original_status'] = 'fail';
                    $result['status'] = 'warn';
                    $result['message'] = $result['message'] . ' (downgraded to warning)';
                }
                break;
        }

        return $result;
    }

    // ─── Aggregation ────────────────────────────────────────────────

    /**
     * Severity-aware result aggregation.
     *
     * Rules:
     *   failing  = any critical check fails
     *   warning  = any warning-severity check fails, OR >2 informational issues
     *   healthy  = everything else
     *
     * Expected, ignored, and skipped checks are excluded from failure scoring.
     */
    protected function aggregateResults(array $checks): array
    {
        $total = count($checks);
        $passed = 0;
        $warned = 0;
        $failed = 0;
        $expected = 0;
        $criticalFails = 0;
        $warningFails = 0;
        $infoIssues = 0;

        foreach ($checks as $check) {
            $status = $check['status'];
            $severity = $check['severity'] ?? 'informational';

            // Excluded from scoring
            if (in_array($status, ['expected', 'ignored', 'skipped'])) {
                if ($status === 'expected') {
                    $expected++;
                }
                continue;
            }

            if ($status === 'pass') {
                $passed++;
            } elseif ($status === 'warn') {
                $warned++;
                if ($severity === 'informational') {
                    $infoIssues++;
                }
            } elseif ($status === 'fail') {
                $failed++;
                match ($severity) {
                    'critical' => $criticalFails++,
                    'warning' => $warningFails++,
                    default => $infoIssues++,
                };
            }
        }

        // Determine overall status
        if ($criticalFails > 0) {
            $overallStatus = 'failing';
        } elseif ($warningFails > 0 || $infoIssues > 2) {
            $overallStatus = 'warning';
        } else {
            $overallStatus = 'healthy';
        }

        return [
            'status' => $overallStatus,
            'total' => $total,
            'passed' => $passed,
            'warned' => $warned,
            'failed' => $failed,
            'expected' => $expected,
        ];
    }

    // ─── Individual Checks ──────────────────────────────────────────

    /**
     * 1. Domain reachable through proxy.
     */
    protected function checkDomainReachable(string $url): array
    {
        try {
            $response = $this->fetchPage($url);
            $status = $response->status();
        } catch (\Throwable $e) {
            return $this->buildResult('domain_reachable', 'fail', "Connection failed: " . $e->getMessage());
        }

        if ($status >= 200 && $status < 300) {
            return $this->buildResult('domain_reachable', 'pass', "HTTP {$status}");
        }

        if ($status >= 300 && $status < 400) {
            return $this->buildResult('domain_reachable', 'warn', "Redirect: HTTP {$status}");
        }

        return $this->buildResult('domain_reachable', 'fail', "HTTP {$status}");
    }

    /**
     * 2. Response status is not 4xx/5xx.
     */
    protected function checkResponseStatus(string $url): array
    {
        try {
            $response = $this->fetchPage($url);
            $status = $response->status();
        } catch (\Throwable $e) {
            return $this->buildResult('response_status', 'fail', "Connection failed: " . $e->getMessage());
        }

        if ($status >= 200 && $status < 300) {
            return $this->buildResult('response_status', 'pass', "HTTP {$status}");
        }

        if ($status >= 300 && $status < 400) {
            return $this->buildResult('response_status', 'warn', "Redirect: HTTP {$status}");
        }

        return $this->buildResult('response_status', 'fail', "HTTP {$status}", evidence: [
            'status_code' => $status,
        ]);
    }

    /**
     * 3. x-proxy: ycookies header present.
     */
    protected function checkProxyHeader(string $url): array
    {
        try {
            $response = $this->fetchPage($url);
            $proxyHeader = $response->header('x-proxy');
        } catch (\Throwable $e) {
            return $this->buildResult('proxy_header', 'fail', "Connection failed: " . $e->getMessage());
        }

        if ($proxyHeader === 'ycookies') {
            return $this->buildResult('proxy_header', 'pass', 'x-proxy: ycookies present', headers: [
                'x-proxy' => $proxyHeader,
            ]);
        }

        return $this->buildResult('proxy_header', 'fail', $proxyHeader
            ? "x-proxy header has unexpected value: {$proxyHeader}"
            : 'x-proxy header missing');
    }

    /**
     * 4. Origin reachable directly.
     *
     * Auto-detects protected origins: if the origin returns 403/503,
     * this is treated as "expected" (protected by firewall or auth).
     */
    protected function checkOriginReachable(Domain $domain): array
    {
        // Try all origin fields in order of preference
        $target = null;
        $targetSource = null;

        if ($domain->origin_url) {
            $target = $domain->origin_url;
            $targetSource = 'origin_url';
        } elseif ($domain->origin_subdomain) {
            $target = "https://{$domain->origin_subdomain}";
            $targetSource = 'origin_subdomain';
        } elseif ($domain->origin_ip) {
            // Use origin_host for the Host header if available, fall back to IP
            $target = "https://{$domain->origin_ip}";
            $targetSource = 'origin_ip';
        } elseif ($domain->origin_host) {
            $target = "https://{$domain->origin_host}";
            $targetSource = 'origin_host';
        }

        if (!$target) {
            return $this->buildResult('origin_reachable', 'warn', 'No origin configured');
        }

        try {
            $start = microtime(true);
            $response = Http::timeout(self::CHECK_TIMEOUT)
                ->withOptions(['verify' => false])
                ->get($target);
            $elapsed = microtime(true) - $start;

            $status = $response->status();

            // Reachable and healthy
            if ($status >= 200 && $status < 400) {
                if ($elapsed > 5) {
                    return $this->buildResult('origin_reachable', 'warn', "Slow: HTTP {$status} in " . round($elapsed, 1) . "s ({$targetSource})");
                }
                return $this->buildResult('origin_reachable', 'pass', "HTTP {$status} in " . round($elapsed, 1) . "s ({$targetSource})");
            }

            // Auto-detect protected origin: 403 or 503 on a proxied domain
            // is very likely intentional firewall/auth protection
            if (in_array($status, [403, 503])) {
                return $this->buildResult(
                    'origin_reachable',
                    'expected',
                    "Origin protected (HTTP {$status}) — direct access blocked ({$targetSource})",
                    evidence: ['protected' => true, 'status_code' => $status, 'target' => $target]
                );
            }

            return $this->buildResult('origin_reachable', 'fail', "HTTP {$status} ({$targetSource})");
        } catch (\Throwable $e) {
            // Connection failure on a proxied domain is common with firewall rules
            if (str_contains($e->getMessage(), 'Connection refused') ||
                str_contains($e->getMessage(), 'Connection reset') ||
                str_contains($e->getMessage(), 'timed out')) {
                return $this->buildResult(
                    'origin_reachable',
                    'expected',
                    "Origin protected (connection blocked) — firewall likely active ({$targetSource})",
                    evidence: ['protected' => true, 'error' => $e->getMessage()]
                );
            }

            return $this->buildResult('origin_reachable', 'fail', "Connection failed: {$e->getMessage()} ({$targetSource})");
        }
    }

    /**
     * 5. Proxy config endpoint returns valid JSON.
     */
    protected function checkConfigEndpoint(Domain $domain): array
    {
        $configUrl = config('app.url') . '/api/proxy-config/' . $domain->name;

        try {
            $response = Http::timeout(self::CHECK_TIMEOUT)
                ->withHeaders($this->proxyConfigSignatureHeaders($domain->name))
                ->get($configUrl);

            if ($response->status() !== 200) {
                return $this->buildResult('config_endpoint', 'fail', "HTTP {$response->status()}");
            }

            $json = $response->json();
            if (!$json || !isset($json['domain'])) {
                return $this->buildResult('config_endpoint', 'fail', 'Invalid JSON structure');
            }

            return $this->buildResult('config_endpoint', 'pass', 'Valid config returned');
        } catch (\Throwable $e) {
            return $this->buildResult('config_endpoint', 'fail', 'Failed: ' . $e->getMessage());
        }
    }

    /**
     * Sign internal proxy-config probes so health checks follow the same auth
     * boundary as the Node proxy.
     *
     * @return array<string, string>
     */
    protected function proxyConfigSignatureHeaders(string $host): array
    {
        $secret = config('services.proxy.shared_secret');

        if (empty($secret)) {
            return [];
        }

        $normalizedHost = strtolower(trim($host));

        return [
            'X-Proxy-Signature' => hash_hmac('sha256', $normalizedHost, $secret),
        ];
    }

    /**
     * 6. YCookies script tag is injected in the HTML.
     */
    protected function checkScriptInjected(string $url): array
    {
        $html = $this->getCachedHtml($url);
        if (!$html) {
            return $this->buildResult('script_injected', 'fail', 'Could not fetch page HTML');
        }

        // Look for manager-*.js or bootstrapper script
        if (
            preg_match('/manager-[A-Za-z0-9]+\.js/', $html) ||
            str_contains($html, '/api/bootstrapper/') ||
            str_contains($html, '/api/script/')
        ) {
            return $this->buildResult('script_injected', 'pass', 'YCookies script found');
        }

        return $this->buildResult('script_injected', 'fail', 'YCookies script not found in HTML');
    }

    /**
     * 7. Consent config API works.
     */
    protected function checkConfigApi(Domain $domain): array
    {
        if (!$domain->site_id) {
            return $this->buildResult('config_api', 'warn', 'No site_id configured');
        }

        $configUrl = config('app.url') . '/api/config/' . $domain->site_id;

        try {
            $response = Http::timeout(self::CHECK_TIMEOUT)->get($configUrl);

            if ($response->status() !== 200) {
                return $this->buildResult('config_api', 'fail', "HTTP {$response->status()}");
            }

            $json = $response->json();
            if (!$json) {
                return $this->buildResult('config_api', 'fail', 'Invalid JSON response');
            }

            return $this->buildResult('config_api', 'pass', 'Config API returns valid JSON');
        } catch (\Throwable $e) {
            return $this->buildResult('config_api', 'fail', 'Failed: ' . $e->getMessage());
        }
    }

    /**
     * 8. Consent logging endpoint is reachable with correct CORS.
     */
    protected function checkConsentEndpoint(): array
    {
        $url = config('app.url') . '/api/log-consent';

        try {
            $response = Http::timeout(self::CHECK_TIMEOUT)
                ->withHeaders([
                    'Origin' => 'https://example.com',
                    'Access-Control-Request-Method' => 'POST',
                    'Access-Control-Request-Headers' => 'content-type',
                ])
                ->send('OPTIONS', $url);

            $status = $response->status();
            $allowOrigin = $response->header('Access-Control-Allow-Origin');
            $allowMethods = $response->header('Access-Control-Allow-Methods');

            $headers = [
                'status' => $status,
                'access-control-allow-origin' => $allowOrigin,
                'access-control-allow-methods' => $allowMethods,
            ];

            if ($status === 204 && $allowOrigin) {
                return $this->buildResult('consent_endpoint', 'pass', 'CORS preflight OK', headers: $headers);
            }

            if ($status === 200 && $allowOrigin) {
                return $this->buildResult('consent_endpoint', 'pass', 'Endpoint reachable with CORS', headers: $headers);
            }

            return $this->buildResult('consent_endpoint', 'fail', "Unexpected response: HTTP {$status}", headers: $headers);
        } catch (\Throwable $e) {
            return $this->buildResult('consent_endpoint', 'fail', 'Failed: ' . $e->getMessage());
        }
    }

    /**
     * 9. No duplicate script injection.
     */
    protected function checkNoDuplicateInjection(string $url): array
    {
        $html = $this->getCachedHtml($url);
        if (!$html) {
            return $this->buildResult('no_duplicate_injection', 'fail', 'Could not fetch page HTML');
        }

        $managerCount = preg_match_all('/manager-[A-Za-z0-9]+\.js/', $html);
        $bootstrapperCount = substr_count($html, '/api/bootstrapper/');
        $scriptDeliveryCount = substr_count($html, '/api/script/');

        $totalInjections = max($managerCount, $bootstrapperCount + $scriptDeliveryCount);

        if ($totalInjections === 1) {
            return $this->buildResult('no_duplicate_injection', 'pass', 'Exactly 1 injection found');
        }

        if ($totalInjections === 0) {
            return $this->buildResult('no_duplicate_injection', 'fail', 'No injection found');
        }

        return $this->buildResult('no_duplicate_injection', 'fail', "{$totalInjections} injections found (expected 1)", evidence: [
            'manager_count' => $managerCount,
            'bootstrapper_count' => $bootstrapperCount,
            'script_delivery_count' => $scriptDeliveryCount,
        ]);
    }

    /**
     * 10. Response headers look correct.
     */
    protected function checkHeadersCorrect(string $url): array
    {
        $response = $this->fetchPage($url);

        $required = ['x-proxy'];
        $optional = ['x-yc-cache', 'x-yc-cache-reason'];
        $collected = [];
        $missing = [];

        foreach ($required as $header) {
            $val = $response->header($header);
            if ($val) {
                $collected[$header] = $val;
            } else {
                $missing[] = $header;
            }
        }

        foreach ($optional as $header) {
            $val = $response->header($header);
            if ($val) {
                $collected[$header] = $val;
            }
        }

        if (empty($missing)) {
            return $this->buildResult('headers_correct', 'pass', 'All required headers present', headers: $collected);
        }

        return $this->buildResult('headers_correct', 'warn', 'Missing headers: ' . implode(', ', $missing), headers: $collected);
    }

    /**
     * 11. CSP does not block cookies.ypsilon.dev.
     */
    protected function checkNoCspBlock(string $url): array
    {
        $response = $this->fetchPage($url);
        $csp = $response->header('content-security-policy') ?? $response->header('content-security-policy-report-only');

        if (!$csp) {
            return $this->buildResult('no_csp_block', 'pass', 'No CSP header (no blocking risk)');
        }

        $cookiesDomain = parse_url(config('app.url'), PHP_URL_HOST);

        // Check script-src and connect-src directives
        $blocked = false;
        $details = [];

        if (preg_match("/script-src\s+([^;]+)/i", $csp, $m)) {
            $scriptSrc = $m[1];
            if (!str_contains($scriptSrc, "'unsafe-inline'") &&
                !str_contains($scriptSrc, $cookiesDomain) &&
                !str_contains($scriptSrc, "'nonce-")) {
                $blocked = true;
                $details['script-src'] = 'May block YCookies scripts';
            }
        }

        if (preg_match("/connect-src\s+([^;]+)/i", $csp, $m)) {
            $connectSrc = $m[1];
            if (!str_contains($connectSrc, $cookiesDomain) && !str_contains($connectSrc, '*')) {
                $details['connect-src'] = 'May block consent API calls';
            }
        }

        if ($blocked) {
            return $this->buildResult('no_csp_block', 'fail', 'CSP may block YCookies', evidence: $details, headers: ['csp' => $csp]);
        }

        if (!empty($details)) {
            return $this->buildResult('no_csp_block', 'warn', 'CSP partially restrictive', evidence: $details, headers: ['csp' => $csp]);
        }

        return $this->buildResult('no_csp_block', 'pass', 'CSP allows YCookies', headers: ['csp' => substr($csp, 0, 200)]);
    }

    protected function checkPageLoadTime(string $url): array
    {
        // Ensure the page is fetched first so we have the real connection time
        $this->fetchPage($url);
        
        $elapsed = $this->cachedPageLoadTime ?? 0;
        $seconds = round($elapsed, 2);

        if ($elapsed < 3) {
            return $this->buildResult('page_load_time', 'pass', "TTFB: {$seconds}s");
        }

        if ($elapsed < 10) {
            return $this->buildResult('page_load_time', 'warn', "Slow TTFB: {$seconds}s");
        }

        return $this->buildResult('page_load_time', 'fail', "Very slow TTFB: {$seconds}s");
    }

    /**
     * 13. SSL certificate validity.
     */
    protected function checkSslValidity(string $hostname): array
    {
        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $client = @stream_socket_client(
                "ssl://{$hostname}:443",
                $errno, $errstr, self::CHECK_TIMEOUT,
                STREAM_CLIENT_CONNECT, $context
            );

            if (!$client) {
                return $this->buildResult('ssl_validity', 'fail', "SSL connection failed: {$errstr}");
            }

            $params = stream_context_get_params($client);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate'] ?? null);
            fclose($client);

            if (!$cert) {
                return $this->buildResult('ssl_validity', 'fail', 'Could not parse SSL certificate');
            }

            $validTo = $cert['validTo_time_t'] ?? 0;
            $daysLeft = (int) (($validTo - time()) / 86400);
            $issuer = $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? 'Unknown';
            $subject = $cert['subject']['CN'] ?? 'Unknown';

            $evidence = [
                'issuer' => $issuer,
                'subject' => $subject,
                'valid_to' => date('Y-m-d', $validTo),
                'days_left' => $daysLeft,
            ];

            if ($daysLeft < 0) {
                return $this->buildResult('ssl_validity', 'fail', "SSL expired {$daysLeft} days ago (Issuer: {$issuer})", evidence: $evidence);
            }

            if ($daysLeft < 7) {
                return $this->buildResult('ssl_validity', 'warn', "SSL expires in {$daysLeft} days (Issuer: {$issuer})", evidence: $evidence);
            }

            if ($daysLeft < 30) {
                return $this->buildResult('ssl_validity', 'warn', "SSL expires in {$daysLeft} days — renew soon (Issuer: {$issuer})", evidence: $evidence);
            }

            return $this->buildResult('ssl_validity', 'pass', "SSL valid for {$daysLeft} days (Issuer: {$issuer})", evidence: $evidence);

        } catch (\Throwable $e) {
            return $this->buildResult('ssl_validity', 'fail', 'SSL check failed: ' . $e->getMessage());
        }
    }

    /**
     * 14. DNS resolution check.
     */
    protected function checkDnsResolution(string $hostname): array
    {
        try {
            $records = dns_get_record($hostname, DNS_A | DNS_AAAA | DNS_CNAME);

            if (empty($records)) {
                return $this->buildResult('dns_resolution', 'fail', 'No DNS records found');
            }

            $ips = [];
            $cnames = [];
            foreach ($records as $record) {
                if (isset($record['ip'])) $ips[] = $record['ip'];
                if (isset($record['ipv6'])) $ips[] = $record['ipv6'];
                if (isset($record['target'])) $cnames[] = $record['target'];
            }

            $evidence = [
                'records' => count($records),
                'ips' => $ips,
                'cnames' => $cnames,
            ];

            $summary = count($ips) . ' IP(s)';
            if (!empty($cnames)) {
                $summary .= ', CNAME → ' . $cnames[0];
            }

            return $this->buildResult('dns_resolution', 'pass', "DNS resolves: {$summary}", evidence: $evidence);

        } catch (\Throwable $e) {
            return $this->buildResult('dns_resolution', 'fail', 'DNS lookup failed: ' . $e->getMessage());
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────

    /**
     * Build a standardized check result with severity.
     */
    protected function buildResult(
        string $name,
        string $status,
        string $message,
        ?int $durationMs = null,
        array $headers = [],
        array $evidence = [],
    ): array {
        $result = [
            'name' => $name,
            'label' => self::LABELS[$name] ?? $name,
            'severity' => self::SEVERITIES[$name] ?? 'informational',
            'status' => $status,
            'message' => $message,
        ];

        if ($durationMs !== null) {
            $result['duration_ms'] = $durationMs;
        }

        if (!empty($headers)) {
            $result['headers'] = $headers;
        }

        if (!empty($evidence)) {
            $result['evidence'] = $evidence;
        }

        return $result;
    }

    /**
     * Cached HTML body for a URL (avoids re-fetching for multiple HTML checks).
     */
    protected function fetchPage(string $url): \Illuminate\Http\Client\Response
    {
        if ($this->cachedResponse && $this->cachedUrl === $url) {
            return $this->cachedResponse;
        }

        $this->cachedUrl = $url;
        $start = microtime(true);
        $this->cachedResponse = Http::timeout(self::CHECK_TIMEOUT)
            ->withOptions(['allow_redirects' => ['max' => 5, 'track_redirects' => true]])
            ->get($url);
        
        $this->cachedPageLoadTime = microtime(true) - $start;
        $this->cachedHtml = $this->cachedResponse->body();

        return $this->cachedResponse;
    }

    protected function getCachedHtml(string $url): ?string
    {
        if ($this->cachedUrl === $url && $this->cachedHtml !== null) {
            return $this->cachedHtml;
        }

        try {
            $this->fetchPage($url);
            return $this->cachedHtml;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
