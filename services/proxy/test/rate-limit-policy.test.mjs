/**
 * Rate Limit Policy — Pure Helper Tests
 *
 * Verifies wildcard path matching and per-domain bypass rules.
 */

import {
  DEFAULT_RATE_LIMIT_MAX_REQUESTS_PER_MINUTE,
  matchesDefaultRateLimitBypass,
  matchesWildcardPath,
  normalizeRateLimitConfig,
  shouldBypassRateLimit,
} from '../rate-limit-policy.js';

let passed = 0;
let failed = 0;

function assert(name, condition, detail = '') {
  if (condition) {
    passed++;
    console.log(`  OK ${name}${detail ? ` (${detail})` : ''}`);
  } else {
    failed++;
    console.log(`  FAIL ${name}${detail ? ` (${detail})` : ''}`);
  }
}

function test(section, fn) {
  console.log(`\n=== ${section} ===`);
  try {
    fn();
  } catch (err) {
    failed++;
    console.log(`  ERROR ${err.message}`);
  }
}

test('Normalization defaults', () => {
  const config = normalizeRateLimitConfig();
  assert('enabled defaults to true', config.enabled === true);
  assert(
    'max defaults to proxy default',
    config.maxRequestsPerMinute === DEFAULT_RATE_LIMIT_MAX_REQUESTS_PER_MINUTE,
    `max=${config.maxRequestsPerMinute}`,
  );
  assert('exclude paths default empty', config.excludePaths.length === 0);
});

test('Wildcard path matching', () => {
  assert('matches wp-admin subtree', matchesWildcardPath('/wp-admin/plugins.php', '/wp-admin*'));
  assert('matches explicit login file', matchesWildcardPath('/wp-login.php', '/wp-login.php*'));
  assert('matches generic admin pattern', matchesWildcardPath('/admin/settings', '/admin*'));
  assert('does not match unrelated path', !matchesWildcardPath('/products/rose', '/admin*'));
});

test('Default WordPress / CMS bypass (no false 429 on wp-admin)', () => {
  assert('wp-admin/edit.php bypasses', matchesDefaultRateLimitBypass('/wp-admin/edit.php') === true);
  assert('wp-admin/load-scripts.php bypasses', matchesDefaultRateLimitBypass('/wp-admin/load-scripts.php') === true);
  assert('wp-login.php bypasses', matchesDefaultRateLimitBypass('/wp-login.php') === true);
  assert('wp-cron.php bypasses', matchesDefaultRateLimitBypass('/wp-cron.php') === true);
  assert('storefront not bypassed', matchesDefaultRateLimitBypass('/shop/parfum') === false);
  assert(
    'wp-json bypasses when Referer is wp-admin',
    matchesDefaultRateLimitBypass('/wp-json/wp/v2/types', {
      headers: { referer: 'https://duftz.de/wp-admin/post.php?post=1&action=edit' },
    }) === true,
  );
  assert(
    'wp-json stays limited without admin Referer',
    matchesDefaultRateLimitBypass('/wp-json/wp/v2/posts', { headers: { referer: 'https://duftz.de/' } }) === false,
  );
  assert(
    'wp-admin path bypasses with limiter enabled',
    shouldBypassRateLimit('/wp-admin/edit.php', { enabled: true, max_requests_per_minute: 200 }) === true,
  );
  assert(
    'shop path still rate limited',
    shouldBypassRateLimit('/produkt/foo', { enabled: true, max_requests_per_minute: 200 }) === false,
  );
});

test('Bypass policy', () => {
  assert(
    'disabled limiter bypasses all paths',
    shouldBypassRateLimit('/anything', { enabled: false }) === true,
  );
  assert(
    'negative max means unlimited',
    shouldBypassRateLimit('/anything', { enabled: true, max_requests_per_minute: -1 }) === true,
  );
  assert(
    'excluded path bypasses limiter',
    shouldBypassRateLimit('/wp-admin/plugins.php', { exclude_paths: ['/wp-admin*'] }) === true,
  );
  assert(
    'non-excluded path stays limited',
    shouldBypassRateLimit('/collections', { exclude_paths: ['/wp-admin*'] }) === false,
  );
});

console.log(`\nPassed: ${passed}`);
console.log(`Failed: ${failed}`);

if (failed > 0) {
  process.exit(1);
}
