import test from 'node:test';
import assert from 'node:assert';
import http from 'node:http';
import fastify from 'fastify';

test('Proxy Health and Readiness Endpoints', async (t) => {
  const TEST_PORT = 38814;
  process.env.LARAVEL_URL = `http://127.0.0.1:${TEST_PORT}`;

  const { registerHealthRoutes } = await import('../health.js');

  // Mock Laravel Server
  let serverHandler = (req, res) => {};
  const server = http.createServer((req, res) => serverHandler(req, res));
  await new Promise((resolve) => server.listen(TEST_PORT, '127.0.0.1', resolve));

  // Boot Fastify App with health routes
  const app = fastify();
  registerHealthRoutes(app);

  t.after(async () => {
    server.close();
    await app.close();
  });

  await t.test('1. /health and /healthz return 200 OK when subscriber is fresh', async () => {
    // In test context, Redis subscriber is not connected but lastEventAt
    // defaults to Date.now() which is <60s ago, so health returns 200.
    const res1 = await app.inject({ method: 'GET', url: '/health' });
    assert.strictEqual(res1.statusCode, 200);
    const body1 = res1.json();
    assert.strictEqual(body1.status, 'ok');
    assert.ok(body1.redis_subscriber, 'Response should include redis_subscriber');

    const res2 = await app.inject({ method: 'GET', url: '/healthz' });
    assert.strictEqual(res2.statusCode, 200);
    assert.strictEqual(res2.json().status, 'ok');
  });

  await t.test('2. /readyz returns 200 OK even if Laravel returns 404', async () => {
    serverHandler = (req, res) => {
      res.writeHead(404);
      res.end();
    };

    const res = await app.inject({ method: 'GET', url: '/readyz' });
    assert.strictEqual(res.statusCode, 200);
    const body = res.json();
    assert.strictEqual(body.status, 'ok');
    assert.strictEqual(body.laravel, 'reachable');
    assert.strictEqual(body.laravel_status, 404);
  });

  await t.test('3. /readyz returns 503 if Laravel is completely unreachable', async () => {
    // Break the port so it immediately fails connection
    process.env.LARAVEL_URL = `http://127.0.0.1:38815`;

    const res = await app.inject({ method: 'GET', url: '/readyz' });
    assert.strictEqual(res.statusCode, 503);
    const body = res.json();
    assert.strictEqual(body.status, 'not_ready');
    assert.strictEqual(body.laravel, 'unreachable');
    assert.ok(body.error.includes('fetch failed') || body.error.includes('ECONNREFUSED'));
    
    // Restore for next tests if any
    process.env.LARAVEL_URL = `http://127.0.0.1:${TEST_PORT}`;
  });

});
