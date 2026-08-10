/**
 * HTML Transform Test — Full pipeline (injector → blocker) hardening tests.
 *
 * Tests for:
 *   1. Chunk-boundary injection correctness
 *   2. Self-protection invariants (all YCookies script types)
 *   3. Malformed / ugly HTML edge cases
 *   4. Secondary injection points (</body> fallback)
 *   5. Duplicate injection detection
 *   6. Large-preamble / streaming behavior
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

// ── Helpers ──────────────────────────────────────────────────

const config = {
  bootstrapper: {
    script_url: 'https://app.ycookies.com/api/script/abc123.js',
  },
  auto_blocking: {
    content: true,
    script: true,
    style: true,
    service: true,
  },
  script_blockers: [
    {
      key: 'google-analytics',
      service: 'google-analytics',
      handles: ['googletagmanager.com', 'google-analytics.com'],
      phrases: ['GoogleAnalyticsObject'],
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

/**
 * Pipe HTML through the full transform pipeline (injector → blocker).
 */
function pipeline(html, cfg = config) {
  return new Promise((resolve, reject) => {
    const injector = createHtmlInjector(cfg);
    const blocker = createBlockerStream(cfg);
    const chunks = [];

    blocker.on('data', (chunk) => chunks.push(chunk.toString()));
    blocker.on('end', () => resolve(chunks.join('')));
    blocker.on('error', reject);

    const readable = Readable.from([html]);
    readable.pipe(injector).pipe(blocker);
  });
}

/**
 * Pipe HTML through injector only, with chunking at specified positions.
 * This tests chunk-boundary behavior directly.
 */
function injectWithChunks(html, chunkPositions, cfg = config) {
  return new Promise((resolve, reject) => {
    const injector = createHtmlInjector(cfg);
    const chunks = [];

    injector.on('data', (chunk) => chunks.push(chunk.toString()));
    injector.on('end', () => resolve(chunks.join('')));
    injector.on('error', reject);

    let pos = 0;
    for (const splitAt of chunkPositions) {
      injector.write(html.slice(pos, splitAt));
      pos = splitAt;
    }
    if (pos < html.length) {
      injector.write(html.slice(pos));
    }
    injector.end();
  });
}

/**
 * Pipe HTML through injector only (no blocker), single chunk.
 */
function injectOnly(html, cfg = config) {
  return injectWithChunks(html, [], cfg);
}

// ── Test 1: Chunk-Boundary Injection ─────────────────────────

console.log('\n=== Test 1: Chunk-Boundary Injection ===\n');

// 1a: </head> split exactly in the middle: "</he" | "ad>"
const normalHtml = '<!DOCTYPE html><html><head><title>Test</title></head><body></body></html>';
const splitAt = normalHtml.indexOf('</head>') + 3; // split after "</he"
const result1a = await injectWithChunks(normalHtml, [splitAt]);
assert(
  result1a.includes('id="ycookies-manager"'),
  'Chunk split mid-</head>: injection succeeds'
);
assert(
  result1a.indexOf('ycookies-manager') < result1a.indexOf('</head'),
  'Chunk split mid-</head>: injected BEFORE </head>'
);

// 1b: </head> with spaces: </HEAD  >
const spacedHead = '<!DOCTYPE html><html><head><title>Test</title></HEAD  ><body></body></html>';
const result1b = await injectOnly(spacedHead);
assert(
  result1b.includes('id="ycookies-manager"'),
  '</HEAD  > with spaces: injection succeeds'
);

// 1c: </head> at the very end of a chunk boundary (last char)
const endHtml = '<html><head><title>T</title></head><body></body></html>';
const headEnd = endHtml.indexOf('</head>') + 7;
const result1c = await injectWithChunks(endHtml, [headEnd]);
assert(
  result1c.includes('id="ycookies-manager"'),
  '</head> at chunk boundary end: injection succeeds'
);

// 1d: Chunk splits inside </head with only 1 char in tail: "<" | "/head>"
const tinyChunk = normalHtml.indexOf('</head>');
const result1d = await injectWithChunks(normalHtml, [tinyChunk]);
assert(
  result1d.includes('id="ycookies-manager"'),
  'Chunk split at start of </head>: injection succeeds'
);

// ── Test 2: Secondary Injection Point (</body> fallback) ─────

console.log('\n=== Test 2: </body> Fallback Injection ===\n');

// 2a: No <head> at all, has </body>
const noHead = '<html><body><p>Hello</p></body></html>';
const result2a = await injectOnly(noHead);
assert(
  result2a.includes('id="ycookies-manager"'),
  'No <head>: injection uses </body> fallback'
);
assert(
  result2a.indexOf('ycookies-manager') < result2a.indexOf('</body'),
  'No <head>: injected BEFORE </body>'
);

// 2b: No <head> and no </body> — last resort append
const fragment = '<div><p>Just a fragment</p></div>';
const result2b = await injectOnly(fragment);
assert(
  result2b.includes('id="ycookies-manager"'),
  'No </head> or </body>: injection appends at end'
);
assert(
  result2b.indexOf('</div>') < result2b.indexOf('ycookies-manager'),
  'Fragment: script appended after content'
);

// 2c: </body> also chunk-split
const bodyHtml = '<html><body><p>Test</p></body></html>';
const bodySplit = bodyHtml.indexOf('</body>') + 3; // split after "</bo"
const result2c = await injectWithChunks(bodyHtml, [bodySplit]);
assert(
  result2c.includes('id="ycookies-manager"'),
  'Chunk split mid-</body>: injection succeeds via fallback'
);

// ── Test 3: Self-Protection Invariants ───────────────────────

console.log('\n=== Test 3: Self-Protection Invariants ===\n');

// 3a: YCookies manager script is never blocked (full pipeline)
const withGA = `<!DOCTYPE html><html><head>
<script src="https://www.googletagmanager.com/gtag/js?id=GA-123"></script>
</head><body></body></html>`;
const result3a = await pipeline(withGA);
// Manager script should be present and NOT blocked
const managerTag = result3a.match(/<script[^>]*id="ycookies-manager"[^>]*>/i)?.[0] || '';
assert(
  !managerTag.includes('data-ycookies-blocked'),
  'Manager script is NOT blocked by blocker'
);
assert(
  !managerTag.includes('type="text/template"'),
  'Manager script does NOT have type=text/template'
);

// 3b: RUM beacon script is never blocked
assert(
  result3a.includes('data-ycookies-rum'),
  'RUM beacon script is present in output'
);
const rumTag = result3a.match(/<script[^>]*data-ycookies-rum[^>]*>/i)?.[0] || '';
assert(
  !rumTag.includes('data-ycookies-blocked'),
  'RUM beacon script is NOT blocked by blocker'
);

// 3c: Third-party GA IS blocked
assert(
  result3a.includes('data-ycookies-blocker-id="google-analytics"'),
  'Google Analytics IS blocked while YCookies scripts are protected'
);

// 3d: Legacy script primary when static + legacy both configured
const staticConfig = {
  ...config,
  bootstrapper: {
    static_loader_url: 'https://cdn.ycookies.com/loader.js',
    script_url: 'https://app.ycookies.com/api/script/abc123.js',
    api_base: 'https://cookies.ypsilon.dev',
  },
  site_id: 'site_123',
};
const result3d = await pipeline(withGA, staticConfig);
const staticTag = result3d.match(/<script[^>]*id="ycookies-manager"[^>]*>/i)?.[0] || '';
assert(
  staticTag.includes('data-ycookies-id'),
  'Manager script has data-ycookies-id attribute'
);
assert(
  staticTag.includes('api/script/abc123.js'),
  'When legacy script_url exists it is the primary src (reliable cross-origin load)'
);
assert(
  !staticTag.includes('data-ycookies-blocked'),
  'Manager script is NOT blocked'
);
assert(
  !result3d.includes('ycookies-manager-fallback'),
  'No static onerror fallback when legacy is already primary'
);

// 3e: External stylesheets are auto-blocked when enabled
const withExternalStyle = `<!DOCTYPE html><html><head>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/lib.css">
</head><body></body></html>`;
const result3e = await pipeline(withExternalStyle, { ...config, site_host: 'example.com' });
assert(
  result3e.includes('data-ycookies-style-blocked="true"'),
  'External stylesheet is blocked in auto mode'
);

// ── Test 4: Malformed / Ugly HTML ────────────────────────────

console.log('\n=== Test 4: Ugly HTML Edge Cases ===\n');

// 4a: Uppercase tags
const uppercase = '<!DOCTYPE html><HTML><HEAD><TITLE>Test</TITLE></HEAD><BODY></BODY></HTML>';
const result4a = await injectOnly(uppercase);
assert(
  result4a.includes('id="ycookies-manager"'),
  'Uppercase </HEAD>: injection succeeds'
);

// 4b: Mixed case
const mixedCase = '<html><Head><title>T</title></hEaD><body></body></html>';
const result4b = await injectOnly(mixedCase);
assert(
  result4b.includes('id="ycookies-manager"'),
  'Mixed case </hEaD>: injection succeeds'
);

// 4c: Duplicate <head> sections
const dupHead = '<html><head></head><head></head><body></body></html>';
const result4c = await injectOnly(dupHead);
// Count actual script tags with id="ycookies-manager" (not inline JS mentions)
const injCount = (result4c.match(/id="ycookies-manager"/g) || []).length;
assert(
  injCount === 1,
  'Duplicate <head>: only ONE injection (not two)'
);

// 4d: </head> inside HTML comment (known limitation)
const commentHead = '<html><head><!-- </head> --></head><body></body></html>';
const result4d = await injectOnly(commentHead);
assert(
  result4d.includes('id="ycookies-manager"'),
  '</head> in comment: injection still occurs (known: string match sees comments)'
);

// 4e: No closing tags at all
const noClose = '<html><head><title>Test</title>';
const result4e = await injectOnly(noClose);
assert(
  result4e.includes('id="ycookies-manager"'),
  'No closing tags: injection appends at end'
);

// 4f: Empty document
const empty = '';
const result4f = await injectOnly(empty);
assert(
  result4f.includes('id="ycookies-manager"'),
  'Empty document: injection appends at end'
);

// ── Test 5: Duplicate Injection Detection ────────────────────

console.log('\n=== Test 5: Duplicate Injection Detection ===\n');

// 5a: Page already has ycookies-manager — skip injection
const preInjected = `<html><head>
<script src="/existing.js" id="ycookies-manager" defer></script>
</head><body></body></html>`;
const result5a = await injectOnly(preInjected);
// Count actual script tags with the attribute, not RUM inline JS references
const managerCount = (result5a.match(/id="ycookies-manager"/g) || []).length;
assert(
  managerCount === 1,
  'Pre-existing ycookies-manager: no double injection'
);

// 5b: Page has data-ycookies-id — skip injection
const preDataAttr = `<html><head>
<script data-ycookies-id="site_123" src="/loader.js" defer></script>
</head><body></body></html>`;
const result5b = await injectOnly(preDataAttr);
// Should not have injected a NEW manager script (just the existing one)
const dataAttrCount = (result5b.match(/id="ycookies-manager"/g) || []).length;
assert(
  dataAttrCount === 0,
  'Pre-existing data-ycookies-id: injection skipped correctly'
);

// 5c: Page has window.ycookies — skip injection
const preGlobal = `<html><head>
<script>window.ycookies = { init: true };</script>
</head><body></body></html>`;
const result5c = await injectOnly(preGlobal);
// The duplicate check matches case-insensitively
const globalInjected = result5c.includes('id="ycookies-manager"');
assert(
  !globalInjected,
  'Pre-existing window.ycookies: injection skipped correctly'
);

// ── Test 6: Large Preamble (streaming stress) ────────────────

console.log('\n=== Test 6: Large Preamble Streaming ===\n');

// 100KB of content before </head>
const bigContent = 'x'.repeat(100_000);
const largePage = `<html><head><style>${bigContent}</style></head><body></body></html>`;
const result6 = await injectOnly(largePage);
assert(
  result6.includes('id="ycookies-manager"'),
  '100KB preamble: injection succeeds'
);
assert(
  result6.indexOf('ycookies-manager') < result6.indexOf('</head'),
  '100KB preamble: injected BEFORE </head>'
);

// ── Test 7: Mutation Error Isolation (per-chunk fallback) ────

console.log('\n=== Test 7: Mutation Error Isolation ===\n');

// 7a: If transform throws, the raw chunk passes through unchanged
{
  const html = '<!DOCTYPE html><html><head><title>Test</title></head><body></body></html>';
  
  // Create an injector that will encounter an error by feeding a poisoned chunk
  // We use a custom approach: override toString to throw on a later call
  const injector = createHtmlInjector({
    bootstrapper: { script_url: 'https://app.ycookies.com/api/script/test.js' },
  });

  const result = await new Promise((resolve) => {
    const chunks = [];
    injector.on('data', (chunk) => chunks.push(chunk));
    injector.on('end', () => resolve(Buffer.concat(chunks).toString()));

    // Create a buffer whose toString will throw on the second call
    // (first call is in the try block where it tries to convert chunk to string)
    const poisonedBuffer = Buffer.from(html);
    let callCount = 0;
    const origToString = poisonedBuffer.toString.bind(poisonedBuffer);
    poisonedBuffer.toString = function (enc) {
      callCount++;
      if (callCount === 1) {
        throw new Error('Simulated decode error');
      }
      return origToString(enc);
    };

    injector.write(poisonedBuffer);
    injector.end();
  });

  // The raw chunk should have been passed through (the callback receives the original chunk)
  // Since toString threw, the error_fallback path passes the raw Buffer
  assert(
    result.length > 0,
    'Error in transform(): response is NOT empty (data preserved)'
  );
  assert(
    injector.injectionPath === 'error_fallback',
    'Error in transform(): injectionPath = error_fallback'
  );
  assert(
    injector.injectionError instanceof Error,
    'Error in transform(): injectionError is set on stream'
  );
  assert(
    injector.injectionError.message === 'Simulated decode error',
    'Error in transform(): injectionError has correct message'
  );
}

// 7b: Normal operation after error recovery — subsequent chunks still pass through
{
  const injector = createHtmlInjector({
    bootstrapper: { script_url: 'https://app.ycookies.com/api/script/test.js' },
  });

  const result = await new Promise((resolve) => {
    const chunks = [];
    injector.on('data', (chunk) => chunks.push(chunk.toString()));
    injector.on('end', () => resolve(chunks.join('')));

    // First: poisoned chunk triggers error_fallback
    const poisoned = Buffer.from('<html><head>');
    let called = false;
    poisoned.toString = function () {
      if (!called) { called = true; throw new Error('first chunk error'); }
      return '<html><head>';
    };
    injector.write(poisoned);

    // Second: normal chunk should still pass through (injected=true after error)
    injector.write(Buffer.from('</head><body>Hello</body></html>'));
    injector.end();
  });

  assert(
    result.includes('Hello'),
    'Post-error chunks still pass through to client'
  );
  assert(
    injector.injectionPath === 'error_fallback',
    'Injector stays in error_fallback mode after first error'
  );
}

// 7c: No config = no mutation = pure passthrough (never errors)
{
  const injector = createHtmlInjector({ bootstrapper: {} });
  const html = '<html><head></head><body>Test</body></html>';
  const result = await new Promise((resolve) => {
    const chunks = [];
    injector.on('data', (chunk) => chunks.push(chunk.toString()));
    injector.on('end', () => resolve(chunks.join('')));
    injector.write(Buffer.from(html));
    injector.end();
  });

  assert(result === html, 'No bootstrapper config: HTML passes through unchanged');
  assert(injector.injectionError === undefined, 'No bootstrapper config: no error set');
}

// ── Summary ─────────────────────────────────────────────────

console.log('\n' + '='.repeat(50));
console.log(`HTML-TRANSFORM: ${passed} passed, ${failed} failed`);
console.log('='.repeat(50) + '\n');

if (failed > 0) process.exit(1);

