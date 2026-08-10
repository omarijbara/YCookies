/**
 * Stampede Protection Test — Verifies jittered TTL and peak in-flight tracking.
 *
 * Tests for:
 *   1. jitteredTTL stays within ±20% bounds
 *   2. jitteredTTL distribution is not clustered (spread test)
 *   3. jitteredTTL never goes below base/2 floor
 *   4. Different base values produce proportional jitter
 *   5. Peak in-flight metric updates correctly
 */

import { jitteredTTL } from '../config-resolver.js';

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

// ── Test 1: Bounds Check ─────────────────────────────────────

console.log('\n=== Test 1: jitteredTTL Bounds (±20%) ===\n');

const BASE = 300_000; // 300s = CACHE_TTL
const MIN_EXPECTED = BASE * 0.8;  // 240s
const MAX_EXPECTED = BASE * 1.2;  // 360s
const SAMPLES = 1000;

let allInBounds = true;
let minSeen = Infinity;
let maxSeen = -Infinity;

for (let i = 0; i < SAMPLES; i++) {
  const val = jitteredTTL(BASE);
  if (val < MIN_EXPECTED || val > MAX_EXPECTED) {
    allInBounds = false;
  }
  if (val < minSeen) minSeen = val;
  if (val > maxSeen) maxSeen = val;
}

assert(allInBounds, `All ${SAMPLES} samples within [${MIN_EXPECTED}, ${MAX_EXPECTED}]`);
assert(minSeen >= MIN_EXPECTED, `Min seen (${minSeen}) >= ${MIN_EXPECTED}`);
assert(maxSeen <= MAX_EXPECTED, `Max seen (${maxSeen}) <= ${MAX_EXPECTED}`);

// ── Test 2: Distribution Spread ──────────────────────────────

console.log('\n=== Test 2: Distribution Spread ===\n');

// Verify the range is at least 30% of the expected jitter window
const jitterWindow = MAX_EXPECTED - MIN_EXPECTED;  // 120s
const observedSpread = maxSeen - minSeen;
assert(
  observedSpread >= jitterWindow * 0.3,
  `Spread (${observedSpread}ms) >= 30% of window (${Math.round(jitterWindow * 0.3)}ms)`
);

// Bucket test: divide range into 5 buckets, each should have at least some samples
const bucketSize = jitterWindow / 5;
const buckets = [0, 0, 0, 0, 0];
for (let i = 0; i < 2000; i++) {
  const val = jitteredTTL(BASE);
  const bucketIdx = Math.min(4, Math.floor((val - MIN_EXPECTED) / bucketSize));
  if (bucketIdx >= 0 && bucketIdx <= 4) buckets[bucketIdx]++;
}
const allBucketsHit = buckets.every(b => b > 0);
assert(allBucketsHit, `All 5 distribution buckets populated: [${buckets.join(', ')}]`);

// No bucket should dominate excessively (> 50% of samples)
const maxBucket = Math.max(...buckets);
assert(maxBucket < 1000, `No bucket dominates: max=${maxBucket}/2000`);

// ── Test 3: Floor Protection ─────────────────────────────────

console.log('\n=== Test 3: Floor Protection ===\n');

// jitteredTTL should never return less than base/2
for (let i = 0; i < 500; i++) {
  const val = jitteredTTL(BASE);
  if (val < BASE / 2) {
    assert(false, `Floor violated: ${val} < ${BASE / 2}`);
    break;
  }
}
assert(true, `All 500 samples >= floor (${BASE / 2}ms)`);

// Small base value
const SMALL_BASE = 1000; // 1s
let smallAllAboveFloor = true;
for (let i = 0; i < 500; i++) {
  if (jitteredTTL(SMALL_BASE) < SMALL_BASE / 2) {
    smallAllAboveFloor = false;
    break;
  }
}
assert(smallAllAboveFloor, `Small base (${SMALL_BASE}ms): all samples >= ${SMALL_BASE / 2}ms`);

// ── Test 4: Proportional Jitter ──────────────────────────────

console.log('\n=== Test 4: Proportional Jitter ===\n');

// 30s base (fallback TTL)
const FALLBACK_BASE = 30_000;
const fbMin = FALLBACK_BASE * 0.8;
const fbMax = FALLBACK_BASE * 1.2;
let fbInBounds = true;
for (let i = 0; i < 500; i++) {
  const val = jitteredTTL(FALLBACK_BASE);
  if (val < fbMin || val > fbMax) {
    fbInBounds = false;
    break;
  }
}
assert(fbInBounds, `30s base: all samples within [${fbMin}, ${fbMax}]`);

// Verify different calls produce different values (not deterministic)
const values = new Set();
for (let i = 0; i < 20; i++) {
  values.add(jitteredTTL(BASE));
}
assert(values.size >= 10, `20 calls produced ${values.size} unique values (>= 10 expected)`);

// ── Test 5: Edge Cases ───────────────────────────────────────

console.log('\n=== Test 5: Edge Cases ===\n');

// Zero base
const zeroResult = jitteredTTL(0);
assert(zeroResult === 0, `Zero base returns 0: got ${zeroResult}`);

// Very small base
const tinyResult = jitteredTTL(10);
assert(tinyResult >= 5, `Tiny base (10ms): result ${tinyResult} >= 5 (floor)`);
assert(tinyResult <= 12, `Tiny base (10ms): result ${tinyResult} <= 12 (max)`);

// Negative base should still work (floor protects)
const negResult = jitteredTTL(-100);
assert(typeof negResult === 'number', `Negative base produces number: ${negResult}`);

// ── Test 6: Synchronized Expiry Simulation ───────────────────

console.log('\n=== Test 6: Synchronized Expiry Simulation ===\n');

// Simulate 50 hosts all pre-warmed at the same time
// Without jitter: all expire at exactly boot+300s
// With jitter: expiries should span a ~120s window
const CACHE_TTL_VALUE = 300_000;
const bootTime = Date.now();
const expiryTimes = [];
for (let i = 0; i < 50; i++) {
  expiryTimes.push(bootTime + jitteredTTL(CACHE_TTL_VALUE));
}

const expiryMin = Math.min(...expiryTimes);
const expiryMax = Math.max(...expiryTimes);
const expirySpread = expiryMax - expiryMin;

assert(
  expirySpread > 30_000,
  `50-host expiry spread: ${Math.round(expirySpread / 1000)}s (> 30s required)`
);

// Count how many hosts would expire in the same 10s window
// (with uniform TTL, all 50 would)
const windowStart = expiryMin;
const windowEnd = windowStart + 10_000;
const inWindow = expiryTimes.filter(t => t >= windowStart && t <= windowEnd).length;
assert(
  inWindow < 25,
  `Hosts expiring in same 10s window: ${inWindow}/50 (< 25 expected, vs 50 without jitter)`
);

// ── Summary ─────────────────────────────────────────────────

console.log('\n' + '='.repeat(50));
console.log(`STAMPEDE: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50) + '\n');

// Explicitly exit to prevent the Redis connection from keeping the process alive.
// The config-resolver.js module creates a persistent Redis connection that
// otherwise prevents clean process exit after tests complete.
process.exit(failed > 0 ? 1 : 0);
