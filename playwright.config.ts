import { defineConfig, devices } from '@playwright/test';
import { config as loadEnv } from 'dotenv';

// Local secrets for Playwright (never commit): copy .env.e2e.example → .env.e2e
loadEnv({ path: '.env.e2e' });

const localBaseUrl = process.env.BASE_URL || 'http://127.0.0.1:8000';
const useWebServer = process.env.PLAYWRIGHT_NO_WEBSERVER !== '1';

/**
 * Playwright E2E Configuration for YCookies
 *
 * Two test targets:
 *  1. Local Laravel app (http://localhost:8000) — API & admin tests
 *  2. Live canary proxy (https://duftz.de)     — proxy header & consent tests
 *
 * Run:
 *   npm run test:e2e          — headless, all browsers
 *   npm run test:e2e:ui       — interactive UI mode
 *   npm run test:e2e:headed   — headed (visible browser)
 *
 * Remote admin + canary: put vars in .env.e2e (see .env.e2e.example), or export them.
 *   PLAYWRIGHT_NO_WEBSERVER=1 BASE_URL=https://cookies.ypsilon.dev E2E_ADMIN_EMAIL=… E2E_ADMIN_PASSWORD=…
 * Example domain defaults to https://duftz.de (override: E2E_EXAMPLE_DOMAIN_URL).
 * Optional Coolify / improve: E2E_EXTERNAL_PORTALS=1 plus E2E_COOLIFY_* / E2E_IMPROVE_URL (see e2e/optional-external-portals.spec.ts).
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 1,
  /* Limit workers to avoid overwhelming the live canary proxy */
  workers: 1,
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],
  timeout: 60_000,
  expect: {
    timeout: 10_000,
  },

  use: {
    /* Local Laravel app */
    baseURL: localBaseUrl,

    /* Collect trace & video on first retry for debugging */
    trace: 'on-first-retry',
    video: 'on-first-retry',
    screenshot: 'only-on-failure',

    /* Extra HTTP headers for API tests */
    extraHTTPHeaders: {
      'Accept': 'application/json',
    },
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'firefox',
      use: { ...devices['Desktop Firefox'] },
    },
    {
      name: 'webkit',
      use: { ...devices['Desktop Safari'] },
    },
    {
      name: 'mobile-chrome',
      use: { ...devices['Pixel 5'] },
    },
    {
      name: 'mobile-safari',
      use: { ...devices['iPhone 12'] },
    },
  ],

  webServer: useWebServer ? {
    command: 'powershell -NoProfile -ExecutionPolicy Bypass -File scripts/playwright/start-local.ps1',
    url: `${localBaseUrl}/up`,
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  } : undefined,
});
