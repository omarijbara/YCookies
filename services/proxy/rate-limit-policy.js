/**
 * Rate Limit Policy Helpers
 *
 * Pure helpers for resolving per-domain proxy rate-limit settings.
 * Used by the Fastify proxy after the domain config has been loaded.
 */

export const DEFAULT_RATE_LIMIT_MAX_REQUESTS_PER_MINUTE = 200;

/**
 * Paths that always bypass per-IP rate limiting (CMS backends issue many
 * parallel requests: scripts, heartbeat, REST from the block editor, etc.).
 * Does not disable origin security — only the proxy's coarse IP bucket.
 *
 * @param {string} pathname
 * @param {object|null} request  Fastify request (optional); used for Referer on /wp-json
 * @returns {boolean}
 */
export function matchesDefaultRateLimitBypass(pathname, request = null) {
  const p = (pathname || '/').toLowerCase();

  if (p.startsWith('/wp-admin')) {
    return true;
  }
  if (p === '/wp-login.php') {
    return true;
  }
  if (p === '/wp-signup.php') {
    return true;
  }
  if (p === '/wp-cron.php') {
    return true;
  }

  // Gutenberg / Site Editor: dozens of /wp-json/* calls while Referer is wp-admin
  if (p.startsWith('/wp-json/') && request?.headers) {
    const ref = String(
      request.headers.referer ?? request.headers.Referer ?? '',
    ).toLowerCase();
    if (ref.includes('/wp-admin')) {
      return true;
    }
  }

  return false;
}

/**
 * Normalize a raw Laravel rate_limit config block into a predictable shape.
 *
 * @param {object|null|undefined} raw
 * @returns {{enabled: boolean, maxRequestsPerMinute: number, excludePaths: string[]}}
 */
export function normalizeRateLimitConfig(raw) {
  const enabled = raw?.enabled !== false;

  let maxRequestsPerMinute = Number(
    raw?.max_requests_per_minute ?? DEFAULT_RATE_LIMIT_MAX_REQUESTS_PER_MINUTE,
  );

  if (!Number.isFinite(maxRequestsPerMinute)) {
    maxRequestsPerMinute = DEFAULT_RATE_LIMIT_MAX_REQUESTS_PER_MINUTE;
  }

  if (maxRequestsPerMinute !== -1) {
    maxRequestsPerMinute = Math.max(1, Math.round(maxRequestsPerMinute));
  }

  const excludePaths = normalizeExcludePaths(raw?.exclude_paths);

  return {
    enabled,
    maxRequestsPerMinute,
    excludePaths,
  };
}

/**
 * Normalize wildcard patterns from config/admin input.
 *
 * @param {unknown} rawPaths
 * @returns {string[]}
 */
export function normalizeExcludePaths(rawPaths) {
  if (!Array.isArray(rawPaths)) {
    return [];
  }

  const normalized = [];

  for (const rawPath of rawPaths) {
    let path = String(rawPath ?? '').trim();
    if (!path || path.startsWith('#')) {
      continue;
    }

    if (!path.startsWith('/') && !path.startsWith('*')) {
      path = `/${path.replace(/^\/+/, '')}`;
    }

    normalized.push(path);
  }

  return [...new Set(normalized)];
}

/**
 * Test whether a pathname matches a wildcard pattern.
 * Supports '*' anywhere in the pattern.
 *
 * @param {string} pathname
 * @param {string} pattern
 * @returns {boolean}
 */
export function matchesWildcardPath(pathname, pattern) {
  const normalizedPath = pathname || '/';
  const normalizedPattern = String(pattern || '').trim();

  if (!normalizedPattern) {
    return false;
  }

  const escaped = normalizedPattern
    .split('*')
    .map((part) => part.replace(/[|\\{}()[\]^$+?.]/g, '\\$&'))
    .join('.*');

  const regex = new RegExp(`^${escaped}$`, 'i');
  return regex.test(normalizedPath);
}

/**
 * Check whether a pathname should bypass rate limiting.
 *
 * @param {string} pathname
 * @param {object|null|undefined} rawConfig
 * @param {object|null} request  Fastify request (optional); for Referer-based /wp-json bypass
 * @returns {boolean}
 */
export function shouldBypassRateLimit(pathname, rawConfig, request = null) {
  const config = normalizeRateLimitConfig(rawConfig);

  if (!config.enabled || config.maxRequestsPerMinute === -1) {
    return true;
  }

  if (matchesDefaultRateLimitBypass(pathname, request)) {
    return true;
  }

  return config.excludePaths.some((pattern) => matchesWildcardPath(pathname, pattern));
}
