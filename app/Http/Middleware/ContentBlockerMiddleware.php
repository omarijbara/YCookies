<?php

namespace App\Http\Middleware;

use App\Models\ContentBlocker;
use App\Models\Domain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content Blocker Runtime Middleware
 *
 * Intercepts HTML responses and replaces <iframe>, <embed>, <object>, and
 * <video> tags whose src matches configured content blockers with a
 * consent-placeholder that shows a "click to unblock" message.
 *
 * Mirrors Borlabs Cookie's ContentBlockerManager:
 * - Host-based matching: compares iframe src domain against configured host patterns
 * - Base64 encoding: original tag is base64-encoded into a data attribute
 * - Preview placeholder: shows a styled div with consent button
 * - Fallback blocker: if no specific blocker matches, uses a generic blocker
 *
 * Self-exclusion: never blocks iframes from the same domain or ycookies sub-paths.
 */
class ContentBlockerMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only process HTML responses
        if (! $this->isHtmlResponse($response)) {
            return $response;
        }

        // Get the domain from the request
        $domain = $this->resolveDomain($request);
        if (! $domain) {
            return $response;
        }

        // Active content blockers (may be empty — universal external-media fallback still applies)
        $blockers = $this->getContentBlockers($domain);

        // Intercept and modify the HTML content
        $html = $response->getContent();
        $modified = $this->blockContent($html, $blockers, $request->getHost());

        if ($modified !== $html) {
            $response->setContent($modified);
        }

        return $response;
    }

    protected function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html')
            && $response->getStatusCode() === 200
            && ! empty($response->getContent());
    }

    protected function resolveDomain(Request $request): ?Domain
    {
        $host = $request->getHost();

        return Cache::remember("domain_by_host:{$host}", 300, function () use ($host) {
            return Domain::where('name', $host)->where('is_active', true)->first();
        });
    }

    protected function getContentBlockers(Domain $domain)
    {
        return Cache::remember("content_blockers:{$domain->id}", 300, function () use ($domain) {
            return ContentBlocker::where('is_active', true)
                ->where(function ($q) use ($domain) {
                    $q->where('domain_id', $domain->id)
                      ->orWhere(fn ($q) => $q->whereNull('domain_id')
                          ->where('is_system', true)
                          ->where('group_id', $domain->group_id));
                })
                ->with('service')
                ->get();
        });
    }

    /**
     * Block matching embedded content (iframes, embeds, objects, videos).
     */
    protected function blockContent(string $html, $blockers, string $currentHost): string
    {
        // Match <iframe>, <embed>, <object>, and <video> tags
        $pattern = '/<(iframe|embed|object|video)\b([^>]*)(?:\/>|>(.*?)<\/\1>)/is';

        return preg_replace_callback(
            $pattern,
            function ($match) use ($blockers, $currentHost) {
                $tag = $match[1];
                $attributes = $match[2];
                $fullMatch = $match[0];
                $innerContent = $match[3] ?? '';

                // Extract src URL
                $src = '';
                if (preg_match('/(?:src|data)\s*=\s*["\']([^"\']*)["\']/', $attributes, $srcMatch)) {
                    $src = $srcMatch[1];
                }

                // Self-exclusion: don't block same-domain content
                if (! empty($src)) {
                    $srcHost = parse_url($src, PHP_URL_HOST);
                    if ($srcHost === $currentHost || stripos($src, 'ycookies') !== false) {
                        return $fullMatch;
                    }
                }

                // Find a matching configured content blocker (excludes wildcard * hosts)
                $matchedBlocker = $this->findMatchingBlocker($src, $blockers);

                if ($matchedBlocker) {
                    return $this->buildPlaceholder($fullMatch, $matchedBlocker, $src);
                }

                // Universal fallback: use DB-backed wildcard blocker if it exists
                if ($this->shouldUseUniversalExternalBlocker($src, $currentHost)) {
                    $wildcardBlocker = $this->findWildcardBlocker($blockers);
                    if ($wildcardBlocker) {
                        return $this->buildPlaceholder($fullMatch, $wildcardBlocker, $src);
                    }

                    return $this->buildUniversalPlaceholder($fullMatch, $src);
                }

                return $fullMatch;
            },
            $html
        ) ?? $html;
    }

    /**
     * Find the first content blocker that matches the given URL's host.
     * Uses Borlabs-style host pattern matching.
     */
    protected function findMatchingBlocker(string $url, $blockers): ?ContentBlocker
    {
        if (empty($url)) {
            return null;
        }

        $urlHost = parse_url($url, PHP_URL_HOST);
        if (! $urlHost) {
            return null;
        }

        foreach ($blockers as $blocker) {
            if (empty($blocker->hosts)) {
                continue;
            }

            foreach ($blocker->hosts as $hostPattern) {
                $hostPattern = trim($hostPattern);
                if (empty($hostPattern) || $hostPattern === '*') {
                    continue;
                }

                // Direct match or wildcard subdomain match
                if ($urlHost === $hostPattern
                    || str_ends_with($urlHost, '.'.$hostPattern)
                    || fnmatch($hostPattern, $urlHost)) {
                    return $blocker;
                }
            }
        }

        return null;
    }

    /**
     * Find the DB-backed wildcard (*) content blocker for universal fallback.
     */
    protected function findWildcardBlocker($blockers): ?ContentBlocker
    {
        foreach ($blockers as $blocker) {
            if (! empty($blocker->hosts) && in_array('*', $blocker->hosts, true)) {
                return $blocker;
            }
        }

        return null;
    }

    /**
     * True when the embed URL is a third-party resource we should block until
     * the visitor consents to the external_media category (mirrors Node html-blocker.js).
     */
    protected function shouldUseUniversalExternalBlocker(string $src, string $currentHost): bool
    {
        if ($src === '') {
            return false;
        }

        $normalized = $src;
        if (str_starts_with($normalized, '//')) {
            $normalized = 'https:'.$normalized;
        }

        $scheme = parse_url($normalized, PHP_URL_SCHEME);
        if (is_string($scheme) && in_array(strtolower($scheme), ['data', 'javascript', 'about', 'blob'], true)) {
            return false;
        }

        // Relative URLs (no host) — leave to the browser origin; do not universal-block
        if (! str_contains($normalized, '://')) {
            return false;
        }

        $srcHost = parse_url($normalized, PHP_URL_HOST);
        if (! is_string($srcHost) || $srcHost === '') {
            return false;
        }

        $cleanSite = strtolower(preg_replace('/^www\./i', '', $currentHost));
        $cleanSrc = strtolower(preg_replace('/^www\./i', '', $srcHost));

        if ($cleanSrc === $cleanSite || str_ends_with($cleanSrc, '.'.$cleanSite)) {
            return false;
        }

        if (stripos($normalized, 'ycookies') !== false) {
            return false;
        }

        return true;
    }

    protected function universalProviderLabel(string $src): string
    {
        try {
            $normalized = str_starts_with($src, '//') ? 'https:'.$src : $src;
            $host = parse_url($normalized, PHP_URL_HOST);
            if (! is_string($host) || $host === '') {
                return 'External content';
            }
            $host = strtolower(preg_replace('/^www\./i', '', $host));
            $parts = explode('.', $host);
            if (count($parts) >= 2) {
                return $parts[count($parts) - 2].'.'.$parts[count($parts) - 1];
            }

            return $host;
        } catch (\Throwable) {
            return 'External content';
        }
    }

    /**
     * Placeholder for unknown third-party embeds; consent via cookie group "external_media".
     */
    protected function buildUniversalPlaceholder(string $originalTag, string $src): string
    {
        $encodedOriginal = base64_encode($originalTag);
        $label = e($this->universalProviderLabel($src));

        $placeholderContent = <<<HTML
            <div style="text-align:center;padding:20px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 12px;opacity:0.7;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                <p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#e2e8f0;">External content</p>
                <p style="margin:0 0 16px;font-size:12px;color:#94a3b8;">Embedded content from <strong>{$label}</strong> requires consent for external media.</p>
                <button type="button" onclick="window.YCookies &amp;&amp; window.YCookies.manager &amp;&amp; window.YCookies.manager.saveConsent({ external_media: true })" style="background:#3b82f6;color:white;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;transition:all .2s;">Accept external media</button>
            </div>
HTML;

        return <<<HTML
<div class="ycookies-content-blocker"
     data-ycookies-blocker-id="universal_external"
     data-ycookies-service=""
     data-ycookies-require-group="external_media"
     data-ycookies-original="{$encodedOriginal}"
     style="background:linear-gradient(135deg,#1e293b 0%,#334155 100%);min-height:200px;display:flex;align-items:center;justify-content:center;border-radius:8px;overflow:hidden;position:relative;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;">
    {$placeholderContent}
</div>
HTML;
    }

    /**
     * Build an HTML placeholder div that replaces the blocked content.
     * The original tag is base64-encoded for later restoration by frontend JS.
     */
    protected function buildPlaceholder(string $originalTag, ContentBlocker $blocker, string $src): string
    {
        $encodedOriginal = base64_encode($originalTag);
        $blockerKey = e($blocker->key);
        $blockerName = e($blocker->name);
        $serviceKey = $blocker->service ? e($blocker->service->key) : '';
        $previewImage = $blocker->preview_image_url ? e($blocker->preview_image_url) : '';

        // Use custom HTML/CSS if configured, otherwise generate default placeholder
        $customHtml = $blocker->html_code ?? '';
        $customCss = $blocker->css_code ?? '';

        $backgroundStyle = $previewImage
            ? "background-image:url('{$previewImage}');background-size:cover;background-position:center;"
            : 'background:linear-gradient(135deg,#1e293b 0%,#334155 100%);';

        $consentJson = $blocker->service
            ? json_encode([$blocker->service->key => true], JSON_THROW_ON_ERROR)
            : json_encode(['external_media' => true], JSON_THROW_ON_ERROR);
        $consentAttr = htmlspecialchars($consentJson, ENT_QUOTES, 'UTF-8');

        $placeholderContent = ! empty($customHtml) ? $customHtml : <<<HTML
            <div style="text-align:center;padding:20px;">
                <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 12px;opacity:0.7;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                <p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#e2e8f0;">Content blocked</p>
                <p style="margin:0 0 16px;font-size:12px;color:#94a3b8;">This content requires your consent to load <strong>{$blockerName}</strong>.</p>
                <button type="button" data-yc-consent="{$consentAttr}" onclick="var m=window.YCookies&amp;&amp;window.YCookies.manager;if(m){try{m.saveConsent(JSON.parse(this.getAttribute('data-yc-consent')));}catch(e){}}" style="background:#3b82f6;color:white;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;transition:all .2s;">Accept &amp; Load</button>
            </div>
HTML;

        return <<<HTML
<div class="ycookies-content-blocker"
     data-ycookies-blocker-id="{$blockerKey}"
     data-ycookies-service="{$serviceKey}"
     data-ycookies-original="{$encodedOriginal}"
     style="{$backgroundStyle}min-height:200px;display:flex;align-items:center;justify-content:center;border-radius:8px;overflow:hidden;position:relative;color:#e2e8f0;font-family:system-ui,-apple-system,sans-serif;">
    {$customCss}
    {$placeholderContent}
</div>
HTML;
    }
}
