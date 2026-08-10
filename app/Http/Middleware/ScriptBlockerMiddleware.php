<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\ScriptBlocker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Script Blocker Runtime Middleware
 *
 * Intercepts HTML responses and mutates <script> tags so they are not
 * executed by the browser until the user grants consent.
 *
 * Mirrors Borlabs Cookie's ScriptBlockerManager:
 * - Handle-based blocking: matches WP-style script handles or src attributes
 * - Phrase-based blocking: matches any substring in the <script> tag
 * - Mutation: changes type="text/javascript" to type="text/template"
 *   and adds data-ycookies-blocker-id + data-ycookies-service-key for
 *   the frontend JS to pick up on consent.
 *
 * Self-exclusion: never blocks scripts containing "ycookies" to avoid
 * blocking the consent manager itself.
 */
class ScriptBlockerMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only process HTML responses (not API, JSON, redirects, etc.)
        if (!$this->isHtmlResponse($response)) {
            return $response;
        }

        // Get the domain from the request (matched by hostname)
        $domain = $this->resolveDomain($request);
        if (!$domain) {
            return $response;
        }

        // Get active script blockers for this domain (cached 5 min)
        $blockers = $this->getScriptBlockers($domain);
        if ($blockers->isEmpty()) {
            return $response;
        }

        // Intercept and modify the HTML content
        $html = $response->getContent();
        $modified = $this->blockScripts($html, $blockers);

        if ($modified !== $html) {
            $response->setContent($modified);
        }

        return $response;
    }

    /**
     * Check if the response is an HTML document.
     */
    protected function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html')
            && $response->getStatusCode() === 200
            && !empty($response->getContent());
    }

    /**
     * Resolve the Domain model from the request hostname.
     */
    protected function resolveDomain(Request $request): ?Domain
    {
        $host = $request->getHost();

        return Cache::remember("domain_by_host:{$host}", 300, function () use ($host) {
            return Domain::where('name', $host)
                ->where('is_active', true)
                ->first();
        });
    }

    /**
     * Get active ScriptBlockers for a domain (cached).
     */
    protected function getScriptBlockers(Domain $domain)
    {
        return Cache::remember("script_blockers:{$domain->id}", 300, function () use ($domain) {
            return ScriptBlocker::where('domain_id', $domain->id)
                ->where('is_active', true)
                ->with('service')
                ->get();
        });
    }

    /**
     * Process the HTML and block matching scripts.
     *
     * Uses regex to find all <script> tags, then checks each against
     * the configured handle and phrase patterns.
     */
    protected function blockScripts(string $html, $blockers): string
    {
        // Match all <script ...> tags (opening tags only, we preserve closing tags)
        // This regex captures the full opening tag including attributes
        return preg_replace_callback(
            '/<script\b([^>]*)>/i',
            function ($match) use ($blockers) {
                $fullTag = $match[0];
                $attributes = $match[1];

                // Self-exclusion: never block YCookies' own scripts
                if (stripos($attributes, 'ycookies') !== false
                    || stripos($attributes, 'data-ycookies') !== false) {
                    return $fullTag;
                }

                // Check each blocker
                foreach ($blockers as $blocker) {
                    if ($this->matchesBlocker($attributes, $blocker)) {
                        return $this->mutateScriptTag($fullTag, $attributes, $blocker);
                    }
                }

                return $fullTag;
            },
            $html
        ) ?? $html;
    }

    /**
     * Check if a script tag's attributes match a ScriptBlocker's rules.
     */
    protected function matchesBlocker(string $attributes, ScriptBlocker $blocker): bool
    {
        // 1. Handle-based matching (check src attribute against handle list)
        if (!empty($blocker->handles)) {
            foreach ($blocker->handles as $handle) {
                $handle = trim($handle);
                if (empty($handle)) continue;

                // Extract src from attributes
                if (preg_match('/src\s*=\s*["\']([^"\']*)["\']/', $attributes, $srcMatch)) {
                    if (stripos($srcMatch[1], $handle) !== false) {
                        return true;
                    }
                }

                // Also check id/data-handle attributes
                if (preg_match('/(?:id|data-handle)\s*=\s*["\']([^"\']*)["\']/', $attributes, $idMatch)) {
                    if (stripos($idMatch[1], $handle) !== false) {
                        return true;
                    }
                }
            }
        }

        // 2. Phrase-based matching (check for any substring in the tag)
        if (!empty($blocker->phrases)) {
            foreach ($blocker->phrases as $phrase) {
                $phrase = trim($phrase);
                if (empty($phrase)) continue;

                if (stripos($attributes, $phrase) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Mutate a <script> tag to prevent execution.
     *
     * Changes type to text/template and adds data attributes for
     * the frontend unblocking JS to identify and restore.
     */
    protected function mutateScriptTag(string $fullTag, string $attributes, ScriptBlocker $blocker): string
    {
        $serviceKey = $blocker->service ? $blocker->service->key : '';
        $blockerKey = $blocker->key;

        // Remove existing type attribute if present
        $modifiedTag = preg_replace('/\btype\s*=\s*["\'][^"\']*["\']/', '', $fullTag);

        // Add our blocking attributes
        $insertAttrs = sprintf(
            ' type="text/template" data-ycookies-blocked="true" data-ycookies-blocker-id="%s" data-ycookies-service="%s"',
            e($blockerKey),
            e($serviceKey)
        );

        // Insert before the closing >
        $modifiedTag = preg_replace('/>$/', $insertAttrs . '>', $modifiedTag);

        return $modifiedTag;
    }
}
