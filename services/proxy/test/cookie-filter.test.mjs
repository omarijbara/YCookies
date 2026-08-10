/**
 * Cookie Filter — Fixture-Driven Test Suite
 *
 * Tests the pure filterCookies() function against 15+ edge cases.
 * This must pass BEFORE the filter is integrated into the proxy.
 */

import { filterCookies, parseCookieName, matchesPattern } from '../cookie-filter.js';

let passed = 0;
let failed = 0;

function assert(name, condition, detail = '') {
  if (condition) {
    passed++;
    console.log(`  ✅ ${name}${detail ? ` (${detail})` : ''}`);
  } else {
    failed++;
    console.log(`  ❌ ${name}: ${detail}`);
  }
}

function test(section, fn) {
  console.log(`\n=== ${section} ===`);
  try {
    fn();
  } catch (err) {
    failed++;
    console.log(`  ❌ ERROR: ${err.message}\n${err.stack}`);
  }
}

const ALLOWLIST_POLICY = {
  mode: 'allowlist',
  essential_patterns: ['my_custom_session', 'site_prefs', 'cart_*'],
  essential_prefixes: ['shop_'],
};

// ─── Parser Tests ───

test('Parser: Normal cookie', () => {
  const r = parseCookieName('session_id=abc123; Path=/; HttpOnly');
  assert('Name is session_id', r.name === 'session_id', `name=${r.name}`);
});

test('Parser: Value with equals sign', () => {
  const r = parseCookieName('token=eyJ0eX=A.payload=data; Secure');
  assert('Name is token', r.name === 'token', `name=${r.name}`);
});

test('Parser: Empty value', () => {
  const r = parseCookieName('deleted=; Path=/; Max-Age=0');
  assert('Name is deleted', r.name === 'deleted', `name=${r.name}`);
});

test('Parser: No equals sign (malformed)', () => {
  const r = parseCookieName('malformed_cookie');
  assert('Name is full value', r.name === 'malformed_cookie', `name=${r.name}`);
});

test('Parser: Empty string', () => {
  const r = parseCookieName('');
  assert('Name is empty', r.name === '', `name="${r.name}"`);
});

test('Parser: Null input', () => {
  const r = parseCookieName(null);
  assert('Name is empty', r.name === '', `name="${r.name}"`);
});

test('Parser: Whitespace around name', () => {
  const r = parseCookieName('  session  =abc; Path=/');
  assert('Name is trimmed', r.name === 'session', `name="${r.name}"`);
});

// ─── Pattern Matching Tests ───

test('Pattern: Exact match', () => {
  assert('Matches', matchesPattern('PHPSESSID', 'PHPSESSID'));
  assert('No match', !matchesPattern('PHPSESSID', 'phpsessid'));
  assert('No match different name', !matchesPattern('_ga', 'PHPSESSID'));
});

test('Pattern: Wildcard match', () => {
  assert('Prefix match', matchesPattern('cart_items', 'cart_*'));
  assert('Exact prefix', matchesPattern('cart_', 'cart_*'));
  assert('No match', !matchesPattern('shopping_cart', 'cart_*'));
});

// ─── Filter: No Policy (passthrough) ───

test('Filter: No policy → all allowed', () => {
  const cookies = [
    '_ga=GA1.1.123; Path=/; SameSite=Lax',
    'tracker=abc; Path=/',
  ];
  const result = filterCookies(cookies, undefined);
  assert('All allowed', result.allowed.length === 2, `allowed=${result.allowed.length}`);
  assert('None blocked', result.blocked.length === 0, `blocked=${result.blocked.length}`);
});

test('Filter: Passthrough mode → all allowed', () => {
  const cookies = ['_ga=GA1.1.123; Path=/'];
  const result = filterCookies(cookies, { mode: 'passthrough' });
  assert('All allowed', result.allowed.length === 1);
  assert('None blocked', result.blocked.length === 0);
});

// ─── Filter: Single Essential Cookie ───

test('Filter: PHPSESSID (framework essential)', () => {
  const cookies = ['PHPSESSID=abc123def; Path=/; HttpOnly; Secure'];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('Allowed', result.allowed.length === 1, `allowed=${result.allowed.length}`);
  assert('None blocked', result.blocked.length === 0);
  assert('Raw header preserved', result.allowed[0].includes('HttpOnly'));
});

// ─── Filter: Single Analytics Cookie ───

test('Filter: _ga (analytics → blocked)', () => {
  const cookies = ['_ga=GA1.1.123456789; Path=/; SameSite=Lax; Max-Age=63072000'];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('Blocked', result.blocked.length === 1, `blocked=${result.blocked.length}`);
  assert('None allowed', result.allowed.length === 0);
  assert('Blocked name is _ga', result.blocked[0].name === '_ga');
  assert('Has reason', result.blocked[0].reason.length > 0);
});

// ─── Filter: Mixed Cookies ───

test('Filter: Mixed essential + analytics', () => {
  const cookies = [
    'PHPSESSID=abc; Path=/; HttpOnly',
    '_ga=GA1.1.123; Path=/; SameSite=Lax',
    'XSRF-TOKEN=xyz; Path=/; Secure',
    '_fbp=fb.1.123; Path=/',
    'my_custom_session=val; Path=/; HttpOnly',
    '_gid=GA1.1.456; Path=/',
  ];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('3 allowed', result.allowed.length === 3, `allowed=${result.allowed.length}`);
  assert('3 blocked', result.blocked.length === 3, `blocked=${result.blocked.length}`);

  const allowedNames = result.allowed.map(c => parseCookieName(c).name);
  assert('PHPSESSID allowed', allowedNames.includes('PHPSESSID'));
  assert('XSRF-TOKEN allowed', allowedNames.includes('XSRF-TOKEN'));
  assert('my_custom_session allowed', allowedNames.includes('my_custom_session'));

  const blockedNames = result.blocked.map(b => b.name);
  assert('_ga blocked', blockedNames.includes('_ga'));
  assert('_fbp blocked', blockedNames.includes('_fbp'));
  assert('_gid blocked', blockedNames.includes('_gid'));
});

// ─── Filter: __Secure- and __Host- Prefix Cookies ───

test('Filter: __Secure- prefix (NOT blanket-allowed)', () => {
  // Security prefix does NOT mean consent-exempt.
  // A vendor could use __Secure-tracker=... for marketing.
  const cookies = ['__Secure-tracker=token123; Secure; Path=/; HttpOnly'];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('Blocked', result.blocked.length === 1, `blocked=${result.blocked.length}`);
  assert('None allowed', result.allowed.length === 0);
});

test('Filter: __Host- prefix (NOT blanket-allowed)', () => {
  const cookies = ['__Host-analytics=abc; Secure; Path=/'];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('Blocked', result.blocked.length === 1, `blocked=${result.blocked.length}`);
  assert('None allowed', result.allowed.length === 0);
});

test('Filter: __Secure- allowed when in policy essential_prefixes', () => {
  const policyWithSecure = {
    mode: 'allowlist',
    essential_patterns: [],
    essential_prefixes: ['__Secure-'],
  };
  const cookies = ['__Secure-session=abc; Secure; Path=/; HttpOnly'];
  const result = filterCookies(cookies, policyWithSecure);
  assert('Allowed via policy', result.allowed.length === 1);
  assert('None blocked', result.blocked.length === 0);
});

// ─── Filter: WordPress Cookies ───

test('Filter: WordPress session cookies (prefix match)', () => {
  const cookies = [
    'wordpress_logged_in_abc123=admin%7C123; Path=/wp-admin; HttpOnly',
    'wordpress_sec_abc123=admin%7C456; Path=/wp-admin; HttpOnly; Secure',
    'wp-settings-1=editor%3Dtinymce; Path=/; Expires=Thu, 01 Jan 2099 00:00:00 GMT',
  ];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('All 3 allowed', result.allowed.length === 3, `allowed=${result.allowed.length}`);
  assert('None blocked', result.blocked.length === 0);
});

// ─── Filter: Custom Policy Prefixes ───

test('Filter: Custom policy prefix (shop_)', () => {
  const cookies = [
    'shop_cart_id=abc; Path=/',
    'shop_session=xyz; Path=/; HttpOnly',
    'marketing_id=123; Path=/',
  ];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('2 allowed (shop_*)', result.allowed.length === 2, `allowed=${result.allowed.length}`);
  assert('1 blocked (marketing_id)', result.blocked.length === 1);
});

// ─── Filter: Custom Policy Wildcard Pattern ───

test('Filter: Custom wildcard pattern (cart_*)', () => {
  const cookies = ['cart_items=3; Path=/; HttpOnly'];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('Allowed', result.allowed.length === 1);
  assert('None blocked', result.blocked.length === 0);
});

// ─── Filter: Cookie Attributes Preserved ───

test('Filter: Attributes preserved on allowed cookies', () => {
  const raw = 'PHPSESSID=abc123; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=3600';
  const result = filterCookies([raw], ALLOWLIST_POLICY);
  assert('Raw is identical', result.allowed[0] === raw);
});

// ─── Filter: Empty and Malformed Input ───

test('Filter: Empty Set-Cookie header', () => {
  const result = filterCookies('', ALLOWLIST_POLICY);
  assert('No allowed', result.allowed.length === 0);
  assert('No blocked', result.blocked.length === 0);
});

test('Filter: Undefined input', () => {
  const result = filterCookies(undefined, ALLOWLIST_POLICY);
  assert('No allowed', result.allowed.length === 0);
  assert('No blocked', result.blocked.length === 0);
});

test('Filter: Null input', () => {
  const result = filterCookies(null, ALLOWLIST_POLICY);
  assert('No allowed', result.allowed.length === 0);
  assert('No blocked', result.blocked.length === 0);
});

test('Filter: Single string input (not array)', () => {
  const result = filterCookies('PHPSESSID=abc; Path=/', ALLOWLIST_POLICY);
  assert('Allowed', result.allowed.length === 1);
  assert('None blocked', result.blocked.length === 0);
});

test('Filter: Array with null/empty entries', () => {
  const cookies = [null, '', 'PHPSESSID=abc; Path=/', undefined, '_ga=GA1; Path=/'];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('1 allowed (PHPSESSID)', result.allowed.length === 1, `allowed=${result.allowed.length}`);
  assert('1 blocked (_ga)', result.blocked.length === 1, `blocked=${result.blocked.length}`);
});

// ─── Filter: Redirect with Cookies (3xx still filters) ───

test('Filter: Cookies on redirect response (still filtered)', () => {
  // The filter doesn't know about status codes — it just filters headers.
  // This test proves the function works regardless of response status.
  const cookies = [
    'session=abc; Path=/; HttpOnly',  // not in essential list
    'PHPSESSID=def; Path=/; HttpOnly',  // essential
  ];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('PHPSESSID allowed', result.allowed.length === 1);
  assert('session blocked', result.blocked.length === 1);
  assert('Blocked is "session"', result.blocked[0].name === 'session');
});

// ─── Filter: Same Name, Different Attributes ───

test('Filter: Same cookie name set twice with different attributes', () => {
  const cookies = [
    'tracker=v1; Path=/; Expires=Thu, 01 Jan 2099 00:00:00 GMT',
    'tracker=v2; Path=/sub; Secure; HttpOnly',
  ];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('Both blocked', result.blocked.length === 2, `blocked=${result.blocked.length}`);
  assert('Both named tracker', result.blocked.every(b => b.name === 'tracker'));
});

// ─── Filter: Laravel Session Cookies ───

test('Filter: Laravel framework cookies', () => {
  const cookies = [
    'laravel_session=eyJpIjoiM2M0; Path=/; HttpOnly; Secure; SameSite=Lax',
    'laravel_token=abc; Path=/; HttpOnly',
    'XSRF-TOKEN=eyJpdi; Path=/; Secure; SameSite=Lax',
  ];
  const result = filterCookies(cookies, ALLOWLIST_POLICY);
  assert('All 3 allowed', result.allowed.length === 3, `allowed=${result.allowed.length}`);
  assert('None blocked', result.blocked.length === 0);
});

// ─── Summary ───

console.log('\n' + '='.repeat(50));
console.log(`COOKIE FILTER TESTS: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50));

process.exit(failed > 0 ? 1 : 0);
