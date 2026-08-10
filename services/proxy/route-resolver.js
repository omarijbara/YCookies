/**
 * Route Resolver — Node.js Implementation
 *
 * MUST produce identical route resolution results as the PHP implementation
 * (PathNormalizer::normalize + PathNormalizer::matchRoute).
 *
 * Normalization order (locked):
 *   1. Parse URL → take pathname only
 *   2. Ignore query string and fragment
 *   3. Preserve original case
 *   4. Normalize empty path to '/'
 *   5. Remove trailing slash unless path is exactly '/'
 *   6. Strip leading locale prefix if defined
 *   7. Match route index: exact > longest wildcard > longest globstar > priority > lexical
 */

const MATCH_PRECEDENCE = {
  exact: 4,
  wildcard: 3,
  globstar: 2,
  default: 1,
};

/**
 * Normalize a URL path.
 *
 * @param {string} url Full URL or path
 * @param {string[]} localePrefixes Optional locale prefixes
 * @returns {{ path: string, locale: string|null }}
 */
export function normalizePath(url, localePrefixes = []) {
  // Step 1: Parse URL, take pathname only
  let path;
  try {
    const parsed = new URL(url, 'http://placeholder');
    path = parsed.pathname;
  } catch {
    path = url.split('?')[0].split('#')[0];
  }

  // Step 4: Normalize empty to '/'
  if (!path) path = '/';

  // Step 5: Remove trailing slash unless '/'
  if (path !== '/' && path.endsWith('/')) {
    path = path.replace(/\/+$/, '');
  }
  if (!path) path = '/';

  // Step 6: Strip locale prefix
  let locale = null;
  if (localePrefixes.length > 0 && path !== '/') {
    const segments = path.slice(1).split('/', 2);
    const firstSegment = segments[0];
    const firstLower = firstSegment.toLowerCase();

    for (const prefix of localePrefixes) {
      if (prefix.toLowerCase() === firstLower) {
        locale = prefix;
        const localeSegment = '/' + firstSegment;
        if (path.toLowerCase() === localeSegment.toLowerCase()) {
          path = '/';
        } else if (path.toLowerCase().startsWith(localeSegment.toLowerCase() + '/')) {
          path = path.slice(localeSegment.length);
          if (path !== '/' && path.endsWith('/')) {
            path = path.replace(/\/+$/, '');
          }
          if (!path) path = '/';
        }
        break;
      }
    }
  }

  return { path, locale };
}

/**
 * Match a normalized path against a route index.
 *
 * @param {string} normalizedPath
 * @param {{ routes: Array }} routeIndex
 * @returns {{ overlay_id: string, match_type: string, matched_pattern: string }|null}
 */
export function matchRoute(normalizedPath, routeIndex) {
  const routes = routeIndex?.routes || [];
  if (routes.length === 0) return null;

  const candidates = [];

  for (const entry of routes) {
    const pattern = entry.pattern || '';
    const matchType = entry.match_type || 'default';
    const overlayId = entry.overlay_id || '';
    const priority = entry.priority || 0;

    let matched = false;
    switch (matchType) {
      case 'exact':
        matched = normalizedPath === pattern;
        break;
      case 'wildcard':
        matched = matchWildcard(normalizedPath, pattern);
        break;
      case 'globstar':
        matched = matchGlobstar(normalizedPath, pattern);
        break;
    }

    if (matched) {
      candidates.push({
        overlay_id: overlayId,
        match_type: matchType,
        pattern,
        priority,
        specificity: pattern.length,
      });
    }
  }

  if (candidates.length === 0) return null;

  // Sort by precedence (same rules as PHP)
  candidates.sort((a, b) => {
    // 1. Match type precedence (higher wins)
    const precA = MATCH_PRECEDENCE[a.match_type] || 0;
    const precB = MATCH_PRECEDENCE[b.match_type] || 0;
    if (precA !== precB) return precB - precA;

    // 2. Specificity (longer pattern wins)
    if (a.specificity !== b.specificity) return b.specificity - a.specificity;

    // 3. Priority (higher wins)
    if (a.priority !== b.priority) return b.priority - a.priority;

    // 4. Lexical overlay_id (ascending)
    return a.overlay_id < b.overlay_id ? -1 : a.overlay_id > b.overlay_id ? 1 : 0;
  });

  const best = candidates[0];
  return {
    overlay_id: best.overlay_id,
    match_type: best.match_type,
    matched_pattern: best.pattern,
  };
}

/**
 * Wildcard (*) match — one path segment.
 */
function matchWildcard(path, pattern) {
  const starPos = pattern.indexOf('*');
  if (starPos === -1) return path === pattern;

  const prefix = pattern.slice(0, starPos);
  const suffix = pattern.slice(starPos + 1);

  if (!path.startsWith(prefix)) return false;
  if (suffix && !path.endsWith(suffix)) return false;

  let matchedPart = path.slice(prefix.length);
  if (suffix) matchedPart = matchedPart.slice(0, -suffix.length);

  return matchedPart.length > 0 && !matchedPart.includes('/');
}

/**
 * Globstar (**) match — zero or more segments.
 */
function matchGlobstar(path, pattern) {
  const globPos = pattern.indexOf('**');
  if (globPos === -1) return path === pattern;

  const prefix = pattern.slice(0, globPos);
  const suffix = pattern.slice(globPos + 2);

  if (!path.startsWith(prefix)) return false;
  if (suffix && !path.endsWith(suffix)) return false;

  return true;
}

export default { normalizePath, matchRoute };
