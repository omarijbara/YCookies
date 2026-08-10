import http from 'k6/http';
import { check, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';

// ==========================================
// YCOOKIES PLATFORM VALIDATION MATRIX
// TRACK A: duftz.de K6 Load Pipeline
// ==========================================

// Custom Metrics
const cacheMissTTFB = new Trend('ttfb_cache_miss');
const cacheHitTTFB = new Trend('ttfb_cache_hit');
const cacheBypassTTFB = new Trend('ttfb_cache_bypass');
const failureRate = new Rate('failure_rate');

export const options = {
  scenarios: {
    single_validation: {
      executor: 'shared-iterations',
      vus: 1,
      iterations: 5,
      maxDuration: '10s',
    },
    burst_test: {
      executor: 'per-vu-iterations',
      vus: 50,
      iterations: 2, // 100 requests total
      startTime: '10s',
      maxDuration: '20s',
    },
    concurrency_test: {
      executor: 'ramping-vus',
      startVUs: 5,
      stages: [
        { duration: '30s', target: 20 },
        { duration: '30s', target: 50 },
        { duration: '30s', target: 5 },
      ],
      startTime: '30s',
    },
    /*
    short_soak: {
      executor: 'constant-vus',
      vus: 10,
      duration: '15m',
      startTime: '2m',
    }
    */
  },
  thresholds: {
    failure_rate: ['rate<0.01'], // 1% failure budget under burst
  },
};

const BASE_URL = __ENV.TARGET_URL || 'http://localhost:3000';
const URLS = [
  BASE_URL + '/',
  BASE_URL + '/shop/',
  BASE_URL + '/produkt/aventus-inspired/',
  BASE_URL + '/?s=parfum', // Bypass Route
];

export default function () {
  group('Anonymous Navigation Paths', () => {
    const url = URLS[Math.floor(Math.random() * 3)]; // Pick home, shop, or product
    const res = http.get(url);

    check(res, {
      'status is 200': (r) => r.status === 200,
    });
    if (res.status >= 500) failureRate.add(1);

    const cacheHeader = res.headers['X-Yc-Cache'];
    if (cacheHeader === 'miss') {
      cacheMissTTFB.add(res.timings.waiting);
    } else if (cacheHeader === 'hit') {
      cacheHitTTFB.add(res.timings.waiting);
    }
  });

  group('Bypass Assertions', () => {
    const res = http.get(URLS[3]); // query string

    check(res, {
      'status is 200 bypass': (r) => r.status === 200,
      'header specifies bypass': (r) => r.headers['X-Yc-Cache'] === 'bypass',
    });
    cacheBypassTTFB.add(res.timings.waiting);
    if (res.status >= 500) failureRate.add(1);
  });
}
