import { test, expect } from '@playwright/test';
import { CANARY_URL } from './fixtures/test-data';

/**
 * Example domain (default: duftz.de) — public UI smoke.
 *
 * Verifies the live reference site responds and serves HTML suitable for YCookies E2E.
 * CANARY_URL is overridable via E2E_EXAMPLE_DOMAIN_URL.
 */
test.describe('Example domain (canary)', () => {

  test('/health returns 200', async ({ request }) => {
    const res = await request.get(`${CANARY_URL}/health`);
    expect(res.ok(), `GET ${CANARY_URL}/health → ${res.status()}`).toBeTruthy();
  });

  test('home page returns HTML shell', async ({ page }) => {
    const response = await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();
    expect(response!.status()).toBe(200);
    await expect(page.locator('body')).toBeAttached();
  });

  test('YCookies loader or manager present (when embedded)', async ({ page }) => {
    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2_000);

    const hasYCookies =
      (await page.locator('script[src*="ycookies"], script[src*="/api/script/"], script[src*="cookies.ypsilon"]').count()) >
        0 ||
      (await page.evaluate(() => typeof (window as unknown as { YCookies?: unknown }).YCookies !== 'undefined'));

    expect(hasYCookies, 'Expected YCookies script or window.YCookies on example domain').toBe(true);
  });
});
