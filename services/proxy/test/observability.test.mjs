/**
 * Observability Test — Verifies counter increments and injectionPath tracking.
 *
 * Tests for:
 *   1. proxy-counters module: inc, getCounters, resetCounters
 *   2. html-injector injectionPath property for each path
 *   3. Counter integration: transforms produce correct counter names
 */

import { inc, getCounters, resetCounters } from '../proxy-counters.js';
import { createHtmlInjector } from '../html-injector.js';
import { Readable } from 'node:stream';

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

function injectStream(html, cfg) {
  return new Promise((resolve, reject) => {
    const injector = createHtmlInjector(cfg);
    const chunks = [];
    injector.on('data', c => chunks.push(c.toString()));
    injector.on('end', () => resolve({ result: chunks.join(''), injector }));
    injector.on('error', reject);
    Readable.from([html]).pipe(injector);
  });
}

function injectChunked(html, splitPositions, cfg) {
  return new Promise((resolve, reject) => {
    const injector = createHtmlInjector(cfg);
    const chunks = [];
    injector.on('data', c => chunks.push(c.toString()));
    injector.on('end', () => resolve({ result: chunks.join(''), injector }));
    injector.on('error', reject);
    let pos = 0;
    for (const sp of splitPositions) {
      injector.write(html.slice(pos, sp));
      pos = sp;
    }
    if (pos < html.length) injector.write(html.slice(pos));
    injector.end();
  });
}

const baseCfg = {
  bootstrapper: { script_url: 'https://app.ycookies.com/api/script/abc123.js' },
  script_blockers: [],
  content_blockers: [],
};

// ── Test 1: proxy-counters module ────────────────────────────

console.log('\n=== Test 1: Proxy Counters Module ===\n');

resetCounters();
assert(Object.keys(getCounters()).length === 0, 'Empty after reset');

inc('test_counter');
assert(getCounters().test_counter === 1, 'Inc by 1');

inc('test_counter');
assert(getCounters().test_counter === 2, 'Inc again = 2');

inc('test_counter', 5);
assert(getCounters().test_counter === 7, 'Inc by 5 = 7');

inc('other_counter');
const snap = getCounters();
assert(snap.test_counter === 7 && snap.other_counter === 1, 'Multiple counters coexist');

// Snapshot is a copy, not a reference
snap.test_counter = 999;
assert(getCounters().test_counter === 7, 'Snapshot is a copy, not a reference');

resetCounters();
assert(Object.keys(getCounters()).length === 0, 'Reset clears all');

// ── Test 2: injectionPath — </head> path ─────────────────────

console.log('\n=== Test 2: injectionPath — </head> Path ===\n');

const html1 = '<!DOCTYPE html><html><head><title>T</title></head><body></body></html>';
const { injector: inj1 } = await injectStream(html1, baseCfg);
assert(inj1.injectionPath === 'head', 'Normal </head>: injectionPath = head');

// ── Test 3: injectionPath — </body> fallback ─────────────────

console.log('\n=== Test 3: injectionPath — </body> Fallback ===\n');

const html2 = '<html><body><p>Hello</p></body></html>';
const { injector: inj2 } = await injectStream(html2, baseCfg);
assert(inj2.injectionPath === 'body', 'No head, </body>: injectionPath = body');

// ── Test 4: injectionPath — flush paths ──────────────────────

console.log('\n=== Test 4: injectionPath — Flush Paths ===\n');

// Flush head: </head> only visible in tail buffer (short document)
const html3 = '</head><body></body>';
const { injector: inj3 } = await injectStream(html3, baseCfg);
// This may be 'head' or 'flush_head' depending on length vs TAIL_SIZE
assert(
  inj3.injectionPath === 'head' || inj3.injectionPath === 'flush_head',
  `Short doc: injectionPath = ${inj3.injectionPath} (head or flush_head)`
);

// Flush append: no </head> or </body> at all
const html4 = '<div>fragment</div>';
const { injector: inj4 } = await injectStream(html4, baseCfg);
assert(
  inj4.injectionPath === 'flush_append',
  'No head/body: injectionPath = flush_append'
);

// Empty document
const html5 = '';
const { injector: inj5 } = await injectStream(html5, baseCfg);
assert(
  inj5.injectionPath === 'flush_append',
  'Empty doc: injectionPath = flush_append'
);

// ── Test 5: injectionPath — skip_duplicate ───────────────────

console.log('\n=== Test 5: injectionPath — Skip Duplicate ===\n');

const preExisting1 = `<html><head>
<script src="/existing.js" id="ycookies-manager" defer></script>
</head><body></body></html>`;
const { injector: inj6 } = await injectStream(preExisting1, baseCfg);
assert(inj6.injectionPath === 'skip_duplicate', 'Pre-existing manager: skip_duplicate');

const preExisting2 = `<html><head>
<script data-ycookies-id="site_123" src="/loader.js"></script>
</head><body></body></html>`;
const { injector: inj7 } = await injectStream(preExisting2, baseCfg);
assert(inj7.injectionPath === 'skip_duplicate', 'Pre-existing data-ycookies-id: skip_duplicate');

const preExisting3 = `<html><head>
<script>window.ycookies = { init: true };</script>
</head><body></body></html>`;
const { injector: inj8 } = await injectStream(preExisting3, baseCfg);
assert(inj8.injectionPath === 'skip_duplicate', 'Pre-existing window.ycookies: skip_duplicate');

// ── Test 6: injectionPath — chunk-boundary ───────────────────

console.log('\n=== Test 6: injectionPath — Chunk Boundary ===\n');

const htmlChunk = '<!DOCTYPE html><html><head><title>Test</title></head><body></body></html>';
const splitAt = htmlChunk.indexOf('</head>') + 3; // split mid-</head>
const { injector: inj9 } = await injectChunked(htmlChunk, [splitAt], baseCfg);
assert(inj9.injectionPath === 'head', 'Chunk-split </head>: injectionPath = head');

// ── Test 7: Counter integration with injection ───────────────

console.log('\n=== Test 7: Counter Integration ===\n');

resetCounters();

// Simulate what server.js would do: inc(`inject_${injector.injectionPath}`)
const { injector: simInj } = await injectStream(html1, baseCfg);
inc(`inject_${simInj.injectionPath}`);
assert(getCounters().inject_head === 1, 'inject_head counter incremented');

const { injector: simInj2 } = await injectStream(html2, baseCfg);
inc(`inject_${simInj2.injectionPath}`);
assert(getCounters().inject_body === 1, 'inject_body counter incremented');

const { injector: simInj3 } = await injectStream(preExisting1, baseCfg);
inc(`inject_${simInj3.injectionPath}`);
assert(getCounters().inject_skip_duplicate === 1, 'inject_skip_duplicate counter incremented');

// Simulate other counters
inc('ssrf_ip_blocked');
inc('ssrf_dns_blocked');
inc('origin_auth_current');
inc('origin_auth_legacy');
inc('origin_auth_none');
inc('decompress_gzip');
inc('decompress_br');

const final = getCounters();
assert(final.ssrf_ip_blocked === 1, 'ssrf_ip_blocked counter');
assert(final.ssrf_dns_blocked === 1, 'ssrf_dns_blocked counter');
assert(final.origin_auth_current === 1, 'origin_auth_current counter');
assert(final.origin_auth_legacy === 1, 'origin_auth_legacy counter');
assert(final.origin_auth_none === 1, 'origin_auth_none counter');
assert(final.decompress_gzip === 1, 'decompress_gzip counter');
assert(final.decompress_br === 1, 'decompress_br counter');

resetCounters();

// ── Summary ─────────────────────────────────────────────────

console.log('\n' + '='.repeat(50));
console.log(`OBSERVABILITY: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50) + '\n');

if (failed > 0) process.exit(1);
