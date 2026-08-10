/**
 * Cross-Language Parity Tests — Manifest Architecture
 *
 * Verifies that the Node.js implementations produce IDENTICAL results
 * to the PHP implementations, using the same golden fixtures.
 *
 * Covers:
 *   1. Canonical JSON serialization (key sorting, no pretty-print)
 *   2. SHA-256 hashing of canonical artifacts
 *   3. Path normalization (trailing slashes, locale stripping, query/fragment)
 *   4. Route matching (exact > wildcard > globstar > priority > lexical)
 *   5. Overlay merge (list replacement, deep merge, null removal, field protection)
 *
 * IMPORTANT: If any test fails, the PHP and Node implementations have diverged.
 * Fix the divergence — do NOT disable the test.
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { canonicalize, hashArtifact, sortKeysRecursive } from '../manifest-verifier.js';
import { normalizePath, matchRoute } from '../route-resolver.js';
import { merge } from '../overlay-merger.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const FIXTURE_DIR = join(__dirname, '..', '..', '..', 'tests', 'Fixtures', 'Runtime');

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

function assertEq(name, actual, expected, detail = '') {
  const match = JSON.stringify(actual) === JSON.stringify(expected);
  assert(name, match, match ? detail : `expected=${JSON.stringify(expected)}, actual=${JSON.stringify(actual)}`);
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

// ── Load golden fixtures ────────────────────────────────────

const baseArtifact = JSON.parse(readFileSync(join(FIXTURE_DIR, 'base_artifact_v1.json'), 'utf8'));
const routeIndex = JSON.parse(readFileSync(join(FIXTURE_DIR, 'route_index_v1.json'), 'utf8'));
const overlaySimple = JSON.parse(readFileSync(join(FIXTURE_DIR, 'overlay_simple_v1.json'), 'utf8'));
const manifest = JSON.parse(readFileSync(join(FIXTURE_DIR, 'manifest_v1.json'), 'utf8'));

// ═══ 1. Canonicalization ═══════════════════════════════════

test('Canonicalization: Key sorting', () => {
  const result = JSON.parse(canonicalize({ z: 1, a: 2, m: 3 }));
  const keys = Object.keys(result);
  assertEq('top-level keys sorted', keys, ['a', 'm', 'z']);
});

test('Canonicalization: Nested key sorting', () => {
  const result = JSON.parse(canonicalize({ z: { b: 1, a: 2 }, a: 'hello' }));
  assertEq('top-level keys sorted', Object.keys(result), ['a', 'z']);
  assertEq('nested keys sorted', Object.keys(result.z), ['a', 'b']);
});

test('Canonicalization: Array order preserved', () => {
  const result = JSON.parse(canonicalize({ items: ['cherry', 'apple', 'banana'] }));
  assertEq('array order preserved', result.items, ['cherry', 'apple', 'banana']);
});

test('Canonicalization: No whitespace', () => {
  const json = canonicalize({ a: 1 });
  assert('no newlines', !json.includes('\n'));
  assert('no double spaces', !json.includes('  '));
  assertEq('compact form', json, '{"a":1}');
});

test('Canonicalization: Unescaped slashes', () => {
  const json = canonicalize({ url: 'https://example.com/path' });
  assert('slashes unescaped', json.includes('https://example.com/path'));
  assert('no escaped slashes', !json.includes('\\/'));
});

test('Canonicalization: Deterministic', () => {
  const data = { z: { b: [3, 2, 1], a: 'hello' }, a: true };
  assertEq('same output twice', canonicalize(data), canonicalize(data));
});

test('Canonicalization: Different key order, same output', () => {
  const a = canonicalize({ a: 1, b: 2 });
  const b = canonicalize({ b: 2, a: 1 });
  assertEq('key order independent', a, b);
});

// ═══ 2. Hashing ════════════════════════════════════════════

test('Hashing: SHA-256 format', () => {
  const hash = hashArtifact({ test: true });
  assert('64 hex chars', /^[0-9a-f]{64}$/.test(hash), `hash=${hash}`);
});

test('Hashing: Deterministic', () => {
  const h1 = hashArtifact({ a: 1, b: 2 });
  const h2 = hashArtifact({ a: 1, b: 2 });
  assertEq('same hash', h1, h2);
});

test('Hashing: Key order independent', () => {
  const h1 = hashArtifact({ a: 1, b: 2 });
  const h2 = hashArtifact({ b: 2, a: 1 });
  assertEq('key order independent hash', h1, h2);
});

test('Hashing: Different data, different hash', () => {
  const h1 = hashArtifact({ a: 1 });
  const h2 = hashArtifact({ a: 2 });
  assert('different hashes', h1 !== h2, `h1=${h1}, h2=${h2}`);
});

test('Hashing: Golden fixture base artifact', () => {
  const hash = hashArtifact(baseArtifact);
  assert('fixture hash is valid hex', /^[0-9a-f]{64}$/.test(hash), `hash=${hash}`);
  // This hash value is verified against PHP in CrossLanguageSignatureTest
  console.log(`    ↳ Base artifact hash: ${hash} (verify against PHP)`);
});

// ═══ 3. Path Normalization ═════════════════════════════════

test('Path: Empty to root', () => {
  assertEq('empty', normalizePath('').path, '/');
});

test('Path: Root stays root', () => {
  assertEq('root', normalizePath('/').path, '/');
});

test('Path: Simple preserved', () => {
  assertEq('simple', normalizePath('/about').path, '/about');
});

test('Path: Trailing slash removed', () => {
  assertEq('trailing slash', normalizePath('/about/').path, '/about');
});

test('Path: Root trailing slash kept', () => {
  assertEq('root slash', normalizePath('/').path, '/');
});

test('Path: Query string ignored', () => {
  assertEq('query', normalizePath('/search?q=test').path, '/search');
});

test('Path: Fragment ignored', () => {
  assertEq('fragment', normalizePath('/page#section').path, '/page');
});

test('Path: Full URL extracts path', () => {
  assertEq('full url', normalizePath('https://example.com/about?lang=en').path, '/about');
});

test('Path: Case preserved', () => {
  assertEq('case', normalizePath('/About-Us').path, '/About-Us');
});

test('Path: No locale by default', () => {
  const result = normalizePath('/de/about');
  assertEq('no locale stripping', result.path, '/de/about');
  assertEq('locale is null', result.locale, null);
});

// ── Locale ──

test('Locale: Stripped when defined', () => {
  const result = normalizePath('/de/about', ['de', 'fr', 'en']);
  assertEq('path', result.path, '/about');
  assertEq('locale', result.locale, 'de');
});

test('Locale: Root', () => {
  const result = normalizePath('/de', ['de', 'fr']);
  assertEq('path', result.path, '/');
  assertEq('locale', result.locale, 'de');
});

test('Locale: No match', () => {
  const result = normalizePath('/ja/about', ['de', 'fr']);
  assertEq('path unchanged', result.path, '/ja/about');
  assertEq('no locale', result.locale, null);
});

test('Locale: Case insensitive', () => {
  const result = normalizePath('/DE/about', ['de', 'fr']);
  assertEq('path', result.path, '/about');
  assertEq('locale', result.locale, 'de');
});

test('Locale: Deep path', () => {
  const result = normalizePath('/fr/blog/posts/123', ['de', 'fr']);
  assertEq('path', result.path, '/blog/posts/123');
  assertEq('locale', result.locale, 'fr');
});

test('Locale: Only first segment', () => {
  const result = normalizePath('/page/de', ['de']);
  assertEq('path unchanged', result.path, '/page/de');
  assertEq('no locale', result.locale, null);
});

// ═══ 4. Route Matching ═════════════════════════════════════

test('Route: Exact match', () => {
  const index = { routes: [{ pattern: '/about', overlay_id: 'about', match_type: 'exact', priority: 0 }] };
  const match = matchRoute('/about', index);
  assertEq('overlay_id', match?.overlay_id, 'about');
  assertEq('match_type', match?.match_type, 'exact');
});

test('Route: Exact no match', () => {
  const index = { routes: [{ pattern: '/about', overlay_id: 'about', match_type: 'exact', priority: 0 }] };
  assertEq('no match', matchRoute('/contact', index), null);
});

test('Route: Wildcard single segment', () => {
  const index = { routes: [{ pattern: '/blog/*', overlay_id: 'blog', match_type: 'wildcard', priority: 0 }] };
  const match = matchRoute('/blog/my-post', index);
  assertEq('matches', match?.overlay_id, 'blog');
});

test('Route: Wildcard no multi-segment', () => {
  const index = { routes: [{ pattern: '/blog/*', overlay_id: 'blog', match_type: 'wildcard', priority: 0 }] };
  assertEq('no deep match', matchRoute('/blog/2026/post', index), null);
});

test('Route: Wildcard no empty', () => {
  const index = { routes: [{ pattern: '/blog/*', overlay_id: 'blog', match_type: 'wildcard', priority: 0 }] };
  assertEq('no empty match', matchRoute('/blog', index), null);
});

test('Route: Globstar deep', () => {
  const index = { routes: [{ pattern: '/docs/**', overlay_id: 'docs', match_type: 'globstar', priority: 0 }] };
  const match = matchRoute('/docs/api/v2', index);
  assertEq('matches', match?.overlay_id, 'docs');
});

test('Route: Exact beats wildcard', () => {
  const index = { routes: [
    { pattern: '/blog/*', overlay_id: 'wild', match_type: 'wildcard', priority: 0 },
    { pattern: '/blog/featured', overlay_id: 'exact', match_type: 'exact', priority: 0 },
  ] };
  assertEq('exact wins', matchRoute('/blog/featured', index)?.overlay_id, 'exact');
});

test('Route: Wildcard beats globstar', () => {
  const index = { routes: [
    { pattern: '/docs/**', overlay_id: 'glob', match_type: 'globstar', priority: 0 },
    { pattern: '/docs/*', overlay_id: 'wild', match_type: 'wildcard', priority: 0 },
  ] };
  assertEq('wildcard wins', matchRoute('/docs/api', index)?.overlay_id, 'wild');
});

test('Route: Priority breaks tie', () => {
  const index = { routes: [
    { pattern: '/shop/*', overlay_id: 'low', match_type: 'wildcard', priority: 1 },
    { pattern: '/shop/*', overlay_id: 'high', match_type: 'wildcard', priority: 10 },
  ] };
  assertEq('higher priority wins', matchRoute('/shop/item', index)?.overlay_id, 'high');
});

test('Route: Lexical tiebreak', () => {
  const index = { routes: [
    { pattern: '/page/*', overlay_id: 'z_overlay', match_type: 'wildcard', priority: 0 },
    { pattern: '/page/*', overlay_id: 'a_overlay', match_type: 'wildcard', priority: 0 },
  ] };
  assertEq('lexically first wins', matchRoute('/page/test', index)?.overlay_id, 'a_overlay');
});

test('Route: Empty returns null', () => {
  assertEq('empty routes', matchRoute('/anything', { routes: [] }), null);
  assertEq('no routes key', matchRoute('/anything', {}), null);
});

test('Route: Golden fixture', () => {
  // /about → exact → about_overlay
  assertEq('exact', matchRoute('/about', routeIndex)?.overlay_id, 'about_overlay');
  // /blog/my-post → wildcard → blog_overlay
  assertEq('wildcard', matchRoute('/blog/my-post', routeIndex)?.overlay_id, 'blog_overlay');
  // /docs/api/v2 → globstar → docs_overlay
  assertEq('globstar', matchRoute('/docs/api/v2', routeIndex)?.overlay_id, 'docs_overlay');
  // /shop/checkout → exact beats wildcard → checkout_overlay
  assertEq('exact beats wild', matchRoute('/shop/checkout', routeIndex)?.overlay_id, 'checkout_overlay');
  // /shop/item → wildcard → shop_overlay
  assertEq('shop wild', matchRoute('/shop/item', routeIndex)?.overlay_id, 'shop_overlay');
  // /unknown → null
  assertEq('no match', matchRoute('/unknown', routeIndex), null);
});

// ═══ 5. Overlay Merge ══════════════════════════════════════

test('Merge: Null overlay returns base', () => {
  const result = merge(baseArtifact, null);
  assertEq('returns base', result.site_id, baseArtifact.site_id);
});

test('Merge: Empty overlay returns base', () => {
  const result = merge(baseArtifact, {});
  assertEq('returns base', result.site_id, baseArtifact.site_id);
});

test('Merge: Golden fixture', () => {
  const result = merge(baseArtifact, overlaySimple);
  // ui_config deep-merged
  assertEq('layout overridden', result.ui_config.layout, 'bottom_bar');
  assertEq('position overridden', result.ui_config.position, 'bottom');
  assertEq('colors preserved', result.ui_config.colors.primary, '#3b82f6');
  // features deep-merged
  assertEq('feature overridden', result.features.lna_shield, false);
  assertEq('feature preserved', result.features.geo_restriction_eu, false);
});

test('Merge: List replacement', () => {
  const overlay = { overlay_id: 'test', cookie_groups: [{ key: 'only-essential' }] };
  const result = merge(baseArtifact, overlay);
  assertEq('replaced, not merged', result.cookie_groups.length, 1);
  assertEq('has new key', result.cookie_groups[0].key, 'only-essential');
});

test('Merge: Null removes key', () => {
  const overlay = { overlay_id: 'test', geo_rules: null };
  const result = merge(baseArtifact, overlay);
  assert('geo_rules removed', !('geo_rules' in result));
});

test('Merge: Base-only fields protected', () => {
  const overlay = { overlay_id: 'test', site_id: 'hacked', domain: 'evil.com', callbacks: { onReady: 'alert()' } };
  const result = merge(baseArtifact, overlay);
  assertEq('site_id preserved', result.site_id, baseArtifact.site_id);
  assertEq('domain preserved', result.domain, baseArtifact.domain);
  assert('callbacks preserved', result.callbacks.onReady.includes('ycookiesDispatchEvent'));
});

test('Merge: Non-eligible fields ignored', () => {
  const overlay = { overlay_id: 'test', random_field: 'ignored', origin: { url: 'evil.com' } };
  const result = merge(baseArtifact, overlay);
  assert('random not added', !('random_field' in result));
  assertEq('origin unchanged', result.origin.url, baseArtifact.origin.url);
});

test('Merge: overlay_id not in result', () => {
  const overlay = { overlay_id: 'test', ui_config: { layout: 'bar' } };
  const result = merge(baseArtifact, overlay);
  assert('no overlay_id', !('overlay_id' in result));
});

test('Merge: Idempotent', () => {
  const r1 = merge(baseArtifact, overlaySimple);
  const r2 = merge(baseArtifact, overlaySimple);
  assertEq('same output', JSON.stringify(r1), JSON.stringify(r2));
});

test('Merge: Does not mutate inputs', () => {
  const baseCopy = JSON.parse(JSON.stringify(baseArtifact));
  const overlayCopy = JSON.parse(JSON.stringify(overlaySimple));
  merge(baseArtifact, overlaySimple);
  assertEq('base unchanged', JSON.stringify(baseArtifact), JSON.stringify(baseCopy));
  assertEq('overlay unchanged', JSON.stringify(overlaySimple), JSON.stringify(overlayCopy));
});

// ═══ Summary ═══════════════════════════════════════════════

console.log('\n' + '='.repeat(50));
console.log(`CROSS-LANGUAGE PARITY: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50));

process.exit(failed > 0 ? 1 : 0);
