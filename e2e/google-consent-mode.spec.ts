// @ts-nocheck
import { test, expect } from '@playwright/test';
import { LOCAL_BASE, waitForBanner, clickShadowButton, clearYCookiesState } from './fixtures/test-data';

test.describe('Google Consent Mode v2', () => {

  test.beforeEach(async ({ page }) => {

    await page.goto(`${LOCAL_BASE}/test-gcm`, { waitUntil: 'domcontentloaded' });
    await clearYCookiesState(page);
    await page.context().clearCookies();
  });

  test('verifies strictly denied defaults on first load', async ({ page }) => {
    // Inject a listener before anything loads to capture dataLayer pushes
    await page.addInitScript(() => {
        const win = window;
        win.dataLayer = win.dataLayer || [];
        const originalPush = win.dataLayer.push;
        win.dataLayer.push = function(...args) {
            win._dispatchedDataLayerPushes = win._dispatchedDataLayerPushes || [];
            win._dispatchedDataLayerPushes.push(args[0]);
            return originalPush.apply(win.dataLayer, args);
        };
    });

    await page.goto(`${LOCAL_BASE}/test-gcm`, { waitUntil: 'domcontentloaded' });
    
    // Wait for the banner config/init
    await page.waitForFunction(() => !!window.YCookies?.manager?.config, { timeout: 15000 });

    // Retrieve dataLayer content 
    const pushes = await page.evaluate(() => window._dispatchedDataLayerPushes || []);
    
    // Find GCM default call
    const consentDefault = pushes.find((push: any) => 
       Array.isArray(push) && push[0] === 'consent' && push[1] === 'default'
    ) || pushes.find((push: any) => 
       push && push['0'] === 'consent' && push['1'] === 'default'
    );

    expect(consentDefault, 'Expected GCM default initialization').toBeTruthy();
    
    // Convert arguments object back to standard object for expectation matching
    const consentPayload = Array.isArray(consentDefault) ? consentDefault[2] : consentDefault['2'];

    expect(consentPayload).toMatchObject({
        ad_storage: 'denied',
        analytics_storage: 'denied',
    });
  });

  test('verifies GCM update triggered after consent acceptance', async ({ page }) => {
    // Inject a listener before anything loads to capture dataLayer pushes
    await page.addInitScript(() => {
        const win = window;
        win.dataLayer = win.dataLayer || [];
        const originalPush = win.dataLayer.push;
        win.dataLayer.push = function(...args) {
            win._dispatchedDataLayerPushes = win._dispatchedDataLayerPushes || [];
            win._dispatchedDataLayerPushes.push(args[0]);
            return originalPush.apply(win.dataLayer, args);
        };
    });

    await page.goto(`${LOCAL_BASE}/test-gcm`, { waitUntil: 'domcontentloaded' });
    
    // YCookies usually uses 'interaction' trigger_mode on canary, so use the helper
    const isBannerVisible = await waitForBanner(page);
    expect(isBannerVisible, 'Banner did not open after interaction').toBe(true);

    // Accept cookies using Shadow DOM helper
    const clicked = await clickShadowButton(page, 'yc-btn-accept');
    expect(clicked, 'Accept All button click failed').toBe(true);
    
    // Give it a split second to save consent and fire dataLayer
    await page.waitForTimeout(500);

    // Capture pushes again
    const pushes = await page.evaluate(() => window._dispatchedDataLayerPushes || []);

    // Try to find the Consent Update
    const consentUpdate = pushes.find((push: any) => 
       Array.isArray(push) && push[0] === 'consent' && push[1] === 'update'
    ) || pushes.find((push: any) => 
       push && push['0'] === 'consent' && push['1'] === 'update'
    );

    console.log("Captured DataLayer Pushes:", JSON.stringify(pushes, null, 2));

    expect(consentUpdate, 'Expected GCM update after consent acceptance').toBeTruthy();

    const consentPayload = Array.isArray(consentUpdate) ? consentUpdate[2] : consentUpdate['2'];
    // We expect them to be granted now
    expect(consentPayload.ad_storage).toBe('granted');
    expect(consentPayload.analytics_storage).toBe('granted');
  });
});
