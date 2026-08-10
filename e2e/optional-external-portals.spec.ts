import { test, expect } from '@playwright/test';

/**
 * Optional smoke tests for infrastructure / sibling apps (not the YCookies Laravel app).
 *
 * Enable with E2E_EXTERNAL_PORTALS=1. Credentials only via environment variables — never commit secrets.
 *
 * Examples (PowerShell, local only):
 *   $env:E2E_EXTERNAL_PORTALS='1'
 *   $env:E2E_COOLIFY_URL='https://coolify.revyome.com/'
 *   $env:E2E_COOLIFY_EMAIL='...'
 *   $env:E2E_COOLIFY_PASSWORD='...'
 *   npm run test:e2e -- e2e/optional-external-portals.spec.ts
 */

const enabled = process.env.E2E_EXTERNAL_PORTALS === '1';

test.describe('External portals (optional)', () => {
  test.beforeEach(() => {
    test.skip(!enabled, 'Set E2E_EXTERNAL_PORTALS=1 to run Coolify / improve smoke tests');
  });

  test('Coolify login page loads', async ({ page }) => {
    const url = process.env.E2E_COOLIFY_URL || 'https://coolify.revyome.com/';
    const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
    expect(response).not.toBeNull();
    expect(response!.status()).toBeLessThan(500);
    await expect(page.getByRole('heading', { name: /coolify/i })).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('input[type="email"], input[name="email"]').first()).toBeVisible();
  });

  test('Coolify sign-in with env credentials', async ({ page }) => {
    const url = process.env.E2E_COOLIFY_URL || 'https://coolify.revyome.com/';
    const email = process.env.E2E_COOLIFY_EMAIL;
    const password = process.env.E2E_COOLIFY_PASSWORD;
    test.skip(!email || !password, 'Set E2E_COOLIFY_EMAIL and E2E_COOLIFY_PASSWORD for this test');

    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.locator('input[type="email"], input[name="email"]').first().fill(email);
    await page.locator('input[type="password"], input[name="password"]').first().fill(password);
    await page.getByRole('button', { name: /login/i }).click();

    await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => {});

    const path = new URL(page.url()).pathname.toLowerCase();
    const stillOnLogin = path === '/login' || path.endsWith('/login');
    expect(stillOnLogin, 'Still on Coolify login — wrong E2E_COOLIFY_* , 2FA, or UI change').toBe(false);
  });

  test('improve.ypsilon.dev responds (no server error)', async ({ request }) => {
    const url = (process.env.E2E_IMPROVE_URL || 'https://improve.ypsilon.dev/').replace(/\/$/, '');
    const res = await request.get(`${url}/`);
    expect(res.status(), `GET ${url}/ → ${res.status()}`).toBeLessThan(500);
  });
});
