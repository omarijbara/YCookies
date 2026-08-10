import { request } from 'undici';
import { statSync } from 'fs';

const TARGET = 'https://duftz.de';
const PROXY_SERVER = '91.99.14.160';

async function measureRequest(url, method = 'GET', headers = {}) {
  const start = performance.now();
  try {
    const res = await request(url, { method, headers, maxRedirections: 0 });
    // drain body to measure full load
    let bodyText = '';
    if (res.body) {
      bodyText = await res.body.text();
    }
    const duration = performance.now() - start;
    return {
      status: res.statusCode,
      durationMs: Math.round(duration),
      cacheStatus: res.headers['x-yc-cache'] || 'none',
      setCookie: res.headers['set-cookie'] || null,
      csp: res.headers['content-security-policy'] || null,
      body: bodyText
    };
  } catch (err) {
    return { error: err.message, durationMs: Math.round(performance.now() - start) };
  }
}

async function runBenchmark() {
  console.log('--- DUFTZ.DE EDGE CACHE BENCHMARK ---\n');

  // Test URLs
  const urls = {
    home: TARGET + '/',
    shop: TARGET + '/shop/',
    category: TARGET + '/produkt-kategorie/parfum-dupes/damen-duftzwillinge/',
    product: TARGET + '/produkt/aventus-inspired/',
    query: TARGET + '/?s=parfum',
    admin: TARGET + '/wp-admin',
  };

  // Removed local statsz fetch since it's firewalled behind Traefik.

  // 2. Cache Population & Validation (MISS -> HIT)
  console.log('\n--- 2. Cache Population & TTFB Comparison ---');
  
  for (const [name, url] of Object.entries(urls)) {
    console.log(`\nTesting ${name}: ${url}`);
    
    // First request
    const r1 = await measureRequest(url);
    console.log(`  [Req 1] Cache: ${r1.cacheStatus} | Time: ${r1.durationMs}ms | Status: ${r1.status}`);

    if (r1.cacheStatus === 'bypass') {
      console.log(`  -> Bypassed by design.`);
      continue;
    }

    // Warm requests
    let totalWarmTime = 0;
    const warmRuns = 5;
    for (let i = 0; i < warmRuns; i++) {
      const rWarm = await measureRequest(url);
      totalWarmTime += rWarm.durationMs;
      console.log(`  [Req ${i+2}] Cache: ${rWarm.cacheStatus} | Time: ${rWarm.durationMs}ms`);
      
      // Integrity Checks
      if (i === 0) {
        if (rWarm.setCookie) console.error(`  [!] ERROR: Set-Cookie leaked on HIT!`);
        
        // Nonce check
        const nonce1 = extractNonce(r1.body);
        const nonce2 = extractNonce(rWarm.body);
        if (nonce1 && nonce2 && nonce1 !== nonce2) {
          console.log(`  [+] Nonce successfully mutated (${nonce1} -> ${nonce2})`);
        } else if (nonce1 === nonce2) {
          console.error(`  [!] ERROR: Nonce did NOT mutate!`);
        }
      }
    }
    const avgWarm = Math.round(totalWarmTime / warmRuns);
    console.log(`  -> Avg HIT Time: ${avgWarm}ms (Speedup: ${Math.round(r1.durationMs / Math.max(avgWarm, 1))}x)`);
  }

  // 3. Bypass Validation (Auth)
  console.log('\n--- 3. Bypass Validation ---');
  const loggedInReq = await measureRequest(urls.home, 'GET', { 'Cookie': 'wordpress_logged_in_xyz=123' });
  console.log(`  Logged-In WP User: Cache Status = ${loggedInReq.cacheStatus}`);
  
  const cartReq = await measureRequest(urls.home, 'GET', { 'Cookie': 'wp_woocommerce_session_123=abc' });
  console.log(`  WooCommerce Cart: Cache Status = ${cartReq.cacheStatus}`);

  const headReq = await measureRequest(urls.home, 'HEAD');
  console.log(`  HEAD Request: Cache Status = ${headReq.cacheStatus}`);

  // End benchmark
}

function extractNonce(html) {
  if (!html) return null;
  const match = html.match(/nonce=['"]([^'"]+)['"]/);
  return match ? match[1] : null;
}

runBenchmark().catch(console.error);
