/**
 * Unit tests for manifest-consumer.js
 *
 * Verifies: manifest resolution, fallback behavior, metric counting.
 */

import {
  resolveManifestConfig,
  applyManifestOverrides,
  mergeBootstrapper,
  manifestMetrics,
} from '../manifest-consumer.js';

let pass = 0;
let fail = 0;

function assert(condition, name) {
  if (condition) {
    console.log(`  ✅ ${name}`);
    pass++;
  } else {
    console.log(`  ❌ ${name}`);
    fail++;
  }
}

// Reset metrics before each test group
function resetMetrics() {
  manifestMetrics.resolved = 0;
  manifestMetrics.fallback = 0;
  manifestMetrics.missing = 0;
  manifestMetrics.signatureOk = 0;
  manifestMetrics.signatureFail = 0;
  manifestMetrics.lastRevision = null;
}

// ════════════════════════════════════════════════════
console.log('\n=== No manifest block ===');
resetMetrics();
{
  const config = { domain: 'test.com', origin: { url: 'https://origin.com' } };
  const result = resolveManifestConfig(config);
  assert(result === null, 'returns null');
  assert(manifestMetrics.missing === 1, 'increments missing');
}

// ════════════════════════════════════════════════════
console.log('\n=== Manifest disabled ===');
resetMetrics();
{
  const config = {
    domain: 'test.com',
    manifest: { enabled: false, reason: 'no_active_revision' },
  };
  const result = resolveManifestConfig(config);
  assert(result === null, 'returns null');
  assert(manifestMetrics.missing === 1, 'increments missing');
}

// ════════════════════════════════════════════════════
console.log('\n=== Manifest enabled, no base_artifact ===');
resetMetrics();
{
  const config = {
    domain: 'test.com',
    manifest: { enabled: true, revision_number: 1 },
  };
  const result = resolveManifestConfig(config);
  assert(result === null, 'returns null');
  assert(manifestMetrics.fallback === 1, 'increments fallback');
}

// ════════════════════════════════════════════════════
console.log('\n=== Manifest enabled, valid base_artifact ===');
resetMetrics();
{
  const config = {
    domain: 'test.com',
    site_id: 'abc123',
    origin: { url: 'https://origin.com' },
    manifest: {
      enabled: true,
      revision_number: 3,
      manifest_hash: 'abc123def456',
      base_artifact: {
        site_id: 'abc123',
        domain: 'test.com',
        origin: { url: 'https://manifest-origin.com' },
        script_blockers: [{ key: 'gtm', handles: ['gtm.js'] }],
        content_blockers: [],
        cookie_policy: { mode: 'allowlist', essential_patterns: ['_ga'] },
        bootstrapper: { script_url: '/api/script/abc123.js' },
        features: { lna_shield: true },
        proxy: { enabled: true, status: 'active', engine: 'node' },
        tcm_config: { enabled: true },
        consent_version: 2,
      },
    },
  };
  const result = resolveManifestConfig(config);
  assert(result !== null, 'returns projected config');
  assert(result.script_blockers.length === 1, 'has script blockers');
  assert(result.script_blockers[0].key === 'gtm', 'correct blocker key');
  assert(result.cookie_policy.mode === 'allowlist', 'correct cookie policy');
  assert(result.origin.url === 'https://manifest-origin.com', 'origin from manifest');
  assert(result.consent.version === 2, 'consent version projected');
  assert(result._manifest.revision === 3, 'revision metadata');
  assert(result._manifest.source === 'manifest', 'source metadata');
  assert(manifestMetrics.resolved === 1, 'increments resolved');
  assert(manifestMetrics.signatureOk === 1, 'increments signatureOk');
}

// ════════════════════════════════════════════════════
console.log('\n=== applyManifestOverrides: null manifest ===');
{
  const config = { domain: 'test.com', origin: { url: 'https://old.com' } };
  const merged = applyManifestOverrides(config, null);
  assert(merged === config, 'returns original config unchanged');
}

// ════════════════════════════════════════════════════
console.log('\n=== applyManifestOverrides: applies overrides ===');
{
  const config = {
    domain: 'test.com',
    revision: '5:123',
    site_id: 'abc',
    origin: { url: 'https://old.com' },
    script_blockers: [],
    content_blockers: [],
    cookie_policy: { mode: 'passthrough' },
    features: { lna_shield: true },
  };
  const manifestConfig = {
    origin: { url: 'https://new.com' },
    script_blockers: [{ key: 'a' }],
    content_blockers: [{ key: 'b' }],
    cookie_policy: { mode: 'allowlist', essential_patterns: ['x'] },
    features: { geo_restriction_eu: true },
    consent: { version: 3 },
    proxy: { enabled: true },
    bootstrapper: { script_url: '/test' },
    _manifest: { revision: 5, source: 'manifest' },
  };
  const merged = applyManifestOverrides(config, manifestConfig);
  assert(merged.domain === 'test.com', 'preserves non-overridden fields');
  assert(merged.revision === '5:123', 'preserves revision counter');
  assert(merged.site_id === 'abc', 'preserves site_id');
  assert(merged.origin.url === 'https://new.com', 'origin overridden');
  assert(merged.script_blockers.length === 1, 'script_blockers overridden');
  assert(merged.content_blockers.length === 1, 'content_blockers overridden');
  assert(merged.cookie_policy.mode === 'allowlist', 'cookie_policy overridden');
  assert(merged.features.lna_shield === true, 'preserves existing features');
  assert(merged.features.geo_restriction_eu === true, 'adds new features');
  assert(merged._manifest.revision === 5, 'manifest metadata attached');
}

// ════════════════════════════════════════════════════
console.log('\n=== mergeBootstrapper: legacy wins over stale manifest ===');
{
  const legacy = {
    script_url: 'https://app.example/api/script/site.js',
    static_loader_url: 'https://app.example/build/assets/manager-NEW.js',
    api_base: 'https://app.example',
  };
  const fromManifest = {
    script_url: 'https://old.example/api/script/site.js',
    static_loader_url: null,
    api_base: 'https://old.example',
  };
  const b = mergeBootstrapper(legacy, fromManifest);
  assert(b.static_loader_url === 'https://app.example/build/assets/manager-NEW.js', 'static_loader_url from legacy');
  assert(b.script_url === 'https://app.example/api/script/site.js', 'script_url from legacy');
  assert(b.api_base === 'https://app.example', 'api_base from legacy');
}

// ════════════════════════════════════════════════════
console.log('\n=== applyManifestOverrides: bootstrapper merged with legacy URLs ===');
{
  const config = {
    domain: 'test.com',
    site_id: 's1',
    bootstrapper: {
      script_url: 'https://live.example/api/script/s1.js',
      static_loader_url: 'https://live.example/build/manager-live.js',
      api_base: 'https://live.example',
    },
    script_blockers: [],
    content_blockers: [],
  };
  const manifestConfig = {
    script_blockers: [{ key: 'x' }],
    style_blockers: [],
    content_blockers: [],
    auto_blocking: { content: true, script: true, style: true, service: true },
    bootstrapper: {
      script_url: 'https://artifact.example/api/script/s1.js',
      static_loader_url: null,
      api_base: 'https://artifact.example',
    },
    cookie_policy: { mode: 'passthrough' },
    features: {},
    consent: { version: 1 },
    proxy: { enabled: true },
    _manifest: { revision: 1, source: 'manifest' },
  };
  const merged = applyManifestOverrides(config, manifestConfig);
  assert(merged.bootstrapper.script_url === 'https://live.example/api/script/s1.js', 'merged uses live script_url');
  assert(
    merged.bootstrapper.static_loader_url === 'https://live.example/build/manager-live.js',
    'merged uses live static_loader_url'
  );
  assert(merged.bootstrapper.api_base === 'https://live.example', 'merged uses live api_base');
}

// ════════════════════════════════════════════════════
console.log('\n==================================================');
console.log(`MANIFEST CONSUMER: ${pass} passed, ${fail} failed`);
console.log('==================================================');

if (fail > 0) process.exit(1);
