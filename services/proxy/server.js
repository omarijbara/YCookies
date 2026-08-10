/**
 * YCookies Proxy Service — Fastify Entry Point
 *
 * Phase 1: Minimal streaming proxy that:
 * - Resolves domain config from Laravel (fail-closed on unknown host)
 * - Streams non-HTML responses directly (passthrough)
 * - Injects consent bootstrapper into HTML responses
 * - Adds structured logging + request IDs
 */

import 'dotenv/config';

// ── Sentry error tracking (must init before other imports) ───────
import * as Sentry from '@sentry/node';
if (process.env.SENTRY_NODE_DSN) {
  Sentry.init({
    dsn: process.env.SENTRY_NODE_DSN,
    environment: process.env.NODE_ENV || 'production',
    tracesSampleRate: 0.1,  // 10% of requests for perf traces
    beforeSend(event) {
      // Don't send health-check noise
      if (event.request?.url?.includes('/health')) return null;
      if (event.request?.url?.includes('/metrics')) return null;
      return event;
    },
  });
  console.log('[sentry] Error tracking enabled');
}

import Fastify from 'fastify';
import { request as undiciRequest } from 'undici';
import { applyDecompressor } from './decompressor.js';
import { registerGracefulShutdown } from './shutdown.js';
import { sendProxyError, PROXY_ERRORS } from './error-responses.js';
import { buildProxyHandler } from './request-pipeline.js';
import { buildWsHandler } from './websocket-handler.js';
import { isInternalProxyIp, resolveRateLimitKey, DOCKER_INTERNAL } from './rate-limit-engine.js';

import { getDomainConfig, preWarmFromDisk, invalidateConfig } from './config-resolver.js';
import { createHtmlInjector } from './html-injector.js';
import { createBlockerStream } from './html-blocker-stream.js';
import crypto from 'node:crypto';

const REVALIDATE_SECRET = crypto.randomUUID();
import { generateNonce, mergeNonce, mergeConnectSrc } from './csp-nonce.js';
import { createNonceReplaceStream } from './csp-replace-stream.js';
import { filterRequestHeaders, filterResponseHeaders, HOP_BY_HOP } from './headers.js';
import { filterCookies } from './cookie-filter.js';
import { assertPublicIP, assertPublicUrl, assertPublicHostname } from './ssrf.js';
import { registerHealthRoutes } from './health.js';
import { initRedisSubscriber } from './redis-subscriber.js';
import { getOrCreateAgent, destroyAgent } from './agent-pool.js';
import { evaluateEligibility, evaluateRequestEligibility, recordDecision, setCacheHeaders } from './response-cache.js';
import { initCacheStore, getCache, setCache } from './cache-store.js';
import { recordMetric, startFlushTimer, stopAndDrain } from './metrics.js';
import { inc } from './proxy-counters.js';
import { shouldTransform, recordSuccess, recordFailure, getCircuitStats, setCircuitBreakerLogger } from './circuit-breaker.js';
import rateLimit from '@fastify/rate-limit';
import websocket from '@fastify/websocket';
import metricsPlugin from 'fastify-metrics';
import { reportError, startErrorFlush, stopErrorFlush } from './error-reporter.js';
import WebSocket from 'ws';
import { randomUUID } from 'node:crypto';
import {
  DEFAULT_RATE_LIMIT_MAX_REQUESTS_PER_MINUTE,
  normalizeRateLimitConfig,
  shouldBypassRateLimit,
} from './rate-limit-policy.js';
import { resolveManifestConfig, applyManifestOverrides } from './manifest-consumer.js';

const PORT = parseInt(process.env.PROXY_PORT || '8080', 10);
const UPSTREAM_REQUEST_TIMEOUT_MS = parseInt(process.env.UPSTREAM_REQUEST_TIMEOUT_MS || '20000', 10);

const fastify = Fastify({
  logger: {
    level: process.env.LOG_LEVEL || 'info',
    ...(process.env.NODE_ENV !== 'production' && {
      transport: { target: 'pino-pretty' },
    }),
  },
  // trustProxy: true is safe because the proxy runs exclusively behind Traefik
  // inside Docker. Only Traefik connects to this port. If the proxy were ever
  // exposed directly, this would need to be restricted to a CIDR range.
  trustProxy: true,
  disableRequestLogging: true, // We log ourselves for cleaner output
  connectionTimeout: 120000,   // 2 min max connection hold (prevents slot exhaustion)
  requestTimeout: 90000,       // 90s max request processing time
});

// Process-level safety net — catch crashes that slip through async error handling.
// Without these, a SINGLE unhandled rejection kills the proxy → all domains go 502.
process.on('uncaughtException', (err) => {
  fastify.log.fatal({ err: err.message, stack: err.stack }, 'Uncaught exception — process alive');
  reportError(err, { level: 'critical' });
});
process.on('unhandledRejection', (reason) => {
  const err = reason instanceof Error ? reason : new Error(String(reason));
  fastify.log.error({ reason: String(reason) }, 'Unhandled rejection — process alive');
  reportError(err, { level: 'error' });
});

// Disable Fastify's default content-type parsing — we pass bodies raw
fastify.removeAllContentTypeParsers();
fastify.addContentTypeParser('*', { parseAs: 'buffer' }, (_req, body, done) => {
  done(null, body);
});

// Register health endpoints
registerHealthRoutes(fastify);

// Register Prometheus metrics endpoint at /metrics
await fastify.register(metricsPlugin, {
  endpoint: '/metrics',
  routeMetrics: {
    enabled: true,
    overrides: {
      histogram: { buckets: [0.01, 0.05, 0.1, 0.5, 1, 3, 5, 10, 30] },
    },
  },
});

// Register the Fastify rate-limit engine in manual mode.
// Per-domain rate limiting is enforced later in proxyHandler after the
// authoritative Laravel config has been loaded for the request hostname.
// This keeps rate-limit behavior configurable per domain and per path.
await fastify.register(rateLimit, {
  global: false,
  max: DEFAULT_RATE_LIMIT_MAX_REQUESTS_PER_MINUTE,
  timeWindow: '1 minute',
  allowList: (request) => isInternalProxyIp(request),
  keyGenerator: resolveRateLimitKey,
  skipOnError: true,
});

const checkDomainRateLimit = fastify.createRateLimit({
  max: async (request) => {
    const policy = normalizeRateLimitConfig(request.ycRateLimitConfig);
    return policy.maxRequestsPerMinute === -1
      ? DEFAULT_RATE_LIMIT_MAX_REQUESTS_PER_MINUTE
      : policy.maxRequestsPerMinute;
  },
  allowList: async (request) => {
    if (isInternalProxyIp(request)) {
      return true;
    }

    return shouldBypassRateLimit(request.ycRateLimitPath || '/', request.ycRateLimitConfig, request);
  },
  keyGenerator: resolveRateLimitKey,
  skipOnError: true,
});

// Register WebSocket plugin — clean 426 for WS upgrades instead of silent failure
await fastify.register(websocket);

// Protect observability endpoints — internal Docker network only
// /metrics (Prometheus) and /statsz (cache/memory stats) expose server internals
// that should never be accessible from the public internet.
const INTERNAL_PREFIXES = ['10.', '172.', '192.168.', '127.0.0.1', '::1', '::ffff:10.', '::ffff:172.', '::ffff:192.168.', '::ffff:127.0.0.1'];
const INTERNAL_ONLY_ROUTES = new Set(['/metrics', '/statsz']);

fastify.addHook('onRequest', async (request, reply) => {
  if (INTERNAL_ONLY_ROUTES.has(request.url) || request.url.startsWith('/internal/')) {
    const ip = request.ip;
    const isInternal = INTERNAL_PREFIXES.some(prefix => ip.startsWith(prefix));
    const hasSecret = request.headers['x-yc-internal-secret'] === REVALIDATE_SECRET;
    
    if (!isInternal && !hasSecret) {
      reply.code(404).send({ error: 'Not Found' });
      return;
    }
  }
});

// Runtime Config Hot-Reload
fastify.route({
  method: 'POST',
  url: '/internal/config/:hostname',
  bodyLimit: 1048576, // 1MB for internal payloads
  handler: async (request, reply) => {
    const { hostname } = request.params;
    const action = request.body?.action || 'invalidated';
    invalidateConfig(hostname, action);
    request.log.info({ hostname, action }, 'Runtime config hot-reload triggered via HTTP');
    return reply.code(200).send({ status: 'ok', hostname, action });
  }
});

// Initialize Redis subscriber for real-time config cache invalidation
// This is non-blocking — proxy works fine without Redis (falls back to TTL)
initRedisSubscriber(fastify.log);
setCircuitBreakerLogger(fastify.log);

/**
 * Main HTTP routing for non-GET methods.
 */
fastify.route({
  method: ['POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'],
  url: '/*',
  bodyLimit: 52428800, // 50MB
  handler: buildProxyHandler(checkDomainRateLimit, fastify, { REVALIDATE_SECRET, UPSTREAM_REQUEST_TIMEOUT_MS })
});

/**
 * GET route with WebSocket proxying support.
 */
fastify.route({
  method: 'GET',
  url: '/*',
  bodyLimit: 52428800, // 50MB
  handler: buildProxyHandler(checkDomainRateLimit, fastify, { REVALIDATE_SECRET, UPSTREAM_REQUEST_TIMEOUT_MS }),
  wsHandler: buildWsHandler(checkDomainRateLimit)
});

function validateConfig() {
  const required = ['LARAVEL_URL', 'PROXY_SHARED_SECRET'];
  for (const key of required) {
    if (!process.env[key]) throw new Error(`Missing required env: ${key}`);
  }
  if (process.env.REDIS_URL) {
    try { new URL(process.env.REDIS_URL); }
    catch { throw new Error('Invalid REDIS_URL format'); }
  }
}

// Start server
try {
  validateConfig();
  preWarmFromDisk(); // Load disk-cached configs into RAM before accepting traffic
  initCacheStore(fastify.log); // Init the edge cache store
  await fastify.listen({ port: PORT, host: '0.0.0.0' });
  fastify.log.info(`YCookies proxy listening on port ${PORT}`);
  startFlushTimer();
  startErrorFlush();
  fastify.log.info('Edge metrics and error flush timers started');

  // ── Pre-warm config cache (non-blocking) ──────────────────────
  // Fetch the admin host's config in the background so the first
  // real request doesn't suffer a cold-start cache miss.
  const bootstrapHost = process.env.ADMIN_HOST || process.env.LARAVEL_API_HOST;
  if (bootstrapHost) {
    getDomainConfig(bootstrapHost, fastify.log)
      .then(() => fastify.log.info({ host: bootstrapHost }, 'Pre-warmed config cache'))
      .catch((err) => fastify.log.debug({ err: err.message }, 'Pre-warm skipped (non-fatal)'));
  }
} catch (err) {
  fastify.log.fatal(err, 'Failed to start proxy');
  process.exit(1);
}

// Graceful shutdown
registerGracefulShutdown(fastify);
