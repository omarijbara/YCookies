/**
 * k6 Proxy Load Test — YCookies
 *
 * Tests Node proxy performance under concurrent load.
 * Measures: TTFB, response time, throughput, error rate,
 * and verifies script injection + consent flow.
 *
 * Usage:
 *   k6 run tests/load/proxy-stress.js
 *   k6 run tests/load/proxy-stress.js --env TARGET=https://duftz.de
 *   k6 run tests/load/proxy-stress.js --env VUS=30
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// ── Custom metrics ──────────────────────────────────
const ttfb = new Trend('proxy_ttfb', true);
const injectionPresent = new Rate('script_injection_rate');
const consentOk = new Rate('consent_endpoint_rate');
const errorRate = new Rate('error_rate');
const requestCount = new Counter('total_requests');

// ── Configuration ───────────────────────────────────
const TARGET = __ENV.TARGET || 'https://duftz.de';
const VUS = parseInt(__ENV.VUS || '10');

// Extract hostname from TARGET (k6 doesn't have URL API)
const HOSTNAME = TARGET.replace(/^https?:\/\//, '').replace(/[\/:].*$/, '');

export const options = {
  scenarios: {
    ramp_up: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '15s', target: Math.ceil(VUS / 2) },
        { duration: '30s', target: VUS },
        { duration: '30s', target: VUS },
        { duration: '15s', target: 0 },
      ],
      gracefulRampDown: '5s',
    },
  },
  thresholds: {
    'http_req_duration':       ['p(95)<8000'],    // 95th < 8s  (proxy adds latency)
    'proxy_ttfb':              ['p(95)<5000'],    // TTFB p95 < 5s
    'error_rate':              ['rate<0.15'],      // error rate < 15%
    'script_injection_rate':   ['rate>0.80'],      // injection success > 80%
  },
};

// ── Main test function ──────────────────────────────
export default function () {
  // 1. Proxy HTML request (the main workload)
  const htmlRes = http.get(TARGET, {
    headers: {
      'Accept': 'text/html,application/xhtml+xml',
      'User-Agent': 'k6-load-test/1.0 (YCookies proxy stress)',
    },
    timeout: '15s',
  });

  requestCount.add(1);
  const isError = htmlRes.status >= 400 || htmlRes.status === 0;
  errorRate.add(isError);

  if (!isError && htmlRes.timings) {
    ttfb.add(htmlRes.timings.waiting);
  }

  const body = htmlRes.body || '';
  const hasInjection = body.includes('bootstrapper') || body.includes('ycookies');
  injectionPresent.add(hasInjection);

  // Log non-200 responses for debugging
  if (htmlRes.status !== 200 && htmlRes.status !== 301 && htmlRes.status !== 302) {
    console.warn(`[${htmlRes.status}] ${TARGET} — ${htmlRes.error || body.substring(0, 200)}`);
  }

  check(htmlRes, {
    'status 200':         (r) => r.status === 200,
    'TTFB < 5s':          (r) => r.timings && r.timings.waiting < 5000,
    'response < 10s':     (r) => r.timings && r.timings.duration < 10000,
    'has HTML':           (r) => r.body && r.body.includes('</html>'),
    'script injected':    () => hasInjection,
  });

  // 2. Config API check (lightweight)
  const configRes = http.get(
    `https://cookies.ypsilon.dev/api/proxy-config/${HOSTNAME}`,
    { headers: { 'Accept': 'application/json' }, timeout: '5s' }
  );

  check(configRes, {
    'config API 200':     (r) => r.status === 200,
    'config < 1s':        (r) => r.timings && r.timings.duration < 1000,
  });

  // 3. Consent OPTIONS check
  const consentRes = http.options(
    'https://cookies.ypsilon.dev/api/consent/ingest',
    null,
    { timeout: '5s' }
  );
  consentOk.add(consentRes.status < 400);

  // Pacing
  sleep(Math.random() * 2 + 0.5);
}

// ── Summary ─────────────────────────────────────────
export function handleSummary(data) {
  const m = data.metrics;
  const p50 = m.proxy_ttfb?.values?.['p(50)'] || 0;
  const p95 = m.proxy_ttfb?.values?.['p(95)'] || 0;
  const p99 = m.proxy_ttfb?.values?.['p(99)'] || 0;
  const errRate = m.error_rate?.values?.rate || 0;
  const injRate = m.script_injection_rate?.values?.rate || 0;
  const total = m.total_requests?.values?.count || 0;
  const avgDur = m.http_req_duration?.values?.avg || 0;

  const summary = `
╔══════════════════════════════════════════════════╗
║          YCookies Proxy Load Test Results        ║
╠══════════════════════════════════════════════════╣
║  Target:     ${TARGET.padEnd(35)}║
║  VUs:        ${String(VUS).padEnd(35)}║
║  Requests:   ${String(total).padEnd(35)}║
╠══════════════════════════════════════════════════╣
║  TTFB p50:   ${(p50.toFixed(0) + 'ms').padEnd(35)}║
║  TTFB p95:   ${(p95.toFixed(0) + 'ms').padEnd(35)}║
║  TTFB p99:   ${(p99.toFixed(0) + 'ms').padEnd(35)}║
║  Avg Resp:   ${(avgDur.toFixed(0) + 'ms').padEnd(35)}║
║  Error Rate: ${((errRate * 100).toFixed(1) + '%').padEnd(35)}║
║  Injection:  ${((injRate * 100).toFixed(1) + '%').padEnd(35)}║
╚══════════════════════════════════════════════════╝
`;

  console.log(summary);

  return {
    stdout: summary,
    'tests/load/results.json': JSON.stringify(data, null, 2),
  };
}
