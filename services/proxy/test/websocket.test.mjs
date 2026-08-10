import test from 'node:test';
import assert from 'node:assert';
import WebSocket, { WebSocketServer } from 'ws';
import Fastify from 'fastify';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { createHmac } from 'node:crypto';

const __dirname = dirname(fileURLToPath(import.meta.url));
const TEST_SECRET = 'test-hmac-secret';

test('WebSocket Proxy Pass-through', async (t) => {
  // 1. Start mocked origin WebSocket server
  const originPort = 30001;
  const wss = new WebSocketServer({ port: originPort });
  
  let originConnected = false;
  let receivedFromClient = null;
  
  wss.on('connection', (ws, req) => {
    console.log('Origin: New connection from', req.url);
    originConnected = true;
    ws.on('message', (msg) => {
      console.log('Origin: Received message:', msg.toString());
      receivedFromClient = msg.toString();
      ws.send('hello from origin');
    });
  });

  // 2. Start mocked Laravel API server (with HMAC signing)
  const apiPort = 30002;
  const api = Fastify();
  api.get('/api/proxy-config/test-ws.com', async (request, reply) => {
    const body = JSON.stringify({
      domain: 'test-ws.com',
      origin: { host: `127.0.0.1:${originPort}`, ip: '127.0.0.1', port: originPort },
      cookie_policy: { whitelist: [] },
      script_blockers: [],
      content_blockers: [],
      revision: 'v1'
    });
    const signature = createHmac('sha256', TEST_SECRET).update(body).digest('hex');
    reply.header('x-signature', signature);
    reply.header('content-type', 'application/json');
    return reply.send(body);
  });
  await api.listen({ port: apiPort, host: '127.0.0.1' });

  // 3. Start proxy server
  const proxyPort = 30003;
  const proxy = spawn('node', ['server.js'], {
    env: {
      ...process.env,
      PROXY_PORT: proxyPort,
      LARAVEL_URL: `http://127.0.0.1:${apiPort}`,
      PROXY_SHARED_SECRET: TEST_SECRET,
      LOG_LEVEL: 'info'
    },
    cwd: join(__dirname, '..')
  });
  proxy.stdout.pipe(process.stdout);
  proxy.stderr.pipe(process.stderr);

  // wait for proxy to start
  await new Promise(resolve => setTimeout(resolve, 3000));

  // 4. Connect client to proxy
  let clientWs;
  try {
    clientWs = new WebSocket(`ws://127.0.0.1:${proxyPort}/socket`, {
      headers: { host: 'test-ws.com' }
    });

    await new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error('Timeout waiting for message')), 5000);
      clientWs.on('open', () => {
        console.log('Client connected. Sending message...');
        clientWs.send('hello from client');
      });
      clientWs.on('message', (msg) => {
        console.log('Client received:', msg.toString());
        clearTimeout(timeout);
        try {
          assert.strictEqual(msg.toString(), 'hello from origin');
          resolve();
        } catch (e) {
          reject(e);
        }
      });
      clientWs.on('error', (err) => {
        console.log('Client WS error:', err);
        clearTimeout(timeout);
        reject(err);
      });
      clientWs.on('close', (code, reason) => {
        console.log('Client WS closed:', code, reason.toString());
        clearTimeout(timeout);
        reject(new Error(`WS closed unexpectedly: ${code} ${reason.toString()}`));
      });
    });

    assert.ok(originConnected, 'Origin server received WS connection');
    assert.strictEqual(receivedFromClient, 'hello from client');
  } finally {
    if (clientWs) clientWs.close();
    // Teardown
    proxy.kill();
    await api.close();
    wss.close();
  }
});
