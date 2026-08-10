<?php

namespace App\Services;

/**
 * SSRF-Safe URL Validator (v2 — Production-Grade)
 *
 * Validates URLs against SSRF (Server-Side Request Forgery) attacks.
 * Used by both the admin panel (input validation) and the proxy
 * middleware (runtime defense-in-depth).
 *
 * Implements:
 * 1. Origin-only format (scheme+host+port, no userinfo/query/fragment)
 * 2. HTTPS-only protocol allowlist
 * 3. FILTER_VALIDATE_DOMAIN hostname validation
 * 4. DNS resolution with private/reserved IP rejection
 * 5. DNS pinning — returns validated IPs for CURLOPT_RESOLVE
 * 6. Port restriction (443 only, or default)
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html
 */
class UrlValidator
{
    /**
     * Private/reserved IPv4 CIDR ranges that must be blocked.
     * Comprehensive list covering RFC 1918, RFC 5737, RFC 6598, etc.
     */
    protected array $blockedCidrs = [
        '0.0.0.0/8',         // "This" network
        '10.0.0.0/8',        // Private (RFC 1918)
        '100.64.0.0/10',     // Shared address space / CGN (RFC 6598)
        '127.0.0.0/8',       // Loopback
        '169.254.0.0/16',    // Link-local (incl. cloud metadata 169.254.169.254)
        '172.16.0.0/12',     // Private (RFC 1918)
        '192.0.0.0/24',      // IETF protocol assignments
        '192.0.2.0/24',      // TEST-NET-1 (RFC 5737)
        '192.88.99.0/24',    // 6to4 relay anycast
        '192.168.0.0/16',    // Private (RFC 1918)
        '198.18.0.0/15',     // Benchmarking (RFC 2544)
        '198.51.100.0/24',   // TEST-NET-2 (RFC 5737)
        '203.0.113.0/24',    // TEST-NET-3 (RFC 5737)
        '224.0.0.0/4',       // Multicast
        '240.0.0.0/4',       // Reserved for future use
        '255.255.255.255/32', // Broadcast
    ];

    /**
     * Blocked IPv6 addresses/prefixes.
     */
    protected array $blockedIpv6 = [
        '::1',          // Loopback
        '::',           // Unspecified
        'fe80::/10',    // Link-local
        'fc00::/7',     // Unique local (private)
        'ff00::/8',     // Multicast
    ];

    /**
     * Validate a URL is a safe HTTPS origin (Used for domain config / admin input).
     *
     * Returns validated IPs that can be used for DNS pinning
     * via CURLOPT_RESOLVE to prevent DNS rebinding attacks.
     *
     * @return array{valid: bool, error: string|null, host: string|null, port: int|null, resolved_ips: string[]}
     */
    public function validate(string $url): array
    {
        return $this->performValidation($url, false);
    }

    /**
     * Validate a full URL for the ScriptScannerService (allows paths, queries, HTTP/HTTPS).
     *
     * @return array{valid: bool, error: string|null, host: string|null, port: int|null, resolved_ips: string[]}
     */
    public function validateForScanner(string $url): array
    {
        return $this->performValidation($url, true);
    }

    /**
     * @param bool $allowFullUrl If true, allows paths, queries, HTTP scheme, and port 80.
     */
    protected function performValidation(string $url, bool $allowFullUrl): array
    {
        // 1. Parse the URL
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            return $this->fail('Invalid URL format.');
        }

        // 2. Format checks: reject userinfo/passwords
        if (array_key_exists('user', $parsed) || array_key_exists('pass', $parsed)) {
            return $this->fail('URLs with username or password are not allowed.');
        }

        if (!$allowFullUrl) {
            // Origin-only format: reject query, fragment, paths
            if (array_key_exists('query', $parsed)) {
                return $this->fail('URLs with query strings are not allowed. Provide only the origin (e.g., https://example.com).');
            }
            if (array_key_exists('fragment', $parsed)) {
                return $this->fail('URLs with fragments are not allowed. Provide only the origin (e.g., https://example.com).');
            }
            // Allow path only if it's "/" or empty (trailing slash is fine)
            $path = $parsed['path'] ?? '';
            if ($path !== '' && $path !== '/') {
                return $this->fail('URLs with paths are not allowed. Provide only the origin (e.g., https://example.com).');
            }
        }

        // 3. Protocol allowlist
        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($allowFullUrl) {
            if ($scheme !== 'https' && $scheme !== 'http') {
                return $this->fail('Only HTTP and HTTPS URLs are allowed. Received: ' . ($scheme ?: 'none'));
            }
        } else {
            if ($scheme !== 'https') {
                return $this->fail('Only HTTPS URLs are allowed. Received: ' . ($scheme ?: 'none'));
            }
        }

        // 4. Port restriction
        $port = $parsed['port'] ?? null;
        if ($allowFullUrl) {
            if ($port !== null && $port !== 443 && $port !== 80) {
                return $this->fail('Only ports 80 and 443 are allowed.');
            }
            $effectivePort = $port ?? ($scheme === 'http' ? 80 : 443);
        } else {
            if ($port !== null && $port !== 443) {
                return $this->fail('Only port 443 is allowed for HTTPS origins.');
            }
            $effectivePort = $port ?? 443;
        }

        // 5. Hostname validation
        $host = $parsed['host'];

        // Reject IP literals (must use domain names)
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->fail('IP addresses are not allowed. Use a domain name.');
        }

        // Reject IPv6 bracket notation
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return $this->fail('IPv6 literals are not allowed. Use a domain name.');
        }

        // Use PHP's FILTER_VALIDATE_DOMAIN for robust hostname validation
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return $this->fail('Invalid hostname: ' . $host);
        }

        // Reject suspicious characters that could enable URL confusion
        if (str_contains($host, '@') || str_contains($host, '\\') || str_contains($host, '%')) {
            return $this->fail('Hostname contains invalid characters.');
        }

        // Must have at least one dot (reject localhost, etc.)
        if (!str_contains($host, '.')) {
            return $this->fail('Single-label hostnames are not allowed. Use a fully qualified domain name.');
        }

        // 6. DNS resolution — resolve and verify ALL IPs are public
        $dnsResult = $this->resolveAndValidateDns($host);
        if ($dnsResult['error']) {
            return $this->fail($dnsResult['error']);
        }

        return [
            'valid' => true,
            'error' => null,
            'host' => $host,
            'port' => $effectivePort,
            'resolved_ips' => $dnsResult['ips'],
        ];
    }

    /**
     * Resolve DNS and validate all returned IPs are public.
     *
     * Returns the validated IPs so the caller can pin them
     * via CURLOPT_RESOLVE to prevent DNS rebinding.
     *
     * @return array{error: string|null, ips: string[]}
     */
    protected function resolveAndValidateDns(string $host): array
    {
        $ipv4Records = @dns_get_record($host, DNS_A);
        $ipv6Records = @dns_get_record($host, DNS_AAAA);

        $allIps = [];

        if (is_array($ipv4Records)) {
            foreach ($ipv4Records as $record) {
                if (isset($record['ip'])) {
                    $allIps[] = $record['ip'];
                }
            }
        }

        if (is_array($ipv6Records)) {
            foreach ($ipv6Records as $record) {
                if (isset($record['ipv6'])) {
                    $allIps[] = $record['ipv6'];
                }
            }
        }

        if (empty($allIps)) {
            return ['error' => 'Domain does not resolve to any IP address.', 'ips' => []];
        }

        // Validate EVERY resolved IP is public
        foreach ($allIps as $ip) {
            if ($this->isPrivateOrReserved($ip)) {
                return [
                    'error' => "Domain resolves to a private/reserved IP address ({$ip}). This is not allowed for security reasons.",
                    'ips' => [],
                ];
            }
        }

        return ['error' => null, 'ips' => $allIps];
    }

    /**
     * Build CURLOPT_RESOLVE entries to pin DNS for a validated host.
     *
     * This prevents DNS rebinding: the HTTP client will use the
     * pre-validated IPs instead of doing a fresh DNS lookup.
     *
     * @param string   $host The hostname
     * @param int      $port The port (443)
     * @param string[] $ips  The validated IP addresses
     * @return string[] Entries like ["example.com:443:93.184.216.34"]
     *                  IPv6 addresses are bracketed per libcurl requirement.
     */
    public static function buildCurlResolveEntries(string $host, int $port, array $ips): array
    {
        $entries = [];
        foreach ($ips as $ip) {
            // libcurl requires IPv6 addresses in brackets: HOST:PORT:[2001:db8::1]
            $addr = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : $ip;
            $entries[] = "{$host}:{$port}:{$addr}";
        }
        return $entries;
    }

    /**
     * Check if an IP address falls within any blocked range.
     */
    protected function isPrivateOrReserved(string $ip): bool
    {
        // IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // PHP's built-in check catches most private/reserved ranges
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }

            // Additional CIDR checks for ranges PHP's filters may not cover
            foreach ($this->blockedCidrs as $cidr) {
                if ($this->ipInCidr($ip, $cidr)) {
                    return true;
                }
            }

            return false;
        }

        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalizedIp = inet_ntop(inet_pton($ip));

            if ($normalizedIp === '::1' || $normalizedIp === '::') {
                return true;
            }

            foreach ($this->blockedIpv6 as $blocked) {
                if (str_contains($blocked, '/')) {
                    [$prefix, $bits] = explode('/', $blocked);
                    if ($this->ipv6InCidr($ip, $prefix, (int) $bits)) {
                        return true;
                    }
                } elseif ($normalizedIp === inet_ntop(inet_pton($blocked))) {
                    return true;
                }
            }

            return false;
        }

        // Unparseable — block by default
        return true;
    }

    /**
     * Check if an IPv4 address is within a CIDR range.
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        $subnetLong &= $mask;

        return ($ipLong & $mask) === $subnetLong;
    }

    /**
     * Check if an IPv6 address is within a prefix.
     */
    protected function ipv6InCidr(string $ip, string $prefix, int $bits): bool
    {
        $ipBin = inet_pton($ip);
        $prefixBin = inet_pton($prefix);

        if ($ipBin === false || $prefixBin === false) {
            return false;
        }

        $mask = str_repeat("\xff", intdiv($bits, 8));
        if ($bits % 8) {
            $mask .= chr(0xff << (8 - ($bits % 8)));
        }
        $mask = str_pad($mask, 16, "\x00");

        return ($ipBin & $mask) === ($prefixBin & $mask);
    }

    /**
     * Validate a user-provided origin IP address.
     *
     * Simpler than validate() — no URL parsing or DNS resolution needed.
     * Just checks the IP is valid and not private/reserved.
     *
     * @return array{valid: bool, error: string|null, ip: string|null}
     */
    public function validateOriginIp(string $ip): array
    {
        $ip = trim($ip);

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['valid' => false, 'error' => 'Invalid IP address format.', 'ip' => null];
        }

        if ($this->isPrivateOrReserved($ip)) {
            return ['valid' => false, 'error' => "IP address {$ip} is a private or reserved address. A public IP is required.", 'ip' => null];
        }

        return ['valid' => true, 'error' => null, 'ip' => $ip];
    }

    /**
     * Return a failure result.
     */
    protected function fail(string $error): array
    {
        return ['valid' => false, 'error' => $error, 'host' => null, 'port' => null, 'resolved_ips' => []];
    }
}
