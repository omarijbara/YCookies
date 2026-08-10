/**
 * Regression Gate — Unified test runner for CI.
 *
 * Runs all 6 test suites and enforces expected pass counts.
 * Fails hard if:
 *   - Any suite has failures
 *   - Any suite's pass count drops below the locked baseline
 *
 * Locked baseline (2026-03-27):
 *   blocker-parity:   128
 *   cookie-filter:     62
 *   stream-parity:     10
 *   self-protection:   14
 *   csp-nonce:         42
 *   reconciliation:    32
 *   html-transform:    36
 *   trust-boundary:    73
 *   observability:     26
 *   stampede:          16
 *   lifecycle:         33
 *   ──────────────────────
 *   TOTAL:            472
 */

import { execSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const PROXY_DIR = join(__dirname, '..');

// ── Locked baselines ────────────────────────────────────────

const SUITES = [
  { name: 'blocker-parity',  file: 'test/blocker-parity.test.mjs',  expectedMin: 128 },
  { name: 'cookie-filter',   file: 'test/cookie-filter.test.mjs',   expectedMin: 62 },
  { name: 'stream-parity',   file: 'test/stream-parity.test.mjs',   expectedMin: 10 },
  { name: 'self-protection',  file: 'test/self-protection.test.mjs', expectedMin: 14 },
  { name: 'csp-nonce',       file: 'test/csp-nonce.test.mjs',       expectedMin: 42 },
  { name: 'reconciliation',  file: 'test/reconciliation.test.mjs',  expectedMin: 32 },
  { name: 'html-transform',  file: 'test/html-transform.test.mjs',  expectedMin: 36 },
  { name: 'trust-boundary',  file: 'test/trust-boundary.test.mjs',  expectedMin: 73 },
  { name: 'observability',   file: 'test/observability.test.mjs',   expectedMin: 26 },
  { name: 'stampede',        file: 'test/stampede.test.mjs',        expectedMin: 16 },
  { name: 'lifecycle',       file: 'test/lifecycle.test.mjs',       expectedMin: 33 },
  { name: 'fallback-integration', file: 'test/fallback-integration.test.mjs', expectedMin: 3 },
];

const EXPECTED_TOTAL = 475;

// ── Run suites ──────────────────────────────────────────────

console.log('\n╔══════════════════════════════════════════════╗');
console.log('║        YCookies Proxy Regression Gate        ║');
console.log('╚══════════════════════════════════════════════╝\n');

let totalPassed = 0;
let totalFailed = 0;
let gateFailures = [];

for (const suite of SUITES) {
  const startTime = Date.now();

  try {
    const output = execSync(`node ${suite.file}`, {
      cwd: PROXY_DIR,
      encoding: 'utf8',
      stdio: ['pipe', 'pipe', 'pipe'],
      timeout: 30000,
    });

    // Extract pass/fail counts from output
    const match = output.match(/(\d+)\s+passed,\s+(\d+)\s+failed/);
    if (!match) {
      gateFailures.push(`${suite.name}: Could not parse test output`);
      console.log(`  ❌ ${suite.name}: output parse error`);
      continue;
    }

    const passed = parseInt(match[1], 10);
    const failed = parseInt(match[2], 10);
    const duration = Date.now() - startTime;

    totalPassed += passed;
    totalFailed += failed;

    // Check failures
    if (failed > 0) {
      gateFailures.push(`${suite.name}: ${failed} test(s) failed`);
      console.log(`  ❌ ${suite.name}: ${passed} passed, ${failed} failed (${duration}ms)`);
      continue;
    }

    // Check baseline
    if (passed < suite.expectedMin) {
      gateFailures.push(`${suite.name}: expected ≥${suite.expectedMin} passed, got ${passed} (tests lost!)`);
      console.log(`  ⚠️  ${suite.name}: ${passed} passed (expected ≥${suite.expectedMin}) (${duration}ms)`);
      continue;
    }

    // All good
    const delta = passed > suite.expectedMin ? ` (+${passed - suite.expectedMin})` : '';
    console.log(`  ✅ ${suite.name}: ${passed} passed${delta} (${duration}ms)`);

  } catch (err) {
    const duration = Date.now() - startTime;
    gateFailures.push(`${suite.name}: process exited with error`);

    // Try to extract counts from stderr/stdout
    const combinedOutput = (err.stdout || '') + (err.stderr || '');
    const match = combinedOutput.match(/(\d+)\s+passed,\s+(\d+)\s+failed/);
    if (match) {
      const passed = parseInt(match[1], 10);
      const failed = parseInt(match[2], 10);
      totalPassed += passed;
      totalFailed += failed;
      console.log(`  ❌ ${suite.name}: ${passed} passed, ${failed} failed (${duration}ms)`);
    } else {
      console.log(`  ❌ ${suite.name}: crashed (${duration}ms)`);
      // Show first line of error for debugging
      const errLine = (err.stderr || err.message || '').split('\n').find(l => l.trim()) || 'unknown';
      console.log(`     ${errLine.substring(0, 120)}`);
    }
  }
}

// ── Summary ─────────────────────────────────────────────────

const allDuration = 'complete';
console.log('\n' + '─'.repeat(48));
console.log(`  Total: ${totalPassed} passed, ${totalFailed} failed`);

if (totalPassed < EXPECTED_TOTAL && gateFailures.length === 0) {
  gateFailures.push(`Total passed (${totalPassed}) below locked baseline (${EXPECTED_TOTAL})`);
}

if (gateFailures.length > 0) {
  console.log('\n  🚫 REGRESSION GATE: FAILED\n');
  for (const f of gateFailures) {
    console.log(`     • ${f}`);
  }
  console.log('');
  process.exit(1);
} else {
  console.log('\n  ✅ REGRESSION GATE: PASSED');
  console.log(`     Baseline: ${EXPECTED_TOTAL} | Actual: ${totalPassed}\n`);
  process.exit(0);
}
