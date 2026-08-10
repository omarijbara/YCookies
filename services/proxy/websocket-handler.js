import { randomUUID } from 'node:crypto';
import WebSocket from 'ws';
import { getDomainConfig } from './config-resolver.js';
import { filterRequestHeaders } from './headers.js';
import { enforceDomainRateLimit } from './rate-limit-engine.js';

export function buildWsHandler(checkDomainRateLimit) {
  return async (connection, request) => {
    const requestId = randomUUID();
    const hostname = request.hostname;
    
    // CRITICAL: @fastify/websocket drops messages received during async gaps.
    // We MUST attach the message handler synchronously before any await.
    const messageQueue = [];
    let upstreamWs = null;
    
    const WS_BUFFER_LIMIT = 50;
    
    connection.on('message', (msg) => {
        if (upstreamWs && upstreamWs.readyState === WebSocket.OPEN) {
            upstreamWs.send(msg);
        } else {
            if (messageQueue.length >= WS_BUFFER_LIMIT) {
                request.log.warn({ requestId, hostname }, 'WS message buffer limit exceeded, dropping connection');
                connection.close(1013, 'Try again later');
                return;
            }
            messageQueue.push(msg);
        }
    });

    // 1. Fail-closed host lookup (async — messages buffered above)
    let config;
    try {
      config = await getDomainConfig(hostname);
    } catch (err) {
      request.log.error({ requestId, hostname, err: err.message }, 'WS Config lookup failed');
      connection.close(1011, 'Config verification failed');
      return;
    }
    
    if (!config || !config.origin || !config.origin.host) {
      connection.close(1008, 'Domain not configured');
      return;
    }

    const urlPath = new URL(request.raw.url, `https://${hostname}`).pathname;
    
    // Rate limits (WebSocket connections)
    const rateLimitAllowed = await enforceDomainRateLimit(request, { 
      header: () => {}, code: () => ({ type: () => ({ send: () => {} }) }) 
    }, config, urlPath, checkDomainRateLimit);

    if (!rateLimitAllowed) {
      request.log.warn({ requestId, hostname }, 'WS rate limit exceeded');
      connection.close(1013, 'Rate limit exceeded');
      return;
    }

    // 2. Setup Upstream Connection
    const rawHost = config.origin.host.replace(/^https?:\/\//, '');
    const targetScheme = config.origin.port === 443 || config.origin.host.startsWith('https') ? 'wss' : 'ws';
    const wsUrl = `${targetScheme}://${rawHost}${request.raw.url}`;
    
    request.log.info({ requestId, wsUrl }, 'Proxying WebSocket connection');
    
    const safeHeaders = filterRequestHeaders(request.headers, request.ip, hostname);
    
    upstreamWs = new WebSocket(wsUrl, {
       headers: { ...safeHeaders, host: rawHost }
    });

    // 3. Once upstream is open, drain any buffered messages
    upstreamWs.on('open', () => {
        request.log.info({ requestId, wsUrl }, 'Upstream WS connected');
        while (messageQueue.length > 0) {
            upstreamWs.send(messageQueue.shift());
        }
    });

    upstreamWs.on('message', (msg) => {
        if (connection.readyState === WebSocket.OPEN) {
            connection.send(msg);
        }
    });

    // 4. Teardown / Error Propagation
    connection.on('close', () => {
        request.log.info({ requestId }, 'Client closed WS connection');
        if (upstreamWs.readyState === WebSocket.OPEN || upstreamWs.readyState === WebSocket.CONNECTING) {
            upstreamWs.close();
        }
    });

    upstreamWs.on('close', () => {
        request.log.info({ requestId, wsUrl }, 'Upstream closed WS connection');
        if (connection.readyState === WebSocket.OPEN || connection.readyState === WebSocket.CONNECTING) {
            connection.close();
        }
    });

    connection.on('error', (err) => {
        request.log.error({ requestId, err: err.message }, 'Client WS error');
        upstreamWs.terminate();
    });

    upstreamWs.on('error', (err) => {
        request.log.error({ requestId, wsUrl, err: err.message }, 'Upstream WS error');
        connection.terminate();
    });
  };
}
