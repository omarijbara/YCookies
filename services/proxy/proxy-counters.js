/**
 * Proxy Counters — Lightweight in-memory counters for observability.
 *
 * Tracks transform-path decisions, trust-boundary failures, and
 * origin-auth lifecycle events. Exposed via /statsz.
 *
 * No external dependencies. No persistence. Resets on restart.
 *
 * Counter groups:
 *   transform:  inject_head, inject_body, inject_flush, inject_skip_duplicate,
 *               inject_skip_preexisting, decompress_gzip, decompress_br,
 *               decompress_deflate, decompress_none
 *   trust:      ssrf_ip_blocked, ssrf_dns_blocked, ssrf_protocol_blocked,
 *               hop_by_hop_dynamic_strips
 *   auth:       origin_auth_current, origin_auth_legacy, origin_auth_none
 */

const counters = Object.create(null);

/**
 * Increment a named counter by 1 (or by `n`).
 *
 * @param {string} name - Counter name (e.g. 'inject_head')
 * @param {number} [n=1] - Amount to increment
 */
export function inc(name, n = 1) {
  counters[name] = (counters[name] || 0) + n;
}

/**
 * Get all counters as a frozen snapshot.
 * Safe to serialize directly into JSON.
 *
 * @returns {Record<string, number>}
 */
export function getCounters() {
  return { ...counters };
}

/**
 * Reset all counters. Used only in tests.
 */
export function resetCounters() {
  for (const key of Object.keys(counters)) {
    delete counters[key];
  }
}
