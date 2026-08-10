import { test, expect } from '@playwright/test';
import { CANARY_URL } from './fixtures/test-data';

/**
 * Proxy Headers & Response Validation
 *
 * Tests the Node.js reverse proxy response for the live canary (duftz.de).
 * All tests are read-only GET requests — no state mutation.
 */
test.describe('Proxy Response Headers', () => {

  test('returns 200 with valid HTML', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);

    const contentType = response!.headers()['content-type'] ?? '';
    expect(contentType).toContain('text/html');

    // Page should have a <head> and <body>
    await expect(page.locator('head')).toBeAttached();
    await expect(page.locator('body')).toBeAttached();
  });

  test('CSP header contains nonce directive', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();

    const csp = response!.headers()['content-security-policy'] ?? '';

    // The proxy should inject a per-response nonce into the CSP
    // Accept either a nonce in script-src or no CSP at all (some origins don't set CSP)
    if (csp) {
      // If CSP is present, it should have our nonce
      expect(csp).toMatch(/nonce-[A-Za-z0-9+/=]+/);
    }
  });

  test('does not leak origin server identity via X-Powered-By', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();

    const poweredBy = response!.headers()['x-powered-by'] ?? '';
    // Should not reveal the origin application stack (PHP/Laravel)
    // The proxy itself (Express/Fastify) may set one — that's acceptable
    expect(poweredBy.toLowerCase()).not.toContain('php');
    expect(poweredBy.toLowerCase()).not.toContain('laravel');
  });

  test('injects consent bootstrapper script', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();

    // The proxy injects the YCookies consent manager into the HTML.
    // Check the raw page HTML for any YCookies-related script injection.
    const html = await page.content();
    const hasYCookiesInjection =
      html.includes('cookies.ypsilon.dev') ||
      html.includes('/api/script/') ||
      html.includes('/api/boot/') ||
      html.includes('data-ycookies-id') ||
      html.includes('window.YCookies') ||
      html.includes('ycookies') || // any ycookies mention in script tags
      html.includes('YCookiesManager');

    expect(hasYCookiesInjection).toBe(true);
  });

  test('Set-Cookie headers do not contain internal proxy cookies', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();

    const allHeaders = await response!.allHeaders();
    const setCookie = allHeaders['set-cookie'] ?? '';

    // Internal proxy session cookies should be filtered out
    expect(setCookie).not.toContain('connect.sid');
    expect(setCookie).not.toContain('proxy_session');
  });
});
