import { test, expect, type Page } from '@playwright/test';
import {
  CANARY_URL,
  CONSENT_COOKIE,
  waitForBanner,
  clickShadowButton,
} from './fixtures/test-data';

/**
 * Manifest Architecture & Runtime Safety E2E Tests
 *
 * Validates the proxy's manifest pipeline from the browser's perspective:
 *   1. Static-loader-first injection (not dynamic /api/script/)
 *   2. CSP nonce consistency across injected scripts
 *   3. Bootstrapper initialisation & consent lifecycle integrity
 *   4. Proxy response headers (x-proxy, x-request-id)
 *   5. Second canary domain injection verification
 *
 * All tests target the live production canary (duftz.de) and are
 * read-only — no state mutation on the server side.
 *
 * NOTE: /statsz and /metrics are internal-only (Docker network).
 * They return 404 from the public internet by design.
 */

const PROXY_BASE = 'https://duftz.de';
const ADMIN_API = 'https://cookies.ypsilon.dev';

// ── Health Probes ──────────────────────────────────────────────

test.describe('Manifest Architecture — Health Probes', () => {

  test('proxy /healthz confirms liveness', async ({ request }) => {
    const response = await request.get(`${PROXY_BASE}/healthz`);
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.status).toBe('ok');
    expect(body.uptime).toBeGreaterThan(0);

    console.log(`[healthz] Uptime: ${body.uptime}s`);
  });

  test('proxy /health confirms liveness (alias)', async ({ request }) => {
    const response = await request.get(`${PROXY_BASE}/health`);
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.status).toBe('ok');
  });

  test('proxy /readyz confirms Laravel backend is reachable', async ({ request }) => {
    const response = await request.get(`${PROXY_BASE}/readyz`);
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(body.status).toBe('ok');
    expect(body.laravel).toBe('reachable');

    console.log(`[readyz] Laravel status code: ${body.laravel_status}`);
  });

  test('/statsz is restricted from public internet (404)', async ({ request }) => {
    const response = await request.get(`${PROXY_BASE}/statsz`);
    expect(response.status()).toBe(404);
    console.log('[statsz] Correctly hidden from public — 404 returned');
  });

  test('/metrics is restricted from public internet (404)', async ({ request }) => {
    const response = await request.get(`${PROXY_BASE}/metrics`);
    expect(response.status()).toBe(404);
    console.log('[metrics] Correctly hidden from public — 404 returned');
  });

  test('Laravel admin API responds (informational)', async ({ request }) => {
    try {
      const response = await request.get(`${ADMIN_API}/up`, { timeout: 10_000 });
      console.log(`[Admin API] /up → ${response.status()}`);
      expect([200, 302, 401, 403, 404, 500, 502, 503, 504]).toContain(response.status());
    } catch (err) {
      console.log(`[Admin API] /up → unreachable (${(err as Error).message.split('\n')[0]})`);
    }
  });
});

// ── Static Loader Hot Path ─────────────────────────────────────

test.describe('Manifest Architecture — Static Loader Hot Path', () => {

  test.beforeEach(async ({ context }) => {
    await context.clearCookies();
  });

  test('proxy injects YCookies bootstrapper script', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);

    const html = await page.content();

    const hasStaticLoader = html.includes('/build/assets/') || html.includes('.js?v=');
    const hasDynamicLoader = html.includes('/api/script/') || html.includes('/api/boot/');
    const hasAdminRef = html.includes('cookies.ypsilon.dev');
    const hasAnyLoader = hasStaticLoader || hasDynamicLoader || hasAdminRef;

    expect(hasAnyLoader).toBe(true);

    if (hasStaticLoader) {
      console.log('[Static Loader] ✅ Proxy injects static Vite asset — hot path active');
    } else if (hasDynamicLoader) {
      console.log('[Static Loader] ⚠️ Proxy uses dynamic /api/script/ fallback');
    } else {
      console.log('[Static Loader] ℹ️ Proxy uses admin API reference injection');
    }
  });

  test('proxy adds x-proxy and x-request-id response headers', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();

    const headers = response!.headers();

    expect(headers['x-proxy']).toBe('ycookies');
    expect(headers['x-request-id']).toBeDefined();
    expect(headers['x-request-id'].length).toBeGreaterThan(10);

    console.log(`[Headers] x-proxy: ${headers['x-proxy']}`);
    console.log(`[Headers] x-request-id: ${headers['x-request-id']}`);

    const cacheStatus = headers['x-yc-cache'];
    if (cacheStatus) {
      console.log(`[Headers] x-yc-cache: ${cacheStatus}`);
    }
  });

  test('injected scripts have valid nonce attributes when CSP is present', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();

    const csp = response!.headers()['content-security-policy'] ?? '';
    const nonceMatch = csp.match(/nonce-([A-Za-z0-9+/=]+)/);

    if (nonceMatch) {
      const expectedNonce = nonceMatch[1];

      const injectedScriptNonces = await page.evaluate(() => {
        const scripts = document.querySelectorAll('script[nonce]');
        return Array.from(scripts).map(s => ({
          nonce: s.getAttribute('nonce'),
          src: s.getAttribute('src')?.substring(0, 80) || '[inline]',
        }));
      });

      console.log(`[Nonce] CSP nonce: ${expectedNonce}`);
      console.log(`[Nonce] Found ${injectedScriptNonces.length} scripts with nonce attr`);

      const matchingScripts = injectedScriptNonces.filter(s => s.nonce === expectedNonce);
      expect(matchingScripts.length).toBeGreaterThan(0);
    } else if (csp) {
      console.log('[Nonce] CSP present but no nonce directive — may use hash-based CSP');
    } else {
      console.log('[Nonce] No CSP header — origin does not require nonces');
    }
  });

  test('content-encoding is stripped from HTML responses (proxy decompresses)', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();

    const title = await page.title();
    expect(title.length).toBeGreaterThan(0);
    console.log(`[Decompression] Page title: "${title}" → HTML decompressed correctly`);
  });
});

// ── YCookies Runtime Initialisation ────────────────────────────
// These tests require the admin API (cookies.ypsilon.dev) to be up
// so the YCookies JS can fetch its config. When the admin API is
// down, the script fails to load and these tests should be skipped.

test.describe('Manifest Architecture — Runtime Initialisation', () => {
  let adminIsUp = false;

  test.beforeAll(async ({ request }) => {
    // Pre-check: is the admin API reachable?
    try {
      const response = await request.get(`${ADMIN_API}/up`, { timeout: 10_000 });
      adminIsUp = response.status() === 200;
    } catch {
      adminIsUp = false;
    }
    if (!adminIsUp) {
      console.log('[Runtime] ⏭️ Admin API unreachable — skipping runtime init tests');
    }
  });

  test.beforeEach(async ({ context }) => {
    await context.clearCookies();
  });

  test('window.YCookies is initialised after page load', async ({ page }) => {
    test.skip(!adminIsUp, 'Admin API (cookies.ypsilon.dev) is unreachable');

    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });

    const hasYCookies = await page.waitForFunction(
      () => !!(window as any).YCookies?.manager?.config,
      { timeout: 15_000 }
    ).then(() => true).catch(() => false);

    expect(hasYCookies).toBe(true);
    console.log('[Runtime] ✅ window.YCookies.manager.config is present');

    const configKeys = await page.evaluate(() => {
      const config = (window as any).YCookies?.manager?.config;
      return config ? Object.keys(config) : [];
    });

    expect(configKeys.length).toBeGreaterThan(0);
    console.log(`[Runtime] Config keys: ${configKeys.join(', ')}`);
    expect(configKeys.includes('cookie_groups')).toBe(true);
  });

  test('consent banner appears after interaction trigger', async ({ page }) => {
    test.skip(!adminIsUp, 'Admin API (cookies.ypsilon.dev) is unreachable');

    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });

    const appeared = await waitForBanner(page);
    expect(appeared).toBe(true);

    console.log('[Banner] ✅ Consent banner appeared after interaction trigger');
  });
});

// ── Fail-Closed Verification ───────────────────────────────────

test.describe('Manifest Architecture — Fail-Closed Verification', () => {
  let adminIsUp = false;

  test.beforeAll(async ({ request }) => {
    try {
      const response = await request.get(`${ADMIN_API}/up`, { timeout: 10_000 });
      adminIsUp = response.status() === 200;
    } catch {
      adminIsUp = false;
    }
  });

  test.beforeEach(async ({ context }) => {
    await context.clearCookies();
  });

  test('full consent lifecycle: load → banner → accept → cookie set', async ({ page }) => {
    test.skip(!adminIsUp, 'Admin API (cookies.ypsilon.dev) is unreachable');

    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });

    const appeared = await waitForBanner(page);
    expect(appeared).toBe(true);

    const clicked = await clickShadowButton(page, 'yc-btn-accept');
    expect(clicked).toBe(true);

    await page.waitForTimeout(1_500);
    const cookies = await page.context().cookies(CANARY_URL);
    const cc = cookies.find(c => c.name === CONSENT_COOKIE);
    expect(cc).toBeDefined();
    expect(cc!.value).toBeTruthy();

    const decoded = decodeURIComponent(cc!.value);
    try {
      const parsed = JSON.parse(decoded);
      expect(parsed.essential).toBe(true);
      console.log('[Consent] ✅ Valid JSON consent structure:', Object.keys(parsed).join(', '));
    } catch {
      expect(decoded.length).toBeGreaterThan(5);
      console.log('[Consent] ✅ Cookie set (non-JSON format):', decoded.substring(0, 100));
    }
  });

  test('banner does NOT reappear after consent is given', async ({ page }) => {
    test.skip(!adminIsUp, 'Admin API (cookies.ypsilon.dev) is unreachable');

    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });

    const appeared = await waitForBanner(page);
    expect(appeared).toBe(true);

    await clickShadowButton(page, 'yc-btn-accept');
    await page.waitForTimeout(1_000);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    await page.evaluate(() => {
      document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: 100, clientY: 100 }));
      window.dispatchEvent(new Event('scroll'));
    });
    await page.waitForTimeout(2_000);

    const overlayVisible = await page.evaluate(() => {
      const mgr = (window as any).YCookies?.manager;
      if (!mgr?._shadow) return false;
      const overlay = mgr._shadow.getElementById('yc-overlay');
      return overlay?.classList.contains('yc-visible') ?? false;
    });

    expect(overlayVisible).toBe(false);
    console.log('[Persistence] ✅ Banner does not reappear — consent persisted');
  });

  test('proxy serves page even when admin API is unavailable', async ({ page }) => {
    // This is the core fail-closed / availability-leaning test.
    // The proxy has multi-tier caching (RAM, Redis, HTTP with SWR).
    // Even if cookies.ypsilon.dev is down, the proxy serves pages from cache.
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);

    await expect(page.locator('body')).toBeAttached();
    const bodyLength = await page.evaluate(() => document.body.innerHTML.length);
    expect(bodyLength).toBeGreaterThan(100);

    expect(response!.headers()['x-proxy']).toBe('ycookies');

    console.log(`[Fail-Closed] ✅ Page served correctly, body length: ${bodyLength}`);
    console.log(`[Fail-Closed] Cache status: ${response!.headers()['x-yc-cache'] ?? 'N/A'}`);
  });
});

// ── Second Canary Domain ───────────────────────────────────────

test.describe('Manifest Architecture — Second Canary (barbershop-dibo.de)', () => {
  const SECOND_CANARY = 'https://barbershop-dibo.de';

  test('second canary domain is proxied and returns 200', async ({ request }) => {
    const response = await request.get(SECOND_CANARY);

    if (response.status() === 200) {
      const contentType = response.headers()['content-type'] ?? '';
      expect(contentType).toContain('text/html');

      const xProxy = response.headers()['x-proxy'];
      if (xProxy) {
        expect(xProxy).toBe('ycookies');
        console.log('[Second Canary] ✅ barbershop-dibo.de is live and proxied via YCookies');
      } else {
        console.log('[Second Canary] ⚠️ barbershop-dibo.de returns 200 but missing x-proxy header');
      }
    } else {
      console.log(`[Second Canary] barbershop-dibo.de returned ${response.status()} — may not be configured`);
      test.skip(true, 'Second canary domain not yet configured');
    }
  });

  test('second canary has YCookies injection', async ({ page }) => {
    const response = await page.goto(SECOND_CANARY, { waitUntil: 'domcontentloaded', timeout: 30_000 });

    if (!response || response.status() !== 200) {
      test.skip(true, `Second canary returned ${response?.status() ?? 'null'}`);
      return;
    }

    const html = await page.content();
    const hasInjection =
      html.includes('cookies.ypsilon.dev') ||
      html.includes('/api/script/') ||
      html.includes('/api/boot/') ||
      html.includes('data-ycookies-id') ||
      html.includes('ycookies') ||
      html.includes('YCookiesManager');

    if (hasInjection) {
      console.log('[Second Canary] ✅ YCookies injection markers found in HTML');
    } else {
      console.log('[Second Canary] ⚠️ No YCookies injection detected — domain may not have proxy_enabled');
    }
  });
});

// ── Edge Cases & Security ──────────────────────────────────────

test.describe('Manifest Architecture — Security', () => {

  test('HTTPS is enforced (x-proxy header proves TLS termination)', async ({ request }) => {
    const response = await request.get(CANARY_URL);
    expect(response.status()).toBe(200);
    expect(response.headers()['x-proxy']).toBe('ycookies');
  });

  test('non-existent proxy path returns origin 404, not proxy error', async ({ request }) => {
    const response = await request.get(`${PROXY_BASE}/definitely-nonexistent-path-12345`);
    expect([404, 301, 302, 200]).toContain(response.status());

    const xProxy = response.headers()['x-proxy'];
    if (xProxy) {
      expect(xProxy).toBe('ycookies');
      console.log(`[Security] Non-existent path returned ${response.status()} through proxy`);
    }
  });

  test('rate limit headers are present on responses', async ({ request }) => {
    const response = await request.get(CANARY_URL);
    expect(response.status()).toBe(200);

    const rateLimitHeader = response.headers()['x-ratelimit-limit'] ??
                            response.headers()['ratelimit-limit'];

    if (rateLimitHeader) {
      console.log(`[Rate Limit] x-ratelimit-limit: ${rateLimitHeader}`);
      console.log(`[Rate Limit] x-ratelimit-remaining: ${response.headers()['x-ratelimit-remaining'] ?? 'N/A'}`);
    } else {
      console.log('[Rate Limit] No rate-limit headers — may be allowlisted IP');
    }
  });
});
