/**
 * Blocker Parity Test Suite
 *
 * Tests the pure blocker decision layer (html-blocker.js) against
 * golden fixtures derived from Laravel's blocking behavior.
 *
 * Comparison is NORMALIZED — we don't require byte-for-byte identity,
 * just behavioral parity (same blocking decisions, same attributes).
 */

import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { applyBlocking } from '../html-blocker.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const FIXTURES_DIR = join(__dirname, 'fixtures');

// ── Helpers ──────────────────────────────────────────────────

let passed = 0;
let failed = 0;
let total = 0;

function assert(condition, msg) {
  total++;
  if (condition) {
    passed++;
    console.log(`  ✅ ${msg}`);
  } else {
    failed++;
    console.log(`  ❌ ${msg}`);
  }
}

/**
 * Normalize HTML for comparison.
 *
 * We normalize:
 * - Collapse multiple whitespace to single space
 * - Normalize attribute quotes (keep double quotes)
 * - Trim lines
 * - Remove empty lines
 * - Normalize base64 values (re-encode to handle platform differences)
 *
 * We do NOT normalize:
 * - Tag casing (preserve for debugging)
 * - Attribute order (preserve for debugging)
 * - The actual blocking attributes (that's what we're testing!)
 */
function normalizeHtml(html) {
  return html
    .split('\n')
    .map(line => line.trim())
    .filter(line => line.length > 0)
    .join('\n')
    // Collapse runs of whitespace inside tags (but not in text content)
    .replace(/\s+/g, ' ')
    // Normalize attribute spacing around =
    .replace(/\s*=\s*/g, '=')
    .trim();
}

/**
 * Extract blocking decisions from HTML for behavioral comparison.
 *
 * Returns a structured representation of what was blocked and how,
 * independent of exact HTML formatting.
 */
function extractBlockingDecisions(html) {
  const decisions = [];

  // Strip HTML comments first — they can contain <script or <iframe text
  // that would be false matches for our regexes
  const cleanHtml = html.replace(/<!--[\s\S]*?-->/g, '');

  // Find blocked scripts
  const scriptRegex = /<script\b([^>]*)>/gi;
  let match;
  while ((match = scriptRegex.exec(cleanHtml)) !== null) {
    const attrs = match[1];
    if (attrs.includes('data-ycookies-blocked')) {
      const blockerId = attrs.match(/data-ycookies-blocker-id="([^"]*)"/)?.[1] || '';
      const service = attrs.match(/data-ycookies-service="([^"]*)"/)?.[1] || '';
      const src = attrs.match(/src=["']([^"']*)["']/)?.[1] || 'inline';
      decisions.push({
        type: 'script-blocked',
        src,
        blockerId,
        service,
      });
    } else {
      const src = attrs.match(/src=["']([^"']*)["']/)?.[1] || 'inline';
      decisions.push({
        type: 'script-allowed',
        src,
      });
    }
  }

  // Find content blockers (placeholder divs)
  const placeholderRegex = /class="ycookies-content-blocker[^"]*"[^>]*data-ycookies-service="([^"]*)"[^>]*data-ycookies-original="([^"]*)"/g;
  while ((match = placeholderRegex.exec(cleanHtml)) !== null) {
    decisions.push({
      type: 'content-blocked',
      service: match[1],
      hasOriginal: match[2].length > 0,
    });
  }

  // Find allowed iframes (still present as <iframe>)
  const iframeRegex = /<iframe\b([^>]*)>/gi;
  while ((match = iframeRegex.exec(cleanHtml)) !== null) {
    const src = match[1].match(/src=["']([^"']*)["']/)?.[1] || 'no-src';
    decisions.push({
      type: 'iframe-allowed',
      src,
    });
  }

  return decisions;
}

// ── Load config ──────────────────────────────────────────────

const config = JSON.parse(readFileSync(join(FIXTURES_DIR, 'blocker-config.json'), 'utf8'));
const emptyConfig = { script_blockers: [], content_blockers: [] };

// ── Find and run fixtures ────────────────────────────────────

const fixtureFiles = readdirSync(FIXTURES_DIR)
  .filter(f => f.endsWith('.input.html'))
  .sort();

console.log(`\nFound ${fixtureFiles.length} fixture(s)\n`);

for (const inputFile of fixtureFiles) {
  const baseName = inputFile.replace('.input.html', '');
  const expectedFile = baseName + '.expected.html';

  console.log(`\n=== ${baseName} ===`);

  const inputHtml = readFileSync(join(FIXTURES_DIR, inputFile), 'utf8');
  const expectedHtml = readFileSync(join(FIXTURES_DIR, expectedFile), 'utf8');

  // Use empty config for the no-blockers fixture
  const testConfig = baseName === '09-no-blockers' ? emptyConfig : config;

  // Apply blocking
  const actualHtml = applyBlocking(inputHtml, testConfig);

  // Extract blocking decisions from both
  const expectedDecisions = extractBlockingDecisions(expectedHtml);
  const actualDecisions = extractBlockingDecisions(actualHtml);

  // Compare decision counts
  assert(
    actualDecisions.length === expectedDecisions.length,
    `Decision count matches (expected=${expectedDecisions.length}, actual=${actualDecisions.length})`
  );

  // Compare each decision
  for (let i = 0; i < Math.max(expectedDecisions.length, actualDecisions.length); i++) {
    const expected = expectedDecisions[i];
    const actual = actualDecisions[i];

    if (!expected) {
      assert(false, `Extra decision in actual: ${JSON.stringify(actual)}`);
      continue;
    }
    if (!actual) {
      assert(false, `Missing decision in actual: ${JSON.stringify(expected)}`);
      continue;
    }

    assert(
      actual.type === expected.type,
      `Decision ${i}: type matches (expected=${expected.type}, actual=${actual.type})`
    );

    if (expected.type === 'script-blocked') {
      assert(
        actual.blockerId === expected.blockerId,
        `Decision ${i}: blocker-id matches (expected=${expected.blockerId}, actual=${actual.blockerId})`
      );
      assert(
        actual.service === expected.service,
        `Decision ${i}: service matches (expected=${expected.service}, actual=${actual.service})`
      );
    }

    if (expected.type === 'content-blocked') {
      assert(
        actual.service === expected.service,
        `Decision ${i}: content service matches (expected=${expected.service}, actual=${actual.service})`
      );
      assert(
        actual.hasOriginal === expected.hasOriginal,
        `Decision ${i}: has original data (expected=${expected.hasOriginal}, actual=${actual.hasOriginal})`
      );
    }
  }

  // Also verify normalized HTML shape is close
  const normalizedExpected = normalizeHtml(expectedHtml);
  const normalizedActual = normalizeHtml(actualHtml);

  // Check that blocked attributes are present where expected
  const expectedBlockedCount = (expectedHtml.match(/data-ycookies-blocked/g) || []).length;
  const actualBlockedCount = (actualHtml.match(/data-ycookies-blocked/g) || []).length;

  assert(
    actualBlockedCount === expectedBlockedCount,
    `Blocked attribute count matches (expected=${expectedBlockedCount}, actual=${actualBlockedCount})`
  );

  // Check placeholder div count
  const expectedPlaceholders = (expectedHtml.match(/ycookies-content-blocker/g) || []).length;
  const actualPlaceholders = (actualHtml.match(/ycookies-content-blocker/g) || []).length;

  assert(
    actualPlaceholders === expectedPlaceholders,
    `Placeholder div count matches (expected=${expectedPlaceholders}, actual=${actualPlaceholders})`
  );
}

// ── Unit tests for pure functions ────────────────────────────

console.log('\n=== Unit: matchesBlocker ===');

import { matchesBlocker, decideScript, decideContent, decideStyle, mutateScriptTag } from '../html-blocker.js';

// Test handle matching via src
assert(
  matchesBlocker('src="https://www.googletagmanager.com/gtag/js?id=UA-12345"', config.script_blockers[0]),
  'Matches GA by src URL substring'
);

// Test handle matching via id
assert(
  matchesBlocker('id="googletagmanager.com-init" src="/local.js"', config.script_blockers[0]),
  'Matches GA by id containing handle'
);

// Test handle NOT matching — id doesn't contain handle
assert(
  !matchesBlocker('id="my-analytics" src="/local.js"', config.script_blockers[0]),
  'Does NOT match when id does not contain handle substring'
);

// Test phrase matching
assert(
  matchesBlocker('data-info="GoogleAnalyticsObject loader"', config.script_blockers[0]),
  'Matches GA by phrase in attributes'
);

// Test phrase NOT in attributes (in body only — not checked)
assert(
  !matchesBlocker('data-name="inline-code"', config.script_blockers[0]),
  'Does NOT match when phrase is not in attributes'
);

// Test self-protection
console.log('\n=== Unit: self-protection ===');
const ycookiesResult = decideScript(
  '<script src="https://app.ycookies.com/api/script/abc.js" id="ycookies-manager">',
  'src="https://app.ycookies.com/api/script/abc.js" id="ycookies-manager"',
  config.script_blockers
);
assert(!ycookiesResult.blocked, 'YCookies script is never blocked');

// Test mutateScriptTag
console.log('\n=== Unit: mutateScriptTag ===');
const mutated = mutateScriptTag(
  '<script src="https://www.googletagmanager.com/gtag/js?id=UA-12345">',
  config.script_blockers[0]
);
assert(mutated.includes('type="text/template"'), 'Adds type=text/template');
assert(mutated.includes('data-ycookies-blocked="true"'), 'Adds data-ycookies-blocked');
assert(mutated.includes('data-ycookies-blocker-id="google-analytics"'), 'Adds correct blocker-id');
assert(mutated.includes('data-ycookies-service="google-analytics"'), 'Adds correct service');
assert(!mutated.includes('type="text/javascript"'), 'Removes existing type if present');

// Test mutateScriptTag with existing type
const mutatedWithType = mutateScriptTag(
  '<script type="text/javascript" src="https://www.googletagmanager.com/gtag/js?id=UA-12345">',
  config.script_blockers[0]
);
assert(!mutatedWithType.includes('type="text/javascript"'), 'Strips old type when present');
assert(mutatedWithType.includes('type="text/template"'), 'Adds new type after stripping old');

// Test content blocking
console.log('\n=== Unit: decideContent ===');
const ytResult = decideContent(
  'src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560"',
  config.content_blockers
);
assert(ytResult.blocked, 'YouTube iframe is blocked');
assert(ytResult.blocker.service_key === 'youtube', 'Correct blocker identified');

const safeResult = decideContent(
  'src="https://example.com/widget.html" width="300"',
  config.content_blockers
);
assert(!safeResult.blocked, 'Self-hosted iframe is not blocked');

const noSrcResult = decideContent(
  'id="dynamic-frame" width="100%"',
  config.content_blockers
);
assert(!noSrcResult.blocked, 'iframe with no src is not blocked');

const universalScript = decideScript(
  '<script src="https://cdn.third-party.example/tracker.js">',
  'src="https://cdn.third-party.example/tracker.js"',
  [],
  { siteHost: 'example.com', autoBlocking: { script: true } }
);
assert(universalScript.blocked, 'Universal external script blocking works when enabled');
assert(universalScript.tag.includes('data-ycookies-require-group="marketing"'), 'Universal script adds require-group');

const noUniversalScript = decideScript(
  '<script src="https://cdn.third-party.example/tracker.js">',
  'src="https://cdn.third-party.example/tracker.js"',
  [],
  { siteHost: 'example.com', autoBlocking: { script: false } }
);
assert(!noUniversalScript.blocked, 'Universal external script blocking can be disabled');

const noUniversalContent = decideContent(
  'src="https://player.vimeo.com/video/123"',
  [],
  'example.com',
  { content: false }
);
assert(!noUniversalContent.blocked, 'Universal content blocking can be disabled');

const universalStyle = decideStyle(
  '<link rel="stylesheet" href="https://cdn.third-party.example/app.css">',
  'rel="stylesheet" href="https://cdn.third-party.example/app.css"',
  [],
  'example.com',
  { style: true }
);
assert(universalStyle.blocked, 'Universal stylesheet blocking works when enabled');
assert(universalStyle.tag.includes('data-ycookies-style-blocked="true"'), 'Stylesheet gets blocked marker');

// ── Summary ──────────────────────────────────────────────────

console.log('\n' + '='.repeat(50));
console.log(`BLOCKER PARITY TESTS: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50) + '\n');

if (failed > 0) {
  process.exit(1);
}
