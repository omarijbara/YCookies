import test from 'node:test';
import assert from 'node:assert';
import http from 'node:http';
import { createHmac } from 'node:crypto';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';

// Override env vars before importing config-resolver.js
process.env.PROXY_SHARED_SECRET = 'test-secret';
process.env.CONFIG_SNAPSHOT_DIR = join(process.cwd(), '.test-snapshots');
const TEST_PORT = 38812;
process.env.LARAVEL_URL = `http://127.0.0.1:${TEST_PORT}`;
// We use a dummy port so the native redis client fails instantly or we intercept it
process.env.REDIS_URL = 'redis://127.0.0.1:38813'; 

import Redis from 'ioredis';

// Mock Redis directly via Prototype BEFORE anything connects
const originalRedisGet = Redis.prototype.get;

let passed = 0;
let failed = 0;

// Setup disk snapshots
try { rmSync(process.env.CONFIG_SNAPSHOT_DIR, { recursive: true, force: true }); } catch (e) {}
mkdirSync(process.env.CONFIG_SNAPSHOT_DIR, { recursive: true });

// Setup mock Laravel HTTP Server
let serverHandler = (req, res) => {};
const server = http.createServer((req, res) => serverHandler(req, res));
await new Promise((resolve) => server.listen(TEST_PORT, '127.0.0.1', resolve));

try {
  const { getDomainConfig } = await import('../config-resolver.js');

  // Test 1
  try {
    serverHandler = (req, res) => { res.writeHead(500); res.end('Internal Server Error'); };
    Redis.prototype.get = async (key) => {
      if (key === 'proxy_cfg:redis-fallback.test') return JSON.stringify({ revision: '1:abc', source: 'redis-mock' });
      return null;
    };
    const config = await getDomainConfig('redis-fallback.test');
    assert.strictEqual(config.source, 'redis-mock');
    assert.strictEqual(config.revision, '1:abc');
    passed++;
  } catch (err) { failed++; console.log(err); }

  // Test 2
  try {
    serverHandler = (req, res) => { res.writeHead(500); res.end(); };
    Redis.prototype.get = async (key) => null;
    const mockDisk = { revision: '1:disk', source: 'disk-mock' };
    writeFileSync(join(process.env.CONFIG_SNAPSHOT_DIR, 'disk-fallback.test.json'), JSON.stringify(mockDisk), 'utf8');
    const config = await getDomainConfig('disk-fallback.test');
    assert.strictEqual(config.source, 'disk-mock');
    assert.strictEqual(config.revision, '1:disk');
    passed++;
  } catch (err) { failed++; console.log(err); }

  // Test 3
  try {
    serverHandler = (req, res) => {
      const payload = JSON.stringify({ revision: '2:bad-hmac' });
      const badHash = createHmac('sha256', 'wrong-secret').update(payload).digest('base64');
      res.writeHead(200, { 'Content-Type': 'application/json', 'x-yc-signature': badHash });
      res.end(payload);
    };
    Redis.prototype.get = async () => null;
    try {
        await getDomainConfig('hmac-fail.test');
        failed++;
        console.log('Should have thrown an Error on HMAC mismatch');
    } catch (err) {
        assert.ok(err.message.includes('HMAC signature mismatch') || err.message.includes('Missing X-Signature header'));
        passed++;
    }
  } catch (err) { failed++; console.log(err); }

} finally {
  server.close();
  try { rmSync(process.env.CONFIG_SNAPSHOT_DIR, { recursive: true, force: true }); } catch (e) {}
  
  console.log(`\n${passed} passed, ${failed} failed\n`);
  setTimeout(() => process.exit(failed > 0 ? 1 : 0), 100);
}

