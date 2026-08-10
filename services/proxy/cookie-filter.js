/**
 * Cookie Filter — Phase 2 Slice 1
 *
 * Pure function: takes raw Set-Cookie headers + domain policy,
 * returns allowed cookies + blocked cookies with reasons.
 *
 * No side effects. No proxy coupling. Fixture-testable.
 */

/**
 * Well-known framework session cookies that are always essential.
 * These keep logins, CSRF protection, and basic site functionality working.
 */
const FRAMEWORK_ESSENTIAL = [
  'PHPSESSID',
  'JSESSIONID',
  'ASP.NET_SessionId',
  'XSRF-TOKEN',
  'csrf_token',
  'laravel_session',
  'laravel_token',
  '__stripe_mid',
  '__stripe_sid',
];

/**
 * Well-known framework cookies matched by prefix (wildcard patterns).
 */
const FRAMEWORK_ESSENTIAL_PREFIXES = [
  'wordpress_logged_in_',
  'wp-settings-',
  'wordpress_sec_',
  'wordpress_test_',
  'woocommerce_',
  'CONCRETE5_',
  'Drupal.visitor.',
  'SESS',              // Drupal session
  'SSESS',             // Drupal secure session
];

/**
 * Parse a raw Set-Cookie header string to extract the cookie name.
 *
 * Handles:
 * - Normal: "name=value; Path=/; HttpOnly"
 * - Value with '=': "name=val=ue; Path=/"
 * - Empty value: "name=; Path=/"
 * - Malformed (no '='): returns the whole first segment
 *
 * @param {string} raw - A single Set-Cookie header value
 * @returns {{ name: string, raw: string }}
 */
export function parseCookieName(raw) {
  if (!raw || typeof raw !== 'string') {
    return { name: '', raw: raw || '' };
  }

  const trimmed = raw.trim();

  // Split on first ';' to get name=value part
  const nameValuePart = trimmed.split(';')[0].trim();

  // Split on first '=' to get cookie name
  const eqIndex = nameValuePart.indexOf('=');
  if (eqIndex === -1) {
    // Malformed — no '=' found
    return { name: nameValuePart, raw: trimmed };
  }

  const name = nameValuePart.slice(0, eqIndex).trim();
  return { name, raw: trimmed };
}

/**
 * Check if a cookie name matches a pattern.
 * Supports exact match and prefix wildcard (pattern ending with '*').
 *
 * @param {string} cookieName
 * @param {string} pattern
 * @returns {boolean}
 */
export function matchesPattern(cookieName, pattern) {
  if (!cookieName || !pattern) return false;

  if (pattern.endsWith('*')) {
    return cookieName.startsWith(pattern.slice(0, -1));
  }

  return cookieName === pattern;
}

/**
 * Filter Set-Cookie headers according to a domain's cookie policy.
 *
 * @param {string|string[]|undefined} setCookieHeaders - Raw Set-Cookie header(s)
 * @param {object|undefined} policy - Cookie policy from config
 * @param {string} [policy.mode] - 'allowlist' (default) or 'passthrough'
 * @param {string[]} [policy.essential_patterns] - Cookie name patterns that are essential
 * @param {string[]} [policy.essential_prefixes] - Cookie name prefixes that are essential
 * @returns {{ allowed: string[], blocked: { name: string, reason: string }[] }}
 */
export function filterCookies(setCookieHeaders, policy) {
  const result = { allowed: [], blocked: [] };

  // Normalize to array
  const headers = normalizeSetCookieHeaders(setCookieHeaders);

  if (headers.length === 0) {
    return result;
  }

  // No policy or passthrough mode → allow everything
  if (!policy || policy.mode === 'passthrough') {
    result.allowed = headers;
    return result;
  }

  // Build the essential set from policy + built-in framework cookies
  const essentialNames = new Set(FRAMEWORK_ESSENTIAL);
  const essentialPrefixes = [...FRAMEWORK_ESSENTIAL_PREFIXES];

  if (policy.essential_patterns) {
    for (const pattern of policy.essential_patterns) {
      if (pattern.endsWith('*')) {
        essentialPrefixes.push(pattern.slice(0, -1));
      } else {
        essentialNames.add(pattern);
      }
    }
  }

  if (policy.essential_prefixes) {
    for (const prefix of policy.essential_prefixes) {
      essentialPrefixes.push(prefix);
    }
  }

  // NOTE: __Secure- and __Host- prefix cookies are NOT blanket-allowed.
  // Those prefixes mean stronger browser constraints (RFC 6265bis),
  // NOT that the cookie is essential or consent-exempt.
  // A marketing vendor could use __Secure-tracker=... and it would bypass consent.
  // If a domain needs these allowed, they must be listed in essential_prefixes explicitly.

  for (const raw of headers) {
    const { name } = parseCookieName(raw);

    if (!name) {
      // Malformed — pass through to avoid breaking anything
      result.allowed.push(raw);
      continue;
    }

    // Check exact match
    if (essentialNames.has(name)) {
      result.allowed.push(raw);
      continue;
    }

    // Check prefix match
    const prefixMatch = essentialPrefixes.some(prefix => name.startsWith(prefix));
    if (prefixMatch) {
      result.allowed.push(raw);
      continue;
    }

    // Not essential → block
    result.blocked.push({
      name,
      reason: 'not in essential list',
    });
  }

  return result;
}

/**
 * Normalize Set-Cookie headers to an array.
 *
 * HTTP responses can have multiple Set-Cookie headers.
 * Undici returns them as an array. Some clients return a single string.
 *
 * @param {string|string[]|undefined} headers
 * @returns {string[]}
 */
function normalizeSetCookieHeaders(headers) {
  if (!headers) return [];
  if (Array.isArray(headers)) return headers.filter(h => h && typeof h === 'string');
  if (typeof headers === 'string') return [headers];
  return [];
}
