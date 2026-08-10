import { createHmac } from 'node:crypto';
import { test, expect } from '@playwright/test';
import {
  LOCAL_BASE,
  LOCAL_PROXY_HOST,
  PROXY_SHARED_SECRET,
} from './fixtures/test-data';

test.describe('Local Proxy Config', () => {
  test('healthcheck is publicly reachable', async ({ request }) => {
    const response = await request.get(`${LOCAL_BASE}/api/proxy-config/healthcheck`);

    expect(response.status()).toBe(204);
  });

  test('unsigned proxy-config requests are rejected', async ({ request }) => {
    const response = await request.get(`${LOCAL_BASE}/api/proxy-config/${LOCAL_PROXY_HOST}`);

    expect(response.status()).toBe(401);
    await expect(response.json()).resolves.toMatchObject({
      error: 'Missing X-Proxy-Signature header',
    });
  });

  test('invalid proxy-config signatures are rejected', async ({ request }) => {
    const response = await request.get(`${LOCAL_BASE}/api/proxy-config/${LOCAL_PROXY_HOST}`, {
      headers: {
        'X-Proxy-Signature': '0'.repeat(64),
      },
    });

    expect(response.status()).toBe(403);
    await expect(response.json()).resolves.toMatchObject({
      error: 'Invalid proxy signature',
    });
  });

  test('valid proxy-config signatures return domain config', async ({ request }) => {
    const signature = createHmac('sha256', PROXY_SHARED_SECRET)
      .update(LOCAL_PROXY_HOST)
      .digest('hex');

    const response = await request.get(`${LOCAL_BASE}/api/proxy-config/${LOCAL_PROXY_HOST}`, {
      headers: {
        'X-Proxy-Signature': signature,
      },
    });

    expect(response.status()).toBe(200);
    expect(response.headers()['etag']).toBeDefined();
    expect(response.headers()['x-signature']).toBeDefined();

    const body = await response.json();
    expect(body.domain).toBe(LOCAL_PROXY_HOST);
    expect(body.origin.auth_token).toBe('playwright-origin-token');
    expect(body.origin.url).toBe('https://origin.ycookies.test');
    expect(body.proxy.status).toBe('active');
  });
});
