/**
 * Route Fingerprinter — Normalizes URL paths into stable route patterns.
 *
 * Replaces dynamic segments (numeric IDs, UUIDs, hashes, tokens)
 * with typed placeholders while preserving URL structure.
 *
 * Examples:
 *   /checkout/12345            → /checkout/:id
 *   /api/orders/9f1c.../items  → /api/orders/:token/items
 *   /search?q=shoes&page=2    → /search
 *   /users/42/posts/abc123    → /users/:id/posts/:token
 *   /                          → /
 *
 * Privacy: raw paths never stored in aggregates.
 */

// UUID: 8-4-4-4-12 hex
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// Hex hash: 16+ hex chars (MD5=32, SHA1=40, SHA256=64, etc.)
const HEX_HASH_RE = /^[0-9a-f]{16,}$/i;

// Pure numeric: 1+ digits
const NUMERIC_RE = /^\d+$/;

// Base64-ish token: 16+ alphanumeric with mixed case
const TOKEN_RE = /^[A-Za-z0-9_-]{16,}$/;

// Max segments to keep — anything beyond is collapsed
const MAX_SEGMENTS = 8;

const fingerprintCache = new Map();
const MAX_FINGERPRINTS = 1000;

/**
 * Fingerprint a single path segment.
 *
 * @param {string} segment - One URL path segment (between slashes)
 * @returns {string} - Normalized segment or placeholder
 */
function classifySegment(segment) {
  if (!segment) return segment;

  // UUID → :uuid
  if (UUID_RE.test(segment)) return ':uuid';

  // Pure numeric → :id
  if (NUMERIC_RE.test(segment)) return ':id';

  // Hex hash (MD5, SHA1, SHA256) → :hash
  if (HEX_HASH_RE.test(segment)) return ':hash';

  // Long base64/token → :token
  if (TOKEN_RE.test(segment) && /[A-Z]/.test(segment) && /[a-z]/.test(segment)) {
    return ':token';
  }

  // Keep meaningful segment as-is
  return segment;
}

/**
 * Fingerprint a URL path into a stable route pattern.
 *
 * @param {string} rawPath - Raw URL path (may include query string)
 * @returns {string} - Normalized route pattern
 */
export function fingerprint(rawPath) {
  if (!rawPath) return '/';

  if (fingerprintCache.has(rawPath)) {
    const cached = fingerprintCache.get(rawPath);
    fingerprintCache.delete(rawPath);
    fingerprintCache.set(rawPath, cached);
    return cached;
  }

  // Strip query string entirely
  let path = rawPath.split('?')[0];

  // Normalize: collapse double slashes, remove trailing slash (keep /)
  path = path.replace(/\/+/g, '/');
  if (path.length > 1 && path.endsWith('/')) {
    path = path.slice(0, -1);
  }

  // Root path
  if (path === '/' || path === '') return '/';

  // Split into segments
  const segments = path.split('/').filter(Boolean);

  // Cap segment count
  const capped = segments.length > MAX_SEGMENTS
    ? [...segments.slice(0, MAX_SEGMENTS), '*']
    : segments;

  // Classify each segment
  const fingerprinted = capped.map(s => s === '*' ? '*' : classifySegment(s));

  const result = '/' + fingerprinted.join('/');

  if (fingerprintCache.size >= MAX_FINGERPRINTS) {
    const oldestKey = fingerprintCache.keys().next().value;
    fingerprintCache.delete(oldestKey);
  }
  fingerprintCache.set(rawPath, result);

  return result;
}
