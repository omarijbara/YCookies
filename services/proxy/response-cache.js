/**
 * Response Cache — Phase 1a: Dry-Run (Observe Only)
 *
 * Computes cache eligibility on every proxied response.
 * Emits x-yc-cache and x-yc-cache-reason headers.
 * Logs policy decisions at debug level.
 *
 * Phase 1a is pure observability — NO actual caching.
 * Phase 1b (write enablement) is gated on consent logging verification.
 *
 * Bypass reason taxonomy (stable):
 *   method          — not GET/HEAD
 *   query           — URL has query string
 *   path            — matches bypass path list
 *   status          — origin returned non-200
 *   content_type    — not text/html
 *   set_cookie      — RAW upstream response has Set-Cookie (before filtering)
 *   cache_control   — origin sent private/no-store/no-cache
 *   vary_star       — origin sent Vary containing *
 *   tenant_disabled — tenant-level cache kill switch
 *   global_disabled — global cache kill switch (env)
 *   auth_hint       — request has Authorization header or session/cart cookies
 */

// ─── Configuration ──────────────────────────────────────────────────

/** Global kill switch: set CACHE_ENABLED=false to bypass all caching */
const GLOBAL_ENABLED = process.env.CACHE_ENABLED !== 'false';

/** Paths that always bypass cache (prefix match, case-insensitive) */
const BYPASS_PATHS = [
  '/wp-admin',
  '/admin',
  '/cart',
  '/checkout',
  '/account',
  '/login',
  '/api',
  '/wp-login',
  '/my-account',
  '/warenkorb',     // German: cart
  '/kasse',         // German: checkout
  '/mein-konto',    // German: account
];

/**
 * Cookie name patterns that indicate an authenticated/session state.
 * Matched against parsed cookie names (case-insensitive).
 *
 * Two match modes:
 *   { name, mode: 'exact' }  — cookie name must equal the pattern
 *   { name, mode: 'prefix' } — cookie name must start with the pattern
 */
const SESSION_COOKIE_RULES = [
  // WordPress (prefix — contains hash suffix)
  { name: 'wordpress_logged_in', mode: 'prefix' },
  { name: 'wordpress_sec', mode: 'prefix' },
  { name: 'wp-settings', mode: 'prefix' },
  // Laravel
  { name: 'laravel_session', mode: 'exact' },
  { name: 'xsrf-token', mode: 'exact' },
  // WooCommerce
  { name: 'woocommerce_cart_hash', mode: 'exact' },
  { name: 'woocommerce_items_in_cart', mode: 'exact' },
  { name: 'wp_woocommerce_session', mode: 'prefix' },
  // Shopify
  { name: '_shopify_s', mode: 'prefix' },
  // Generic (exact only — avoid false positives)
  { name: 'auth_token', mode: 'exact' },
];

import { fingerprint } from './route-fingerprint.js';

// Common tracking query parameters that should be ignored for caching
const TRACKING_PARAMS = new Set([
  'fbclid', 'gclid', 'utm_source', 'utm_medium', 'utm_campaign',
  'utm_term', 'utm_content', '_ga', 'mc_cid', 'mc_eid'
]);

// ─── Eligibility Engine ─────────────────────────────────────────────

/**
 * Evaluate whether a request + response pair is cache-eligible.
 *
 * @param {object} params
 * @param {string} params.hostname        — Request hostname (guaranteed tenant ID)
 * @param {string} params.method          — HTTP method (uppercase)
 * @param {string} params.url             — Full request URL (path + query)
 * @param {string} params.path            — URL path only (no query)
 * @param {object} params.requestHeaders  — Incoming request headers
 * @param {number} params.statusCode      — Upstream response status code
 * @param {object} params.responseHeaders — Filtered response headers
 * @param {object} params.rawUpstreamHeaders — Raw upstream response headers (before filtering)
 * @returns {{ eligible: boolean, reason: string|null, cacheKey: string }}
 */
export function evaluateRequestEligibility({
  hostname,
  method,
  url,
  path,
  requestHeaders,
  config,
}) {
  const rawPath = path || '/';
  const pathForRules = rawPath.toLowerCase();
  const tenant = hostname || config?.domain || 'unknown';
  const revision = config?.revision || 0;
  
  // Use route fingerprinting to normalize dynamic paths
  const fingerprintedPath = fingerprint(rawPath);
  
  // Strip ignored query parameters to normalize the URL for caching
  let normalizedQuery = '';
  if (url && url.includes('?')) {
    try {
      const urlObj = new URL(url, `http://${tenant}`);
      const params = urlObj.searchParams;
      
      // Remove known tracking params
      for (const param of TRACKING_PARAMS) {
        params.delete(param);
      }
      
      const remainingQ = params.toString();
      if (remainingQ) {
        normalizedQuery = `?${remainingQ}`;
      }
    } catch (e) {
      // Ignore URL parse errors
    }
  }

  const cacheKey = `${tenant}:${revision}:${method}:${fingerprintedPath}${normalizedQuery}`;

  if (!GLOBAL_ENABLED) return { eligible: false, reason: 'global_disabled', cacheKey };
  if (config?.proxy?.cache_disabled) return { eligible: false, reason: 'tenant_disabled', cacheKey };
  if (method !== 'GET' && method !== 'HEAD') return { eligible: false, reason: 'method', cacheKey };
  if (url && url.includes('?')) return { eligible: false, reason: 'query', cacheKey };

  for (const bypassPath of BYPASS_PATHS) {
    if (pathForRules.startsWith(bypassPath)) return { eligible: false, reason: 'path', cacheKey };
  }

  if (requestHeaders?.authorization) return { eligible: false, reason: 'auth_hint', cacheKey };
  if (requestHeaders?.cookie && hasSessionCookie(requestHeaders.cookie)) return { eligible: false, reason: 'auth_hint', cacheKey };

  return { eligible: true, reason: null, cacheKey };
}

/**
 * Evaluate whether a request + response pair is cache-eligible.
 *
 * @param {object} params
 * @param {string} params.hostname        — Request hostname (guaranteed tenant ID)
 * @param {string} params.method          — HTTP method (uppercase)
 * @param {string} params.url             — Full request URL (path + query)
 * @param {string} params.path            — URL path only (no query)
 * @param {object} params.requestHeaders  — Incoming request headers
 * @param {number} params.statusCode      — Upstream response status code
 * @param {object} params.responseHeaders — Filtered response headers
 * @param {object} params.rawUpstreamHeaders — Raw upstream response headers (before filtering)
 * @param {object} params.config          — Domain config from Laravel
 * @returns {{ eligible: boolean, reason: string|null, cacheKey: string }}
 */
export function evaluateEligibility(params) {
  // 1-7. Run request-side checks first
  const reqCheck = evaluateRequestEligibility(params);
  if (!reqCheck.eligible) return reqCheck;

  const { cacheKey } = reqCheck;
  const { statusCode, responseHeaders, rawUpstreamHeaders } = params;

  // ─── Response-side checks (require upstream response) ───

  // 8. Status code — only 200
  if (statusCode !== 200) {
    return { eligible: false, reason: 'status', cacheKey };
  }

  // 9. Content-Type — only text/html
  const contentType = (responseHeaders?.['content-type'] || '').toLowerCase();
  if (!contentType.includes('text/html')) {
    return { eligible: false, reason: 'content_type', cacheKey };
  }

  // 10. Set-Cookie bypass — check RAW upstream headers (before proxy filtering)
  //     We relax this to ignore generic 'wp-settings' and 'wp-settings-time' cookies
  //     that WordPress sends to anonymous users.
  if (rawUpstreamHeaders?.['set-cookie']) {
    const rawSetCookies = Array.isArray(rawUpstreamHeaders['set-cookie']) 
      ? rawUpstreamHeaders['set-cookie'] 
      : [rawUpstreamHeaders['set-cookie']];
    
    let hasSessionizingCookie = false;
    for (const cookieStr of rawSetCookies) {
      const parts = cookieStr.split(';');
      if (parts.length > 0) {
        const nameEq = parts[0].trim();
        const cookieName = nameEq.split('=')[0].trim().toLowerCase();
        
        // Ignore known anonymous WordPress cookies and generic session cookies (PHPSESSID)
        // Note: the cache engine will explicitly STRIP the 'set-cookie' header to prevent Session Fixation.
        if (!cookieName.startsWith('wp-settings') && 
            !cookieName.startsWith('ycookies_') && 
            cookieName !== 'phpsessid' && 
            cookieName !== 'session_id') {
          hasSessionizingCookie = true;
          break;
        }
      }
    }
    
    if (hasSessionizingCookie) {
      return { eligible: false, reason: 'set_cookie', cacheKey };
    }
  }

  // 11. Cache-Control hostility (unless opted-in to ignore)
  // We bypass caching if origin sent 'no-store', 'private', or 'no-cache'.
  // A tenant can override this via config.proxy.cache_ignore_cc if they use blanket cache headers safely.
  if (!params.config?.proxy?.cache_ignore_cc) {
    const cc = (responseHeaders?.['cache-control'] || '').toLowerCase();
    if (cc.includes('no-store') || cc.includes('private') || cc.includes('no-cache')) {
      return { eligible: false, reason: 'cache_control', cacheKey };
    }
  }

  // 12. Vary: * bypass — parse tokens, detect * anywhere
  const vary = responseHeaders?.['vary'] || '';
  if (vary && hasVaryStar(vary)) {
    return { eligible: false, reason: 'vary_star', cacheKey };
  }

  // All checks passed — eligible for caching
  return { eligible: true, reason: null, cacheKey };
}

// ─── Vary Parser ────────────────────────────────────────────────────

/**
 * Check if a Vary header contains the * token.
 * Splits on commas and trims tokens to handle combined values.
 *
 * @param {string} varyHeader — Raw Vary header value
 * @returns {boolean}
 */
function hasVaryStar(varyHeader) {
  const tokens = varyHeader.split(',');
  for (const token of tokens) {
    if (token.trim() === '*') {
      return true;
    }
  }
  return false;
}

// ─── Session Cookie Detector ────────────────────────────────────────

/**
 * Check if the Cookie header contains any known session/auth cookies.
 * Parses cookie names from the header and matches against rules.
 *
 * @param {string} cookieHeader — Raw Cookie header value
 * @returns {boolean}
 */
function hasSessionCookie(cookieHeader) {
  // Parse cookie names from "name1=val1; name2=val2" format
  const cookieNames = parseCookieNames(cookieHeader);

  for (const cookieName of cookieNames) {
    const lower = cookieName.toLowerCase();
    for (const rule of SESSION_COOKIE_RULES) {
      if (rule.mode === 'exact' && lower === rule.name) {
        return true;
      }
      if (rule.mode === 'prefix' && lower.startsWith(rule.name)) {
        return true;
      }
    }
  }
  return false;
}

/**
 * Parse cookie names from a raw Cookie header.
 * Returns an array of cookie names (without values).
 *
 * @param {string} cookieHeader — Raw Cookie header value
 * @returns {string[]}
 */
function parseCookieNames(cookieHeader) {
  const names = [];
  const pairs = cookieHeader.split(';');
  for (const pair of pairs) {
    const eqIndex = pair.indexOf('=');
    if (eqIndex > 0) {
      names.push(pair.substring(0, eqIndex).trim());
    } else {
      // Cookie with no value (rare but valid)
      const trimmed = pair.trim();
      if (trimmed) names.push(trimmed);
    }
  }
  return names;
}

// ─── Stats Tracking ─────────────────────────────────────────────────

const stats = {
  total: 0,
  eligible: 0,   // would-be cacheable (dry-run: not served from cache)
  bypassed: 0,   // bypassed by policy rule
  reasons: {},
  tenants: {},   // per-tenant breakdown
};

/** Max tenants to track individually (LRU-ish: oldest get evicted) */
const MAX_TRACKED_TENANTS = 50;

/**
 * Record a policy decision in stats (global + per-tenant).
 *
 * @param {{ eligible: boolean, reason: string|null, cacheKey: string }} result
 */
export function recordDecision(result) {
  stats.total++;

  // Extract tenant from cache key (format: tenant:method:path)
  const tenant = result.cacheKey?.split(':')[0] || 'unknown';

  // Ensure tenant entry exists
  if (!stats.tenants[tenant]) {
    // Evict oldest if at capacity
    const tenantKeys = Object.keys(stats.tenants);
    if (tenantKeys.length >= MAX_TRACKED_TENANTS) {
      delete stats.tenants[tenantKeys[0]];
    }
    stats.tenants[tenant] = { eligible: 0, bypassed: 0, reasons: {} };
  }
  const t = stats.tenants[tenant];

  if (result.eligible) {
    stats.eligible++;
    t.eligible++;
  } else {
    stats.bypassed++;
    t.bypassed++;
    const r = result.reason || 'unknown';
    stats.reasons[r] = (stats.reasons[r] || 0) + 1;
    t.reasons[r] = (t.reasons[r] || 0) + 1;
  }
}

/**
 * Get cache stats for /statsz endpoint.
 * Includes global totals and per-tenant breakdown.
 *
 * @returns {object}
 */
export function getCacheStats() {
  // Build per-tenant summary
  const tenantSummary = {};
  for (const [tenant, data] of Object.entries(stats.tenants)) {
    tenantSummary[tenant] = {
      eligible: data.eligible,
      bypassed: data.bypassed,
      total: data.eligible + data.bypassed,
      reasons: { ...data.reasons },
    };
  }

  return {
    mode: GLOBAL_ENABLED ? 'active' : 'disabled',
    total: stats.total,
    eligible: stats.eligible,
    bypassed: stats.bypassed,
    bypass_reasons: { ...stats.reasons },
    tenants: tenantSummary,
  };
}

// ─── Header Helpers ─────────────────────────────────────────────────

/**
 * Set cache headers on the response.
 * Phase 1b: If eligible, override hostile upstream Cache-Control headers
 * to protect the origin. Otherwise, emit bypass reason.
 *
 * @param {object} responseHeaders — Mutable response headers object
 * @param {{ eligible: boolean, reason: string|null }} result
 */
export function setCacheHeaders(responseHeaders, result) {
  if (result.eligible) {
    // Override hostile Cache-Control headers to cache at the edge
    responseHeaders['cache-control'] = 'public, max-age=300'; // 5 minutes edge cache
    responseHeaders['x-yc-cache'] = 'miss'; // Will be hit from cache layer later
  } else {
    responseHeaders['x-yc-cache'] = 'bypass';
    if (result.reason) {
      responseHeaders['x-yc-cache-reason'] = result.reason;
    } else {
      responseHeaders['x-yc-cache-reason'] = 'unknown';
    }
  }
}
