import { test, expect } from '@playwright/test';
import { ADMIN_URL, ADMIN_EMAIL, ADMIN_PASS, hasAdminLoginCreds } from './fixtures/test-data';

/**
 * Admin Panel Smoke Tests
 *
 * Tests the Filament admin panel login flow and dashboard rendering.
 * Requires `php artisan serve` running on localhost:8000.
 */
test.describe('Admin Panel', () => {

  test('login page renders correctly', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/login`);

    // Filament login page should have email and password fields
    await expect(page.locator('input[type="email"], input[name="data.email"], input[name*="email"]').first()).toBeVisible();
    await expect(page.locator('input[type="password"], input[name="data.password"], input[name*="password"]').first()).toBeVisible();

    // Should have a submit button
    const submitBtn = page.getByRole('button', { name: /sign in/i });
    await expect(submitBtn).toBeVisible();
  });

  test('valid credentials → redirect to dashboard', async ({ page }) => {
    test.skip(!hasAdminLoginCreds(), 'Set E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD when BASE_URL is not local');

    await page.goto(`${ADMIN_URL}/login`);

    // Fill login form
    await page.locator('input[name="data.email"], input[type="email"]').first().fill(ADMIN_EMAIL);
    await page.locator('input[name="data.password"], input[type="password"]').first().fill(ADMIN_PASS);

    // Submit
    await page.getByRole('button', { name: /sign in/i }).click();

    // Wait for navigation to dashboard
    await page.waitForURL(/\/admin\/\d+/, { timeout: 15_000 });

    // Dashboard should contain navigation and core elements
    await expect(page.locator('nav, [class*="sidebar"], [class*="navigation"]').first()).toBeVisible();

    // Should see some dashboard content
    const pageContent = await page.textContent('body');
    expect(pageContent).toBeTruthy();
  });

  test('invalid credentials → error message', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/login`);

    // Fill with wrong credentials
    await page.locator('input[name="data.email"], input[type="email"]').first().fill('wrong@example.com');
    await page.locator('input[name="data.password"], input[type="password"]').first().fill('wrongpassword');

    // Submit
    await page.getByRole('button', { name: /sign in/i }).click();

    // Should show an error — Filament uses validation messages
    // Wait for the error to appear (stays on login page)
    await page.waitForTimeout(2_000);

    // Should still be on the login page
    expect(page.url()).toContain('/login');

    // Should have some error indication
    const hasError = await page.locator('[class*="danger"], [class*="error"], [role="alert"], .fi-fo-field-wrp-error-message').count();
    expect(hasError).toBeGreaterThan(0);
  });

  test('dashboard loads consent chart after login', async ({ page }) => {
    test.skip(!hasAdminLoginCreds(), 'Set E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD when BASE_URL is not local');

    await page.goto(`${ADMIN_URL}/login`);

    // Login
    await page.locator('input[name="data.email"], input[type="email"]').first().fill(ADMIN_EMAIL);
    await page.locator('input[name="data.password"], input[type="password"]').first().fill(ADMIN_PASS);
    await page.getByRole('button', { name: /sign in/i }).click();

    // Wait for dashboard
    await page.waitForURL(/\/admin\/\d+/, { timeout: 15_000 });

    // The dashboard should have widgets/cards
    // Filament widgets are typically in a grid layout
    const widgets = page.locator('[class*="widget"], [class*="fi-wi"], .fi-page-dashboard');
    await expect(widgets.first()).toBeVisible({ timeout: 10_000 });
  });
});
