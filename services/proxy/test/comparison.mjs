/**
 * Phase 1 Comparison Test — Node Proxy vs Direct Origin
 *
 * Uses undici.request() to properly set Host header for proxy requests.
 * Node's built-in fetch() ignores Host header overrides per Fetch spec.
 */

import { request as undiciRequest } from 'undici';

const NODE_PROXY = 'http://127.0.0.1:8080';
const LARAVEL_API = 'http://127.0.0.1:8000';

let passed = 0;
let failed = 0;
const results = [];

function assert(name, condition, detail = '') {
  if (condition) {
    passed++;
    results.push({ name, status: '✅ PASS', detail });
    console.log(`  ✅ ${name}${detail ? ` (${detail})` : ''}`);
  } else {
    failed++;
    results.push({ name, status: '❌ FAIL', detail });
    console.log(`  ❌ ${name}: ${detail}`);
  }
}

async function test(section, fn) {
  console.log(`\n=== ${section} ===`);
  try {
    await fn();
  } catch (err) {
    failed++;
    results.push({ name: section, status: '❌ ERROR', detail: err.message });
    console.log(`  ❌ ERROR: ${err.message}`);
  }
}

/**
 * Helper: Make a proxy request with proper Host header via undici.
 */
async function proxyRequest(path, host, extraHeaders = {}) {
  return undiciRequest(`${NODE_PROXY}${path}`, {
    method: 'GET',
    headers: {
      host: host,
      accept: 'text/html',
      'user-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) YCookies-Test/1.0',
      ...extraHeaders,
    },
    headersTimeout: 15000,
    bodyTimeout: 30000,
  });
}

// ─── Test 1: Laravel Config API ───

await test('1. Laravel Config API', async () => {
  const res = await undiciRequest(`${LARAVEL_API}/api/proxy-config/duftz.de`);
  assert('Config returns 200', res.statusCode === 200, `Got ${res.statusCode}`);

  const bodyText = await res.body.text();
  const config = JSON.parse(bodyText);
  assert('Config has revision', typeof config.revision === 'number', `revision=${config.revision}`);
  assert('Config has domain', config.domain === 'duftz.de', `domain=${config.domain}`);
  assert('Config has site_id', typeof config.site_id === 'string' && config.site_id.length > 5);
  assert('Config has origin.ip', typeof config.origin?.ip === 'string');
  assert('Config has bootstrapper URL', config.bootstrapper?.script_url?.includes('/api/script/'));
  assert('Config has features', typeof config.features === 'object');

  // HMAC
  const sig = res.headers['x-signature'];
  assert('HMAC signature present', sig && sig.length === 64, `sig=${sig?.length} chars`);

  // ETag
  const etag = res.headers['etag'];
  assert('ETag present', etag && etag.includes(String(config.revision)), `etag=${etag}`);

  // 304 path
  const res304 = await undiciRequest(`${LARAVEL_API}/api/proxy-config/duftz.de`, {
    headers: { 'if-none-match': etag }
  });
  await res304.body.text(); // drain
  assert('304 Not Modified on same revision', res304.statusCode === 304, `Got ${res304.statusCode}`);
});

// ─── Test 2: Config API - Unknown Host ───

await test('2. Config API - Unknown Host', async () => {
  const res = await undiciRequest(`${LARAVEL_API}/api/proxy-config/nonexistent.test`);
  await res.body.text(); // drain
  assert('Unknown host returns 404', res.statusCode === 404, `Got ${res.statusCode}`);
});

// ─── Test 3: Node Proxy - Fail-Closed ───

await test('3. Node Proxy - Fail-Closed (Unknown Host)', async () => {
  const res = await proxyRequest('/', 'attacker.evil.test');
  const body = await res.body.text();
  assert('Unknown host returns 503', res.statusCode === 503, `Got ${res.statusCode}`);
  assert('503 body mentions service unavailable',
    body.toLowerCase().includes('not configured') || body.toLowerCase().includes('unavailable'));
});

// ─── Test 4: Node Proxy - Health ───

await test('4. Node Proxy - Health', async () => {
  const res = await undiciRequest(`${NODE_PROXY}/healthz`);
  assert('Healthz returns 200', res.statusCode === 200, `Got ${res.statusCode}`);
  const body = JSON.parse(await res.body.text());
  assert('Health status is ok', body.status === 'ok');
  assert('Health has uptime', typeof body.uptime === 'number');
});

// ─── Test 5: Node Proxy - HTML Proxying (duftz.de) ───

await test('5. Node Proxy - HTML Proxy (duftz.de)', async () => {
  const res = await proxyRequest('/', 'duftz.de');

  assert('HTML proxy returns 200', res.statusCode === 200, `Got ${res.statusCode}`);

  const contentType = (res.headers['content-type'] || '');
  assert('Content-Type is text/html', contentType.includes('text/html'), `ct=${contentType}`);

  const body = await res.body.text();
  assert('Response body is non-empty', body.length > 100, `length=${body.length}`);

  // Check bootstrapper injection
  // NOTE: count may be 2 during canary testing because the origin (duftz.de)
  // is still served through Laravel's existing proxy, which injects its own copy.
  // In production, Node replaces Laravel, so count will be exactly 1.
  const bootstrapperCount = (body.match(/ycookies-manager/g) || []).length;
  assert('Bootstrapper injected at least once', bootstrapperCount >= 1, `count=${bootstrapperCount}`);
  assert('Bootstrapper has defer', body.includes('id="ycookies-manager" defer'));

  // Check content-length is absent (since we inject content)
  const cl = res.headers['content-length'];
  assert('Content-Length absent for HTML', cl === undefined || cl === null, `cl=${cl}`);

  // Check x-request-id header
  const reqId = res.headers['x-request-id'];
  assert('X-Request-Id is present', reqId && reqId.length > 10, `reqId=${reqId}`);

  // Check x-proxy header
  assert('X-Proxy header is ycookies', res.headers['x-proxy'] === 'ycookies');

  // Check page has real HTML structure
  assert('Has <html> tag', body.toLowerCase().includes('<html'));
  assert('Has <head> tag', body.toLowerCase().includes('<head'));
  assert('Has <body> tag', body.toLowerCase().includes('<body'));
});

// ─── Test 6: Non-HTML Passthrough ───

await test('6. Node Proxy - Non-HTML Passthrough', async () => {
  const res = await proxyRequest('/favicon.ico', 'duftz.de', { accept: '*/*' });

  // Accept 200 or 404 or 301/302 (depending on whether favicon exists on origin)
  assert('Non-HTML returns valid status', [200, 301, 302, 404].includes(res.statusCode), `Got ${res.statusCode}`);

  // If 200, content-type should be non-HTML
  if (res.statusCode === 200) {
    const contentType = (res.headers['content-type'] || '');
    assert('Non-HTML has non-HTML content-type', !contentType.includes('text/html'), `ct=${contentType}`);

    // Content-length should be preserved for non-HTML
    const cl = res.headers['content-length'];
    if (cl !== undefined) {
      assert('Non-HTML preserves content-length', parseInt(cl, 10) >= 0, `cl=${cl}`);
    }
  }
  await res.body.text(); // drain
});

// ─── Test 7: Header Parity ───

await test('7. Header Parity', async () => {
  const res = await proxyRequest('/', 'duftz.de');

  // Check important headers are forwarded
  const contentType = res.headers['content-type'];
  assert('Content-Type forwarded', contentType !== undefined, `ct=${contentType}`);

  // X-Request-Id and X-Proxy should be present
  assert('X-Request-Id present', typeof res.headers['x-request-id'] === 'string');
  assert('X-Proxy present', res.headers['x-proxy'] === 'ycookies');

  await res.body.text(); // drain
});

// ─── Test 8: Config Cache ───

await test('8. Config Cache Behavior', async () => {
  // First request (cache may already be warm from test 5)
  const start1 = Date.now();
  const res1 = await proxyRequest('/', 'duftz.de');
  const time1 = Date.now() - start1;
  await res1.body.text(); // drain

  // Second request (should definitely be cache hit)
  const start2 = Date.now();
  const res2 = await proxyRequest('/', 'duftz.de');
  const time2 = Date.now() - start2;
  await res2.body.text(); // drain

  assert('First request returns 200', res1.statusCode === 200, `Got ${res1.statusCode}`);
  assert('Second request returns 200', res2.statusCode === 200, `Got ${res2.statusCode}`);
  assert('Both have x-request-id (different)',
    res1.headers['x-request-id'] !== res2.headers['x-request-id'],
    `id1=${res1.headers['x-request-id']?.slice(0,8)}, id2=${res2.headers['x-request-id']?.slice(0,8)}`);

  console.log(`    First request: ${time1}ms, Second: ${time2}ms`);
});

// ─── Test 9: Stats Endpoint ───

await test('9. Stats Endpoint', async () => {
  const res = await undiciRequest(`${NODE_PROXY}/statsz`);
  assert('Statsz returns 200', res.statusCode === 200, `Got ${res.statusCode}`);
  const body = JSON.parse(await res.body.text());
  assert('Stats has cache info', typeof body.cache === 'object');
  assert('Stats has memory info', typeof body.memory === 'object');
  assert('Cache has at least 1 entry', body.cache.total >= 1, `total=${body.cache.total}`);
});

// ─── Test 10: Readyz with Laravel ───

await test('10. Readyz with Laravel', async () => {
  const res = await undiciRequest(`${NODE_PROXY}/readyz`);
  assert('Readyz returns 200', res.statusCode === 200, `Got ${res.statusCode}`);
  const body = JSON.parse(await res.body.text());
  assert('Laravel is reachable', body.laravel === 'reachable');
});

// ─── Summary ───

console.log('\n' + '='.repeat(50));
console.log(`RESULTS: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50));

if (failed > 0) {
  console.log('\nFailed tests:');
  results.filter(r => r.status !== '✅ PASS').forEach(r => {
    console.log(`  ${r.status} ${r.name}: ${r.detail}`);
  });
}

process.exit(failed > 0 ? 1 : 0);
