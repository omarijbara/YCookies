import { test, expect } from '@playwright/test';
import {
  CANARY_URL,
  CONSENT_COOKIE,
  waitForBanner,
  isOverlayVisible,
  clickShadowButton,
  countShadowElements,
  clearYCookiesState,
} from './fixtures/test-data';

/**
 * Consent Banner Lifecycle
 *
 * Tests the full YCookies consent banner flow on the live canary (duftz.de).
 * The banner uses a CLOSED Shadow DOM, so all interaction goes through
 * page.evaluate() helpers.
 */
test.describe('Consent Banner', () => {

  test.beforeEach(async ({ context }) => {
    await context.clearCookies();
  });

  test('banner appears on fresh visit', async ({ page }) => {
    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });

    const appeared = await waitForBanner(page);
    expect(appeared).toBe(true);
  });

  test('banner renders Accept All button', async ({ page }) => {
    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);

    // The banner always renders at minimum an Accept All button
    // (even if cookie_groups is empty, the manager uses defaults)
    const hasAcceptBtn = await page.evaluate(() => {
      const mgr = (window as any).YCookies?.manager;
      if (!mgr?._shadow) return false;
      return !!mgr._shadow.getElementById('yc-btn-accept');
    });
    expect(hasAcceptBtn).toBe(true);

    // Also verify there's a banner element
    const hasBanner = await countShadowElements(page, '.yc-banner');
    expect(hasBanner).toBe(1);
  });

  test('Accept All → overlay hides → consent cookie set', async ({ page }) => {
    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);

    // Click accept all
    const clicked = await clickShadowButton(page, 'yc-btn-accept');
    expect(clicked).toBe(true);

    // Wait for overlay to hide
    await page.waitForTimeout(1_000);
    const visible = await isOverlayVisible(page);
    expect(visible).toBe(false);

    // Consent cookie should be set
    const cookies = await page.context().cookies(CANARY_URL);
    const cc = cookies.find(c => c.name === 'ycookies_consent');
    expect(cc).toBeDefined();
    expect(cc!.value).toBeTruthy();
  });

  test('banner does NOT reappear on reload after consent', async ({ page }) => {
    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);

    // Accept
    await clickShadowButton(page, 'yc-btn-accept');
    await page.waitForTimeout(1_000);

    // Reload
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3_000);

    // The wrapper should either not exist or overlay should not be visible
    const wrapperExists = await page.locator('#ycookies-consent-wrapper').count();
    if (wrapperExists > 0) {
      const visible = await isOverlayVisible(page);
      expect(visible).toBe(false);
    }
    // If wrapper doesn't exist at all, that's also correct
  });

  test('clearing cookies → banner reappears', async ({ page, context }) => {
    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);

    // Accept
    await clickShadowButton(page, 'yc-btn-accept');
    await page.waitForTimeout(1_500);

    // Verify consent cookie was set
    let cookies = await page.context().cookies(CANARY_URL);
    let cc = cookies.find(c => c.name === 'ycookies_consent');
    expect(cc).toBeDefined();

    // Clear all cookies
    await context.clearCookies();

    // Also clear localStorage & sessionStorage
    await clearYCookiesState(page);

    // Reload — banner should reappear after interaction
    await page.reload({ waitUntil: 'domcontentloaded' });

    // Cookie should be gone now
    cookies = await page.context().cookies(CANARY_URL);
    cc = cookies.find(c => c.name === 'ycookies_consent');
    expect(cc).toBeUndefined();

    // Banner should reappear (with interaction trigger)
    const appeared = await waitForBanner(page, 25_000);
    expect(appeared).toBe(true);
  });

  test('consent cookie contains expected structure', async ({ page }) => {
    await page.goto(CANARY_URL, { waitUntil: 'domcontentloaded' });
    await waitForBanner(page);

    await clickShadowButton(page, 'yc-btn-accept');
    await page.waitForTimeout(1_000);

    const cookies = await page.context().cookies(CANARY_URL);
    const cc = cookies.find(c => c.name === 'ycookies_consent');
    expect(cc).toBeDefined();

    // The cookie value should be a JSON-encoded consent object
    const decoded = decodeURIComponent(cc!.value);
    let parsed: any;
    try {
      parsed = JSON.parse(decoded);
    } catch {
      // Cookie might be in a non-JSON format — just verify it's not empty
      expect(decoded.length).toBeGreaterThan(5);
      return;
    }

    // After "Accept All", essential should be true
    expect(parsed.essential).toBe(true);
  });
});
