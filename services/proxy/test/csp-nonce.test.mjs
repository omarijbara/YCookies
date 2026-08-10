/**
 * CSP Nonce Test Suite
 *
 * Tests the conservative CSP merge logic:
 * - No CSP → don't add one
 * - script-src → add nonce
 * - script-src-elem → add nonce there (takes priority)
 * - default-src only → create script-src with default-src values + nonce
 * - strict-dynamic → nonce works alongside it
 * - Already has our nonce → no change
 * - Multiple CSP directives → only modify script-related ones
 */

import {
  generateNonce,
  parseCSP,
  serializeCSP,
  mergeNonce,
  buildNoncedScriptTag,
} from '../csp-nonce.js';

let passed = 0;
let failed = 0;

function assert(condition, msg) {
  if (condition) {
    passed++;
    console.log(`  ✅ ${msg}`);
  } else {
    failed++;
    console.log(`  ❌ ${msg}`);
  }
}

// ── Nonce generation ────────────────────────────────────────

console.log('\n=== Nonce Generation ===');

const nonce1 = generateNonce();
const nonce2 = generateNonce();
assert(typeof nonce1 === 'string', 'Returns a string');
assert(nonce1.length >= 20, `Nonce is at least 20 chars (got ${nonce1.length})`);
assert(nonce1 !== nonce2, 'Each call returns a different nonce');

// ── CSP Parsing ─────────────────────────────────────────────

console.log('\n=== CSP Parsing ===');

const parsed1 = parseCSP("default-src 'self'; script-src 'self' https://cdn.example.com; style-src 'self' 'unsafe-inline'");
assert(parsed1.size === 3, `Parsed 3 directives (got ${parsed1.size})`);
assert(parsed1.get('default-src') === "'self'", 'default-src correct');
assert(parsed1.get('script-src') === "'self' https://cdn.example.com", 'script-src correct');
assert(parsed1.get('style-src') === "'self' 'unsafe-inline'", 'style-src correct');

const parsedEmpty = parseCSP('');
assert(parsedEmpty.size === 0, 'Empty CSP parses to empty map');

const parsedNull = parseCSP(null);
assert(parsedNull.size === 0, 'Null CSP parses to empty map');

const parsedNoValue = parseCSP('upgrade-insecure-requests');
assert(parsedNoValue.has('upgrade-insecure-requests'), 'Valueless directive parsed');
assert(parsedNoValue.get('upgrade-insecure-requests') === '', 'Valueless directive has empty value');

// ── CSP Serializing ─────────────────────────────────────────

console.log('\n=== CSP Serializing ===');

const map = new Map([
  ['default-src', "'self'"],
  ['script-src', "'self' 'nonce-abc123'"],
  ['upgrade-insecure-requests', ''],
]);
const serialized = serializeCSP(map);
assert(
  serialized === "default-src 'self'; script-src 'self' 'nonce-abc123'; upgrade-insecure-requests",
  'Serializes correctly'
);

// ── Merge: No CSP ───────────────────────────────────────────

console.log('\n=== Merge: No CSP ===');

const noCSP = mergeNonce('', 'abc123');
assert(noCSP.modified === false, 'No CSP → not modified');
assert(noCSP.csp === '', 'No CSP → empty string');

const nullCSP = mergeNonce(null, 'abc123');
assert(nullCSP.modified === false, 'Null CSP → not modified');

// ── Merge: script-src exists ────────────────────────────────

console.log('\n=== Merge: Existing script-src ===');

const withScriptSrc = mergeNonce("default-src 'self'; script-src 'self' https://cdn.example.com", 'testNonce123');
assert(withScriptSrc.modified === true, 'Modified when script-src exists');
assert(withScriptSrc.csp.includes("'nonce-testNonce123'"), 'Nonce added to CSP');
assert(withScriptSrc.csp.includes("script-src 'self' https://cdn.example.com 'nonce-testNonce123'"), 'Nonce appended to script-src');
assert(withScriptSrc.csp.includes("default-src 'self'"), 'default-src unchanged');

// ── Merge: script-src-elem takes priority ───────────────────

console.log('\n=== Merge: script-src-elem priority ===');

const withElem = mergeNonce("script-src 'self'; script-src-elem 'self' https://cdn.example.com", 'elemNonce');
assert(withElem.modified === true, 'Modified when script-src-elem exists');
assert(withElem.csp.includes("script-src-elem 'self' https://cdn.example.com 'nonce-elemNonce'"), 'Nonce in script-src-elem');
// script-src should NOT get our nonce (script-src-elem overrides)
const scriptSrcInResult = withElem.csp.match(/script-src\s[^;]*/)?.[0] || '';
assert(!scriptSrcInResult.includes('elemNonce'), 'Nonce NOT added to script-src when script-src-elem exists');

// ── Merge: default-src only (no script-src) ─────────────────

console.log('\n=== Merge: default-src fallback ===');

const defaultOnly = mergeNonce("default-src 'self' https://cdn.example.com; style-src 'self'", 'defaultNonce');
assert(defaultOnly.modified === true, 'Modified when only default-src');
assert(defaultOnly.csp.includes("script-src 'self' https://cdn.example.com 'nonce-defaultNonce'"), 'Created script-src from default-src + nonce');
assert(defaultOnly.csp.includes("default-src 'self' https://cdn.example.com"), 'default-src unchanged');
assert(defaultOnly.csp.includes("style-src 'self'"), 'style-src unchanged');

// ── Merge: strict-dynamic ───────────────────────────────────

console.log('\n=== Merge: strict-dynamic ===');

const withStrictDynamic = mergeNonce("script-src 'strict-dynamic' 'nonce-existing123'", 'ourNonce');
assert(withStrictDynamic.modified === true, 'Modified with strict-dynamic');
assert(withStrictDynamic.csp.includes("'strict-dynamic'"), 'strict-dynamic preserved');
assert(withStrictDynamic.csp.includes("'nonce-existing123'"), 'Existing nonce preserved');
assert(withStrictDynamic.csp.includes("'nonce-ourNonce'"), 'Our nonce added alongside');

// ── Merge: duplicate nonce prevention ───────────────────────

console.log('\n=== Merge: Duplicate prevention ===');

const alreadyHas = mergeNonce("script-src 'self' 'nonce-myNonce123'", 'myNonce123');
assert(alreadyHas.modified === false, 'Not modified when nonce already present');

// ── Merge: No script restriction ────────────────────────────

console.log('\n=== Merge: No script restriction ===');

const noScriptRestriction = mergeNonce("style-src 'self'; img-src *", 'someNonce');
assert(noScriptRestriction.modified === false, 'Not modified when no script restriction');
assert(noScriptRestriction.csp === "style-src 'self'; img-src *", 'CSP unchanged');

// ── Merge: Complex real-world CSP ───────────────────────────

console.log('\n=== Merge: Complex real-world CSP ===');

const realWorld = mergeNonce(
  "default-src 'none'; script-src 'self' https://www.google-analytics.com https://www.googletagmanager.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' https://fonts.gstatic.com; connect-src 'self' https://www.google-analytics.com; frame-ancestors 'self'",
  'realNonce'
);
assert(realWorld.modified === true, 'Modified on real-world CSP');
assert(realWorld.csp.includes("'nonce-realNonce'"), 'Nonce present');
assert(realWorld.csp.includes("script-src 'self' https://www.google-analytics.com https://www.googletagmanager.com 'nonce-realNonce'"), 'Nonce appended to real script-src');
assert(realWorld.csp.includes("default-src 'none'"), 'default-src not touched');
assert(realWorld.csp.includes("frame-ancestors 'self'"), 'Other directives preserved');

// ── buildNoncedScriptTag ────────────────────────────────────

console.log('\n=== buildNoncedScriptTag ===');

const tag = buildNoncedScriptTag('https://app.ycookies.com/api/script/abc.js', 'tagNonce99');
assert(tag.includes('nonce="tagNonce99"'), 'Script tag has nonce attribute');
assert(tag.includes('id="ycookies-manager"'), 'Script tag has ycookies-manager id');
assert(tag.includes('defer'), 'Script tag has defer');
assert(tag.includes('src="https://app.ycookies.com/api/script/abc.js"'), 'Script tag has correct src');

// ── Summary ─────────────────────────────────────────────────

console.log('\n' + '='.repeat(50));
console.log(`CSP NONCE TESTS: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50) + '\n');

if (failed > 0) process.exit(1);
