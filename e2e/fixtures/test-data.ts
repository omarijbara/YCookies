/**
 * Shared test constants for YCookies Playwright E2E suite.
 *
 * Centralises URLs, selectors, and credentials so every spec
 * stays in sync when the UI or infra changes.
 */
import type { Page } from '@playwright/test';

// ── Target URLs ──────────────────────────────────────────────
/** Live example / canary site (YCookies embed). Override with E2E_EXAMPLE_DOMAIN_URL. */
export const CANARY_URL = (process.env.E2E_EXAMPLE_DOMAIN_URL || 'https://duftz.de').replace(/\/$/, '');

export const LOCAL_BASE = (process.env.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');

/** Filament admin base (no trailing slash). Override with ADMIN_URL, else BASE_URL + /admin. */
export const ADMIN_URL = (process.env.ADMIN_URL || `${LOCAL_BASE}/admin`).replace(/\/$/, '');

export const LOCAL_SITE_ID = 'site-localtest';
export const LOCAL_PROXY_HOST = 'ycookies.test';
export const PROXY_SHARED_SECRET = 'playwright-proxy-secret';

/** True when E2E targets the default local Laravel app (Dusk-style user exists). */
export const isLocalE2ETarget =
  /127\.0\.0\.1|localhost/i.test(LOCAL_BASE) || LOCAL_BASE.includes('ycookies.test');

// ── Admin credentials ────────────────────────────────────────
// Local: matches Dusk seeder. Remote (e.g. BASE_URL=https://cookies.ypsilon.dev): set E2E_ADMIN_EMAIL / E2E_ADMIN_PASSWORD — never commit real passwords.
export const ADMIN_EMAIL =
  process.env.E2E_ADMIN_EMAIL ?? (isLocalE2ETarget ? 'admin@ycookies.local' : '');
export const ADMIN_PASS =
  process.env.E2E_ADMIN_PASSWORD ?? (isLocalE2ETarget ? 'password' : '');

export function hasAdminLoginCreds(): boolean {
  return Boolean(ADMIN_EMAIL && ADMIN_PASS);
}

// ── YCookies Banner ──────────────────────────────────────────
// The consent banner is rendered inside a CLOSED Shadow DOM
// attached to #ycookies-consent-wrapper.
//
// IMPORTANT: The canary uses trigger_mode: "interaction", so
// the banner only appears AFTER user interaction (scroll/click/
// mouse move).  waitForBanner() triggers a scroll automatically.
//
// window.YCookies.manager._shadow stores the shadow root.
// All interaction must go through page.evaluate().
export const WRAPPER_ID = 'ycookies-consent-wrapper';
export const REOPEN_WIDGET_ID = 'ycookies-reopen-widget';

// ── Consent cookie name ──────────────────────────────────────
export const CONSENT_COOKIE = 'ycookies_consent';

// ── Content blocker selectors (main DOM) ─────────────────────
export const CONTENT_BLOCKER_CLASS = 'ycookies-content-blocker';
export const BLOCKED_SCRIPT_SELECTOR = 'script[data-ycookies-blocked="true"]';

// ── Known blocked third-party domains ────────────────────────
export const BLOCKED_DOMAINS = [
  'google-analytics.com',
  'googletagmanager.com',
  'facebook.net',
  'connect.facebook.net',
  'doubleclick.net',
  'analytics.tiktok.com',
];

// ── API route patterns ───────────────────────────────────────
export const API = {
  config: (siteId: string) => `/api/config/${siteId}`,
  script: (siteId: string) => `/api/script/${siteId}.js`,
  boot: (siteId: string) => `/api/boot/${siteId}.js`,
  logConsent: '/api/log-consent',
} as const;

// ═══════════════════════════════════════════════════════════════
// Shadow DOM Helpers
// ═══════════════════════════════════════════════════════════════

/**
 * Wait for the YCookies banner to appear.
 *
 * Since the canary uses trigger_mode: "interaction", we need to
 * trigger a user interaction first (scroll), then wait for the
 * wrapper element to appear and the overlay to become visible.
 */
export async function waitForBanner(page: Page, timeout = 20_000): Promise<boolean> {
  try {
    // Step 1: Wait for the YCookies manager to be initialized
    // (config fetched and event listeners registered)
    await page.waitForFunction(() => {
      return !!(window as any).YCookies?.manager?.config;
    }, { timeout });

    // Step 2: Trigger user interaction — the config uses trigger_mode: "interaction"
    // which requires scroll/click/keydown/mousemove/touchstart.
    // We use a polling approach: dispatch events repeatedly until the banner appears.
    const startTime = Date.now();
    while (Date.now() - startTime < timeout) {
      // Dispatch interaction events
      await page.evaluate(() => {
        document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, clientX: 100, clientY: 100 }));
        document.dispatchEvent(new KeyboardEvent('keydown', { bubbles: true, key: 'Shift' }));
        window.dispatchEvent(new Event('scroll'));
        document.dispatchEvent(new MouseEvent('click', { bubbles: true, clientX: 50, clientY: 50 }));
      });
      await page.mouse.move(300 + Math.random() * 50, 300 + Math.random() * 50);

      // Check if banner appeared
      const wrapperExists = await page.locator(`#${WRAPPER_ID}`).count();
      if (wrapperExists > 0) {
        // Wait for overlay animation
        const visible = await page.evaluate(() => {
          const mgr = (window as any).YCookies?.manager;
          if (!mgr?._shadow) return false;
          const overlay = mgr._shadow.getElementById('yc-overlay');
          return overlay?.classList.contains('yc-visible') ?? false;
        });
        if (visible) return true;
      }

      await page.waitForTimeout(500);
    }
    return false;
  } catch {
    return false;
  }
}

/**
 * Check if the YCookies overlay is currently visible.
 */
export async function isOverlayVisible(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const mgr = (window as any).YCookies?.manager;
    if (!mgr?._shadow) return false;
    const overlay = mgr._shadow.getElementById('yc-overlay');
    return overlay?.classList.contains('yc-visible') ?? false;
  });
}

/**
 * Click a button inside the YCookies closed Shadow DOM.
 */
export async function clickShadowButton(page: Page, buttonId: string): Promise<boolean> {
  return page.evaluate((id: string) => {
    const mgr = (window as any).YCookies?.manager;
    if (!mgr?._shadow) return false;
    const btn = mgr._shadow.getElementById(id);
    if (btn) { btn.click(); return true; }
    return false;
  }, buttonId);
}

/**
 * Get text content of an element inside the shadow DOM.
 */
export async function getShadowText(page: Page, selector: string): Promise<string> {
  return page.evaluate((sel: string) => {
    const mgr = (window as any).YCookies?.manager;
    if (!mgr?._shadow) return '';
    const el = mgr._shadow.querySelector(sel);
    return el?.textContent?.trim() ?? '';
  }, selector);
}

/**
 * Count elements matching a selector inside the shadow DOM.
 */
export async function countShadowElements(page: Page, selector: string): Promise<number> {
  return page.evaluate((sel: string) => {
    const mgr = (window as any).YCookies?.manager;
    if (!mgr?._shadow) return 0;
    return mgr._shadow.querySelectorAll(sel).length;
  }, selector);
}

/**
 * Clean all YCookies state so a fresh test starts clean.
 */
export async function clearYCookiesState(page: Page): Promise<void> {
  await page.evaluate(() => {
    // Clear localStorage
    localStorage.removeItem('ycookies_consent_version');
    localStorage.removeItem('ycookies_consent_uid');
    // Clear indexedDB if any
    try {
      indexedDB.deleteDatabase('ycookies');
    } catch { /* ignore */ }
  });
}
