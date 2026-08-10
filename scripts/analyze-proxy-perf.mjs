import fs from 'node:fs';
import readline from 'node:readline';

// ─── Settings ────────────────────────────────────────────────────────
const args = process.argv.slice(2);
const LOG_FILE = args[0];

if (!LOG_FILE) {
  console.error('Usage: node analyze-proxy-perf.mjs <path-to-pino-json-log>');
  process.exit(1);
}

// ─── Data Structures ─────────────────────────────────────────────────

const metrics = {
  global: { origin_ttfb_ms: [], origin_body_ms: [], transform_ms: [], total_ms: [] },
  hosts: {}, // { "domain.com": { ttfb: [], total: [] } }
  paths: {
    '/': { origin_ttfb_ms: [], origin_body_ms: [], transform_ms: [], total_ms: [] },
    '/shop/': { origin_ttfb_ms: [], origin_body_ms: [], transform_ms: [], total_ms: [] },
    'product': { origin_ttfb_ms: [], origin_body_ms: [], transform_ms: [], total_ms: [] },
    'category': { origin_ttfb_ms: [], origin_body_ms: [], transform_ms: [], total_ms: [] },
    'cart/account/login/query': { origin_ttfb_ms: [], origin_body_ms: [], transform_ms: [], total_ms: [] },
    'other': { origin_ttfb_ms: [], origin_body_ms: [], transform_ms: [], total_ms: [] }
  },
  hourly: {}, // { "YYYY-MM-DD HH:00": { ttfb: [], total: [] } }
  cache_bypass: {} // { "set_cookie": 150, "auth_hint": 300, ... }
};

// ─── Helper Functions ────────────────────────────────────────────────

function categorizePath(pathStr) {
  if (!pathStr) return 'other';
  
  // URL has a query string OR it's a known non-cacheable/dynamic path
  if (pathStr.includes('?') || pathStr.match(/\/(cart|checkout|account|login|wp-admin|my-account|warenkorb|kasse|mein-konto|api)/i)) {
    return 'cart/account/login/query';
  }
  
  // Root homepage
  if (pathStr === '/' || pathStr === '') return '/';
  
  // Shop index
  if (pathStr === '/shop' || pathStr === '/shop/') return '/shop/';
  
  // Product pages (common WordPress/WooCommerce/Shopify patterns)
  if (pathStr.match(/\/(product|p|item)\//i)) return 'product';
  
  // Category pages
  if (pathStr.match(/\/(category|collection|c)\//i)) return 'category';
  
  return 'other';
}

function pushMetric(target, timing) {
  if (!target) return;
  if (timing.origin_ttfb_ms != null) target.origin_ttfb_ms.push(timing.origin_ttfb_ms);
  if (timing.origin_body_ms != null && timing.origin_body_ms >= 0) target.origin_body_ms.push(timing.origin_body_ms);
  if (timing.transform_ms != null && timing.transform_ms >= 0) target.transform_ms.push(timing.transform_ms);
  if (timing.total_ms != null) target.total_ms.push(timing.total_ms);
}

function percentile(arr, p) {
  if (arr.length === 0) return 0;
  arr.sort((a, b) => a - b);
  const index = (p / 100) * (arr.length - 1);
  const lower = Math.floor(index);
  const upper = Math.ceil(index);
  const weight = index % 1;
  if (upper >= arr.length) return arr[lower];
  return Math.round(arr[lower] * (1 - weight) + arr[upper] * weight);
}

// ─── Stream Processing ───────────────────────────────────────────────

const rl = readline.createInterface({
  input: LOG_FILE === '-' ? process.stdin : fs.createReadStream(LOG_FILE),
  crlfDelay: Infinity
});

rl.on('line', (line) => {
  if (!line.trim()) return;
  try {
    const log = JSON.parse(line);
    
    // 1. Process Cache Policy Decisions
    if (log.msg === 'cache policy decision' && !log.eligible) {
      const reason = log.reason || 'unknown';
      metrics.cache_bypass[reason] = (metrics.cache_bypass[reason] || 0) + 1;
    }
    
    // 2. Process Request Timing
    if (log.msg === 'request timing' && log.timing) {
      const { hostname, path, timing, time } = log;
      
      // Global
      pushMetric(metrics.global, timing);
      
      // Path Class
      const pClass = categorizePath(path);
      pushMetric(metrics.paths[pClass], timing);
      
      // Hostname
      if (!metrics.hosts[hostname]) {
        metrics.hosts[hostname] = { origin_ttfb_ms: [], total_ms: [] };
      }
      if (timing.origin_ttfb_ms != null) metrics.hosts[hostname].origin_ttfb_ms.push(timing.origin_ttfb_ms);
      if (timing.total_ms != null) metrics.hosts[hostname].total_ms.push(timing.total_ms);

      // Hourly (extract from epoch mapping if available)
      const date = time ? new Date(time) : new Date(); // Pino log time is epoch ms
      const hourKey = `${date.toISOString().substring(0, 13)}:00`; 
      if (!metrics.hourly[hourKey]) {
        metrics.hourly[hourKey] = { origin_ttfb_ms: [], total_ms: [] };
      }
      if (timing.origin_ttfb_ms != null) metrics.hourly[hourKey].origin_ttfb_ms.push(timing.origin_ttfb_ms);
      if (timing.total_ms != null) metrics.hourly[hourKey].total_ms.push(timing.total_ms);
    }
    
  } catch (err) {
    // Ignore unparseable lines
  }
});

rl.on('close', () => {
  // ─── Reporting ─────────────────────────────────────────────────────
  
  const report = (name, arrs) => {
    return {
      samples: arrs.total_ms ? arrs.total_ms.length : 0,
      origin_ttfb_ms: { p50: percentile(arrs.origin_ttfb_ms, 50), p95: percentile(arrs.origin_ttfb_ms, 95), p99: percentile(arrs.origin_ttfb_ms, 99) },
      origin_body_ms: { p50: percentile(arrs.origin_body_ms || [], 50), p95: percentile(arrs.origin_body_ms || [], 95), p99: percentile(arrs.origin_body_ms || [], 99) },
      transform_ms: { p50: percentile(arrs.transform_ms || [], 50), p95: percentile(arrs.transform_ms || [], 95), p99: percentile(arrs.transform_ms || [], 99) },
      total_ms: { p50: percentile(arrs.total_ms || [], 50), p95: percentile(arrs.total_ms || [], 95), p99: percentile(arrs.total_ms || [], 99) },
    };
  };

  console.log('\n======================================================');
  console.log('   PROXY 24H SOAK PERFORMANCE ANALYSIS REPORT         ');
  console.log('======================================================\n');
  
  console.log('--- 1. GLOBAL LATENCY PERCENTILES ---');
  console.dir(report('Global', metrics.global), { depth: null, colors: true });

  console.log('\n--- 2. GROUPED BY PATH CLASS ---');
  for (const [pClass, arrs] of Object.entries(metrics.paths)) {
    if (arrs.total_ms.length > 0) {
      console.log(`\nPath Class: [ ${pClass} ]`);
      console.dir(report(pClass, arrs), { depth: null, colors: true });
    }
  }

  console.log('\n--- 3. HOURLY BREAKDOWN (TTFB & TOTAL ms p95) ---');
  const sortedHours = Object.keys(metrics.hourly).sort();
  for (const hr of sortedHours) {
    const hrMetrics = metrics.hourly[hr];
    console.log(` [${hr}]  Samples: ${hrMetrics.total_ms.length.toString().padEnd(6)} | ttfb_p95: ${percentile(hrMetrics.origin_ttfb_ms, 95).toString().padStart(4)}ms | total_p95: ${percentile(hrMetrics.total_ms, 95).toString().padStart(4)}ms`);
  }

  console.log('\n--- 4. CACHE BYPASS DISTRIBUTION ---');
  const bypassSorted = Object.entries(metrics.cache_bypass).sort((a,b) => b[1] - a[1]);
  for (const [reason, count] of bypassSorted) {
    console.log(`  ${reason.padEnd(20)} : ${count}`);
  }
  console.log('\n====== END OF REPORT ======\n');
});
