import { initCacheStore, setCache, getCache } from '../cache-store.js';
import { evaluateRequestEligibility, evaluateEligibility } from '../response-cache.js';

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

// ── Test Setup ─────────────────────────────────────────────

console.log('\n=== E2E Cache Lifecycle & Eligibility Test ===\n');

// 1. Initialize Memory Cache Engine (No REDIS_URL)
process.env.REDIS_URL = '';
// Disable Pino logger outputs for tests
const mockLogger = { info: () => {}, error: () => {}, warn: () => {} };
initCacheStore(mockLogger);

async function runTests() {
  const config = {
    domain: 'duftz.de',
    proxy: { cache_disabled: false }
  };

  // Test 1: PHPSESSID relaxation
  console.log('\n--- Test 1: PHPSESSID Eligibility ---');
  const reqHeaders1 = {
    host: 'duftz.de',
    cookie: 'ycookies_preferences=1; PHPSESSID=abc123anonymous' // Generic PHP Session (SHOULD PASS)
  };
  
  const reqCheck1 = evaluateRequestEligibility({
    hostname: 'duftz.de', method: 'GET', url: '/', path: '/', requestHeaders: reqHeaders1, config
  });
  
  assert(reqCheck1.eligible === true, 'Request with ONLY generic PHPSESSID is ELIGIBLE for caching.');

  // Test 2: wordpress_logged_in tracking rejection
  console.log('\n--- Test 2: Logged-in Override ---');
  const reqHeaders2 = {
    host: 'duftz.de',
    cookie: 'wordpress_logged_in_xyz=123;' // Explicit WordPress Auth (SHOULD BYPASS)
  };
  
  const reqCheck2 = evaluateRequestEligibility({
    hostname: 'duftz.de', method: 'GET', url: '/', path: '/', requestHeaders: reqHeaders2, config
  });
  
  assert(reqCheck2.eligible === false && reqCheck2.reason === 'auth_hint', 'Request with wordpress_logged_in bypassed the cache.');

  // Test 3: The standard miss -> hit -> stale lifecycle
  console.log('\n--- Test 3: Cache Write -> Hit -> Stale Lifecycle ---');
  const cacheKey = reqCheck1.cacheKey;
  
  // A. Cache is empty initially (Miss)
  let cached = await getCache(cacheKey);
  assert(cached === null, 'Initial read is a MISS.');

  // B. Write to cache with 1 second TTL
  const mockHeaders = { 'content-type': 'text/html', 'x-upstream-origin': 'true' };
  const mockBody = Buffer.from('<html><body>Test Cache Body</body></html>', 'utf8');
  await setCache(cacheKey, mockHeaders, mockBody, 1);
  assert(true, 'Cache Write successful with 1s TTL.');

  // C. Read from cache immediately (Hit)
  let hit = await getCache(cacheKey);
  assert(hit !== null, 'Immediate re-fetch is a HIT.');
  assert(hit.body.toString('utf8') === '<html><body>Test Cache Body</body></html>', 'Cached payload exactly matches write.');
  assert(hit.headers['x-upstream-origin'] === 'true', 'Headers are successfully stored and retrieved.');

  // D. Wait 1.1s for TTL Expiry (Stale)
  console.log('  Waiting 1100ms for TTL expiry...');
  await new Promise(resolve => setTimeout(resolve, 1100));

  let stale = await getCache(cacheKey);
  assert(stale === null, 'Cache returns MISS after TTL expires (Stale purge).');

  // E. Test 4: Origin response sets PHPSESSID (Should be eligible)
  console.log('\n--- Test 4: Origin Set-Cookie PHPSESSID Eligibility ---');
  const originResponseCheck = evaluateEligibility({
    hostname: 'duftz.de',
    method: 'GET',
    url: '/',
    path: '/',
    requestHeaders: reqHeaders1, // Request passed
    statusCode: 200,
    responseHeaders: { 'content-type': 'text/html' },
    rawUpstreamHeaders: { 'set-cookie': 'PHPSESSID=anon123; path=/' },
    config
  });
  
  assert(originResponseCheck.eligible === true, 'Origin setting PHPSESSID remains ELIGIBLE for cache write.');

  // F. Test 5: Config Revision Invalidation
  console.log('\n--- Test 5: Config Revision Cache Invalidation ---');
  
  // Write cache with revision 0
  await setCache(reqCheck1.cacheKey, mockHeaders, mockBody, 300);
  let initialHit = await getCache(reqCheck1.cacheKey);
  assert(initialHit !== null, 'Hit cache on revision 0.');
  
  // Simulate config bump
  const updatedConfig = { ...config, revision: 1 };
  const newReqCheck = evaluateRequestEligibility({
    hostname: 'duftz.de', method: 'GET', url: '/', path: '/', requestHeaders: reqHeaders1, config: updatedConfig
  });
  
  assert(newReqCheck.cacheKey !== reqCheck1.cacheKey, 'Cache key dynamically updated via config.revision bump.');
  
  let revisionMiss = await getCache(newReqCheck.cacheKey);
  assert(revisionMiss === null, 'Cache returns MISS automatically after revision bump (Isolation success).');

  console.log('\n' + '='.repeat(50));
  console.log(`CACHE E2E SUMMARY: ${passed} passed, ${failed} failed`);
  console.log('='.repeat(50) + '\n');

  if (failed > 0) process.exit(1);
}

runTests().catch(err => {
  console.error(err);
  process.exit(1);
});
