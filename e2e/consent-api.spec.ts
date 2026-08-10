import { test, expect } from '@playwright/test';
import { API, LOCAL_BASE, LOCAL_SITE_ID } from './fixtures/test-data';

/**
 * Consent API Endpoint Smoke Tests
 *
 * Tests the Laravel API endpoints against the local dev server.
 * Requires `php artisan serve` running on localhost:8000.
 */
test.describe('Consent API', () => {
  test('GET /api/config/{site_id} returns valid consent config JSON', async ({ request }) => {
    const response = await request.get(`${LOCAL_BASE}${API.config(LOCAL_SITE_ID)}`);
    expect(response.status()).toBe(200);

    const ct = response.headers()['content-type'] ?? '';
    expect(ct).toContain('json');

    const body = await response.json();

    // Must contain essential structure
    expect(body).toHaveProperty('cookie_groups');
    expect(Array.isArray(body.cookie_groups)).toBe(true);
    expect(body.cookie_groups.length).toBeGreaterThan(0);
  });

  test('GET /api/script/{site_id}.js returns JavaScript', async ({ request }) => {
    const response = await request.get(`${LOCAL_BASE}${API.script(LOCAL_SITE_ID)}`);
    expect(response.status()).toBe(200);

    const ct = response.headers()['content-type'] ?? '';
    expect(ct).toMatch(/javascript|ecmascript/);

    const body = await response.text();
    expect(body.length).toBeGreaterThan(100); // Not an empty stub
  });

  test('GET /api/boot/{site_id}.js returns bootstrapper JS', async ({ request }) => {
    const response = await request.get(`${LOCAL_BASE}${API.boot(LOCAL_SITE_ID)}`);
    expect(response.status()).toBe(200);

    const ct = response.headers()['content-type'] ?? '';
    expect(ct).toMatch(/javascript|ecmascript/);
  });

  test('POST /api/log-consent accepts consent payload', async ({ request }) => {
    const payload = {
      site_id: LOCAL_SITE_ID,
      consent: {
        type: 'all',
        groups: {
          essential: true,
          statistics: true,
          marketing: false,
        },
        services: [],
      },
      uid: 'playwright-e2e-user',
    };

    const response = await request.post(`${LOCAL_BASE}${API.logConsent}`, {
      data: payload,
    });

    // Accept 200, 201, or 204 as success
    expect([200, 201, 204]).toContain(response.status());
  });

  test('GET /api/config/INVALID_ID returns 404', async ({ request }) => {
    const response = await request.get(
      `${LOCAL_BASE}${API.config('nonexistent_site_id_999')}`
    );
    expect(response.status()).toBe(404);
  });

  test('GET /api/script/INVALID_ID.js returns 404', async ({ request }) => {
    const response = await request.get(
      `${LOCAL_BASE}${API.script('nonexistent_site_id_999')}`
    );
    expect(response.status()).toBe(404);
  });
});
