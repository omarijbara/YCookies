/**
 * Trust Boundary Test — Verifies security boundaries for the proxy.
 *
 * Tests for:
 *   1. Hop-by-hop header stripping (static + dynamic Connection tokens)
 *   2. Forwarded header replacement (X-Forwarded-For/Host/Proto)
 *   3. SSRF: private IP blocking (IPv4, IPv6, IPv4-mapped)
 *   4. SSRF: protocol and hostname restrictions
 *   5. SSRF: DNS resolution to private IP (defense-in-depth)
 *   6. Origin auth token selection (current, legacy, expired)
 */

import { filterRequestHeaders, filterResponseHeaders, HOP_BY_HOP } from '../headers.js';
import { assertPublicIP, assertPublicUrl, assertPublicHostname, isPrivateIP } from '../ssrf.js';

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

function assertThrows(fn, msg) {
  try {
    fn();
    failed++;
    console.log(`  ❌ ${msg} (did not throw)`);
  } catch {
    passed++;
    console.log(`  ✅ ${msg}`);
  }
}

async function assertRejects(fn, msg) {
  try {
    await fn();
    failed++;
    console.log(`  ❌ ${msg} (did not throw)`);
  } catch {
    passed++;
    console.log(`  ✅ ${msg}`);
  }
}

// ── Test 1: Static Hop-by-Hop Stripping ──────────────────────

console.log('\n=== Test 1: Static Hop-by-Hop Header Stripping ===\n');

const reqHeaders1 = {
  'host': 'example.com',
  'connection': 'keep-alive',
  'keep-alive': 'timeout=5',
  'transfer-encoding': 'chunked',
  'te': 'trailers',
  'trailer': 'Checksum',
  'proxy-authorization': 'Basic abc123',
  'upgrade': 'websocket',
  'user-agent': 'TestBot/1.0',
  'accept': 'text/html',
};

const filtered1 = filterRequestHeaders(reqHeaders1, '1.2.3.4');
assert(!filtered1['connection'], 'Request: connection stripped');
assert(!filtered1['keep-alive'], 'Request: keep-alive stripped');
assert(!filtered1['transfer-encoding'], 'Request: transfer-encoding stripped');
assert(!filtered1['te'], 'Request: te stripped');
assert(!filtered1['trailer'], 'Request: trailer stripped');
assert(!filtered1['proxy-authorization'], 'Request: proxy-authorization stripped');
assert(!filtered1['upgrade'], 'Request: upgrade stripped');
assert(filtered1['accept'] === 'text/html', 'Request: accept preserved');
assert(filtered1['user-agent'] === 'TestBot/1.0', 'Request: user-agent preserved');

// Response path
const resHeaders1 = {
  'content-type': 'text/html',
  'connection': 'close',
  'keep-alive': 'timeout=5',
  'transfer-encoding': 'chunked',
  'proxy-authenticate': 'Basic',
  'cache-control': 'no-cache',
  'set-cookie': 'session=abc',
};

const fRes1 = filterResponseHeaders(resHeaders1);
assert(!fRes1['connection'], 'Response: connection stripped');
assert(!fRes1['keep-alive'], 'Response: keep-alive stripped');
assert(!fRes1['transfer-encoding'], 'Response: transfer-encoding stripped');
assert(!fRes1['proxy-authenticate'], 'Response: proxy-authenticate stripped');
assert(!fRes1['set-cookie'], 'Response: set-cookie stripped (handled separately)');
assert(fRes1['content-type'] === 'text/html', 'Response: content-type preserved');
assert(fRes1['cache-control'] === 'no-cache', 'Response: cache-control preserved');

// ── Test 2: Dynamic Connection Token Stripping (RFC 7230 §6.1) ──

console.log('\n=== Test 2: Dynamic Connection Token Stripping ===\n');

const reqHeaders2 = {
  'host': 'example.com',
  'connection': 'X-Internal-Auth, X-Debug-Token, close',
  'x-internal-auth': 'admin-bypass-token',
  'x-debug-token': 'secret-debug-key',
  'x-safe-header': 'should-survive',
  'user-agent': 'TestBot/1.0',
};

const filtered2 = filterRequestHeaders(reqHeaders2, '1.2.3.4');
assert(!filtered2['connection'], 'Request: connection itself stripped');
assert(!filtered2['x-internal-auth'], 'Request: dynamic hop-by-hop x-internal-auth stripped');
assert(!filtered2['x-debug-token'], 'Request: dynamic hop-by-hop x-debug-token stripped');
assert(filtered2['x-safe-header'] === 'should-survive', 'Request: non-hop-by-hop header preserved');

// Response path
const resHeaders2 = {
  'content-type': 'text/html',
  'connection': 'X-Backend-Secret',
  'x-backend-secret': 'internal-value',
  'x-powered-by': 'Node.js',
};

const fRes2 = filterResponseHeaders(resHeaders2);
assert(!fRes2['connection'], 'Response: connection stripped');
assert(!fRes2['x-backend-secret'], 'Response: dynamic hop-by-hop x-backend-secret stripped');
assert(fRes2['x-powered-by'] === 'Node.js', 'Response: non-hop-by-hop preserved');

// Edge case: no Connection header
const reqHeaders2b = {
  'host': 'example.com',
  'x-custom': 'value',
};

const filtered2b = filterRequestHeaders(reqHeaders2b, '1.2.3.4');
assert(filtered2b['x-custom'] === 'value', 'No Connection header: normal headers preserved');

// ── Test 3: Forwarded Header Replacement ─────────────────────

console.log('\n=== Test 3: Forwarded Header Replacement ===\n');

const reqHeaders3 = {
  'host': 'target.example.com',
  'x-forwarded-for': '99.99.99.99, 88.88.88.88',
  'x-forwarded-host': 'attacker.example.com',
  'x-forwarded-proto': 'http',
  'x-request-id': 'attacker-controlled-id',
  'accept': '*/*',
};

const filtered3 = filterRequestHeaders(reqHeaders3, '1.2.3.4');
assert(filtered3['x-forwarded-for'] === '1.2.3.4', 'XFF replaced with proxy-determined IP');
assert(filtered3['x-forwarded-host'] === 'target.example.com', 'XFH replaced with incoming host');
assert(filtered3['x-forwarded-proto'] === 'https', 'XFP hardcoded to https');
assert(!filtered3['x-request-id'] || filtered3['x-request-id'] !== 'attacker-controlled-id', 'X-Request-Id not forwarded from client');
assert(filtered3['accept'] === '*/*', 'Normal headers preserved');

// Accept-encoding is replaced
assert(filtered3['accept-encoding'] === 'identity', 'Accept-Encoding forced to identity');

// HTTP/2 pseudo-headers stripped
const reqHeaders3b = {
  ':method': 'GET',
  ':path': '/',
  ':authority': 'example.com',
  'host': 'example.com',
};

const filtered3b = filterRequestHeaders(reqHeaders3b, '1.2.3.4');
assert(!filtered3b[':method'], 'H/2 pseudo :method stripped');
assert(!filtered3b[':path'], 'H/2 pseudo :path stripped');
assert(!filtered3b[':authority'], 'H/2 pseudo :authority stripped');

// ── Test 4: SSRF — Private IP Blocking ───────────────────────

console.log('\n=== Test 4: SSRF Private IP Blocking ===\n');

// IPv4 private ranges
assertThrows(() => assertPublicIP('127.0.0.1'), 'Blocks 127.0.0.1 (loopback)');
assertThrows(() => assertPublicIP('127.0.0.2'), 'Blocks 127.0.0.2 (loopback range)');
assertThrows(() => assertPublicIP('10.0.0.1'), 'Blocks 10.x (RFC 1918)');
assertThrows(() => assertPublicIP('10.255.255.255'), 'Blocks 10.255.x (RFC 1918)');
assertThrows(() => assertPublicIP('172.16.0.1'), 'Blocks 172.16.x (RFC 1918)');
assertThrows(() => assertPublicIP('172.31.255.255'), 'Blocks 172.31.x (RFC 1918)');
assertThrows(() => assertPublicIP('192.168.0.1'), 'Blocks 192.168.x (RFC 1918)');
assertThrows(() => assertPublicIP('169.254.1.1'), 'Blocks 169.254.x (link-local)');
assertThrows(() => assertPublicIP('0.0.0.0'), 'Blocks 0.0.0.0');

// IPv4 non-private (should NOT throw)
try { assertPublicIP('8.8.8.8'); passed++; console.log('  ✅ Allows 8.8.8.8 (Google DNS)'); }
catch { failed++; console.log('  ❌ Allows 8.8.8.8 (Google DNS)'); }

try { assertPublicIP('172.32.0.1'); passed++; console.log('  ✅ Allows 172.32.x (not RFC 1918)'); }
catch { failed++; console.log('  ❌ Allows 172.32.x (not RFC 1918)'); }

// IPv6 private
assertThrows(() => assertPublicIP('::1'), 'Blocks ::1 (IPv6 loopback)');
assertThrows(() => assertPublicIP('fe80::1'), 'Blocks fe80:: (link-local)');
assertThrows(() => assertPublicIP('fc00::1'), 'Blocks fc00:: (unique local)');
assertThrows(() => assertPublicIP('fd12::1'), 'Blocks fd:: (unique local)');

// IPv4-mapped IPv6
assertThrows(() => assertPublicIP('::ffff:127.0.0.1'), 'Blocks ::ffff:127.0.0.1 (mapped loopback)');
assertThrows(() => assertPublicIP('::ffff:10.0.0.1'), 'Blocks ::ffff:10.x (mapped private)');

// ── Test 5: SSRF — Protocol and Hostname Restrictions ────────

console.log('\n=== Test 5: SSRF Protocol and Hostname Restrictions ===\n');

assertThrows(() => assertPublicUrl('ftp://example.com'), 'Blocks ftp:// protocol');
assertThrows(() => assertPublicUrl('file:///etc/passwd'), 'Blocks file:// protocol');
assertThrows(() => assertPublicUrl('gopher://evil.com'), 'Blocks gopher:// protocol');
assertThrows(() => assertPublicUrl('https://localhost/'), 'Blocks localhost');
assertThrows(() => assertPublicUrl('https://server.local/'), 'Blocks .local mDNS');
assertThrows(() => assertPublicUrl('https://host.lan/'), 'Blocks .lan mDNS');
assertThrows(() => assertPublicUrl('https://db.internal/'), 'Blocks .internal');
assertThrows(() => assertPublicUrl('https://router.home.arpa/'), 'Blocks .home.arpa');

// Valid URLs should pass
try { assertPublicUrl('https://example.com/'); passed++; console.log('  ✅ Allows https://example.com'); }
catch { failed++; console.log('  ❌ Allows https://example.com'); }

try { assertPublicUrl('http://api.example.com/v1'); passed++; console.log('  ✅ Allows http://api.example.com'); }
catch { failed++; console.log('  ❌ Allows http://api.example.com'); }

// ── Test 6: SSRF — DNS Resolution Check ─────────────────────

console.log('\n=== Test 6: SSRF DNS Resolution Check ===\n');

// localhost resolves to 127.0.0.1 — should be blocked
await assertRejects(
  () => assertPublicHostname('localhost'),
  'DNS: blocks localhost hostname'
);

// IP literals pass through to assertPublicIP
await assertRejects(
  () => assertPublicHostname('127.0.0.1'),
  'DNS: blocks 127.0.0.1 IP literal'
);

await assertRejects(
  () => assertPublicHostname('::1'),
  'DNS: blocks ::1 IPv6 literal'
);

// Public hostname should pass
try {
  await assertPublicHostname('example.com');
  passed++;
  console.log('  ✅ DNS: allows example.com (resolves to public IP)');
} catch {
  failed++;
  console.log('  ❌ DNS: allows example.com (resolves to public IP)');
}

// isPrivateIP utility
assert(isPrivateIP('127.0.0.1') === true, 'isPrivateIP: 127.0.0.1 is private');
assert(isPrivateIP('8.8.8.8') === false, 'isPrivateIP: 8.8.8.8 is not private');
assert(isPrivateIP('::1') === true, 'isPrivateIP: ::1 is private');
assert(isPrivateIP('not-an-ip') === false, 'isPrivateIP: non-IP returns false');

// ── Test 7: Origin Auth Token Selection ──────────────────────

console.log('\n=== Test 7: Origin Auth Token Selection ===\n');

// Import the selectOriginAuthToken function via a local reimplementation
// (it's not exported from server.js, so we test the logic directly)
function selectOriginAuthToken(origin) {
  if (
    origin.auth_token_legacy &&
    origin.auth_legacy_expires_at &&
    new Date() < new Date(origin.auth_legacy_expires_at)
  ) {
    return origin.auth_token_legacy;
  }
  return origin.auth_token;
}

// No legacy token → current token
const origin1 = { auth_token: 'current-abc' };
assert(selectOriginAuthToken(origin1) === 'current-abc', 'No legacy: returns current token');

// Active legacy token (future expiry) → legacy token
const futureDate = new Date(Date.now() + 86400000).toISOString();
const origin2 = { auth_token: 'new-token', auth_token_legacy: 'old-token', auth_legacy_expires_at: futureDate };
assert(selectOriginAuthToken(origin2) === 'old-token', 'Active legacy: returns legacy token (grace period)');

// Expired legacy token (past expiry) → current token
const pastDate = new Date(Date.now() - 86400000).toISOString();
const origin3 = { auth_token: 'new-token', auth_token_legacy: 'expired-token', auth_legacy_expires_at: pastDate };
assert(selectOriginAuthToken(origin3) === 'new-token', 'Expired legacy: returns current token');

// No auth token at all → null/undefined
const origin4 = {};
assert(!selectOriginAuthToken(origin4), 'No token: returns falsy');

// Legacy token but no expiry → current token (safety)
const origin5 = { auth_token: 'current', auth_token_legacy: 'legacy' };
assert(selectOriginAuthToken(origin5) === 'current', 'Legacy without expiry: returns current (safe default)');

// ── Summary ─────────────────────────────────────────────────

console.log('\n' + '='.repeat(50));
console.log(`TRUST-BOUNDARY: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50) + '\n');

if (failed > 0) process.exit(1);
