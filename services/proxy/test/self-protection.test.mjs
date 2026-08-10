/**
 * Self-Protection Integration Test
 *
 * Proves the composed pipeline (injector → blocker) never blocks
 * YCookies' own injected scripts. This is the most important
 * regression test for transform ordering.
 *
 * Tests:
 * 1. Injector adds ycookies-manager script before </head>
 * 2. Blocker does NOT block the injected script (self-protection)
 * 3. Blocker DOES block third-party scripts on the same page
 * 4. Pipeline works even when injector + blocker compose serially
 */

import { createHtmlInjector } from '../html-injector.js';
import { createBlockerStream } from '../html-blocker-stream.js';
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

/**
 * Pipe HTML through the full transform pipeline (injector → blocker).
 */
function pipelineBlock(html, config) {
  return new Promise((resolve, reject) => {
    const injector = createHtmlInjector(config);
    const blocker = createBlockerStream(config);
    const chunks = [];

    blocker.on('data', (chunk) => chunks.push(chunk.toString()));
    blocker.on('end', () => resolve(chunks.join('')));
    blocker.on('error', reject);

    const readable = Readable.from([html]);
    readable.pipe(injector).pipe(blocker);
  });
}

// ── Config that matches the real Laravel shape ──────────────────

const config = {
  bootstrapper: {
    script_url: 'https://app.ycookies.com/api/script/abc123.js',
  },
  script_blockers: [
    {
      key: 'google-analytics',
      service: 'google-analytics',  // Laravel API field name
      handles: ['googletagmanager.com', 'google-analytics.com'],
      phrases: ['GoogleAnalyticsObject'],
    },
    {
      key: 'facebook-pixel',
      service: 'facebook',
      handles: ['connect.facebook.net'],
      phrases: ['fbq('],
    },
  ],
  content_blockers: [
    {
      key: 'youtube',
      service: 'youtube',
      hosts: ['youtube.com', 'youtube-nocookie.com'],
    },
  ],
};

// ── Test: Full pipeline composibility ───────────────────────────

console.log('\n=== Self-Protection Integration Test ===\n');

const inputHtml = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Real Site</title>
  <script src="https://www.googletagmanager.com/gtag/js?id=GA-12345"></script>
</head>
<body>
  <h1>Hello World</h1>
  <script src="https://connect.facebook.net/en_US/fbevents.js"></script>
  <iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315"></iframe>
</body>
</html>`;

const result = await pipelineBlock(inputHtml, config);

// 1. YCookies script was injected
assert(
  result.includes('id="ycookies-manager"'),
  'YCookies manager script is present in output'
);

// 2. YCookies script was NOT blocked
assert(
  !result.includes('data-ycookies-blocked="true" data-ycookies-blocker-id') ||
    !/ycookies-manager[^>]*data-ycookies-blocked/.test(result),
  'YCookies manager script is NOT blocked'
);

// More precise: check the ycookies-manager script specifically
const ycScript = result.match(/<script[^>]*id="ycookies-manager"[^>]*>/i)?.[0] || '';
assert(
  !ycScript.includes('type="text/template"'),
  'YCookies manager script does NOT have type=text/template'
);
assert(
  !ycScript.includes('data-ycookies-blocked'),
  'YCookies manager script does NOT have data-ycookies-blocked'
);
assert(
  ycScript.includes('defer'),
  'YCookies manager script retains its defer attribute'
);

// 3. Third-party scripts WERE blocked
const gaBlocked = result.includes('data-ycookies-blocker-id="google-analytics"');
assert(gaBlocked, 'Google Analytics script IS blocked');

const fbBlocked = result.includes('data-ycookies-blocker-id="facebook-pixel"');
assert(fbBlocked, 'Facebook Pixel script IS blocked');

// 4. Content blocking worked
const ytBlocked = result.includes('ycookies-content-blocker');
assert(ytBlocked, 'YouTube iframe IS replaced with placeholder');

// 5. No iframe remains
const iframeRemains = /<iframe\b/i.test(result.replace(/<!--[\s\S]*?-->/g, ''));
assert(!iframeRemains, 'No raw iframe tag remains in output');

// 6. Service field from Laravel API was used correctly
assert(
  result.includes('data-ycookies-service="google-analytics"'),
  'Blocker used service field from Laravel API (not service_key)'
);
assert(
  result.includes('data-ycookies-service="facebook"'),
  'Facebook service field from Laravel API is correct'
);

// ── Test: No blockers configured — injector only ────────────────

console.log('\n=== No-Blockers Pipeline Test ===\n');

const noBlockerConfig = {
  bootstrapper: {
    script_url: 'https://app.ycookies.com/api/script/abc123.js',
  },
  script_blockers: [],
  content_blockers: [],
};

const noBlockerResult = await pipelineBlock(inputHtml, noBlockerConfig);

assert(
  noBlockerResult.includes('id="ycookies-manager"'),
  'YCookies script injected even without blockers'
);
assert(
  (noBlockerResult.match(/data-ycookies-blocked/g) || []).length === 0,
  'No scripts blocked when no blockers configured'
);
assert(
  /<iframe\b/i.test(noBlockerResult),
  'Iframes pass through when no content blockers configured'
);

// ── Summary ─────────────────────────────────────────────────────

console.log('\n' + '='.repeat(50));
console.log(`SELF-PROTECTION: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50) + '\n');

if (failed > 0) process.exit(1);
