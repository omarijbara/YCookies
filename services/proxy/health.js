import { getCacheStats } from './config-resolver.js';
import { getRedisStatus } from './redis-subscriber.js';
import { getAgentCacheStats } from './agent-pool.js';
import { getCacheStats as getResponseCacheStats } from './response-cache.js';
import { getMetricsStats } from './metrics.js';
import { getCounters } from './proxy-counters.js';
import { getStoreStats } from './cache-store.js';
import { manifestMetrics } from './manifest-consumer.js';
import { getCircuitStats } from './circuit-breaker.js';
import { request as undiciRequest } from 'undici';
import { readFileSync } from 'node:fs';

const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url)));
const PROXY_VERSION = pkg.version;

const LARAVEL_URL = process.env.LARAVEL_URL || 'http://127.0.0.1:8000';
const startedAt = Date.now();

/**
 * Register health check routes on a Fastify instance.
 *
 * @param {import('fastify').FastifyInstance} fastify
 */
export function registerHealthRoutes(fastify) {
  // Liveness: is the process alive AND is Redis subscriber healthy?
  // /healthz and /health serve as Docker healthcheck targets.
  // Returns 503 when Redis subscriber is stale + disconnected,
  // so Traefik stops routing to a proxy with broken cache invalidation.
  const livenessHandler = async (_request, reply) => {
    const redis = getRedisStatus();
    const subscriberHealthy = redis.connected || redis.lastEventAgeMs < 60_000;

    const status = subscriberHealthy ? 'ok' : 'degraded';
    const code = subscriberHealthy ? 200 : 503;

    reply.code(code).send({
      status,
      version: PROXY_VERSION,
      uptime: Math.round((Date.now() - startedAt) / 1000),
      redis_subscriber: {
        connected: redis.connected,
        mode: redis.mode,
        lastEventAgeMs: redis.lastEventAgeMs,
      },
    });
  };

  fastify.get('/healthz', livenessHandler);
  fastify.get('/health', livenessHandler);

  // Readiness: can we reach Laravel?
  fastify.get('/readyz', async (_request, reply) => {
    try {
      const laravelUrl = process.env.LARAVEL_URL || 'http://127.0.0.1:8000';
      const response = await undiciRequest(`${laravelUrl}/api/proxy-config/healthcheck`, {
        method: 'HEAD',
        headersTimeout: 3000,
        bodyTimeout: 3000,
      });
      // Drain body to free connection
      if (response.body) await response.body.text();

      // Even a 404 means Laravel is responding
      reply.code(200).send({
        status: 'ok',
        laravel: 'reachable',
        laravel_status: response.statusCode,
      });
    } catch (err) {
      reply.code(503).send({
        status: 'not_ready',
        laravel: 'unreachable',
        error: err.message,
      });
    }
  });

  // Stats: cache, Redis, and uptime info (internal only)
  fastify.get('/statsz', async (_request, reply) => {
    const cacheStats = getCacheStats();
    const redisStatus = getRedisStatus();
    const proxyCounters = getCounters();
    const responseCacheStats = getResponseCacheStats();
    const storeStats = await getStoreStats();

    reply.code(200).send({
      status: 'ok',
      uptime: Math.round((Date.now() - startedAt) / 1000),
      // User-requested explicit fields:
      cache_hits: proxyCounters['cache_hit'] || 0,
      cache_misses: proxyCounters['cache_miss'] || 0,
      cache_bypasses: proxyCounters['cache_bypass'] || 0,
      cache_write_failures: storeStats.write_failures,
      cache_store: storeStats.store,
      approx_cached_items: storeStats.items,
      top_bypass_reasons: responseCacheStats.bypass_reasons,
      // Existing observability:
      memory: {
        rss: Math.round(process.memoryUsage().rss / 1024 / 1024),
        heapUsed: Math.round(process.memoryUsage().heapUsed / 1024 / 1024),
        heapTotal: Math.round(process.memoryUsage().heapTotal / 1024 / 1024),
      },
      cache: cacheStats,
      agents: getAgentCacheStats(),
      response_cache: responseCacheStats,
      redis: redisStatus,
      metrics: getMetricsStats(),
      proxy_counters: proxyCounters,
      manifest: manifestMetrics,
      circuit_breaker: getCircuitStats(),
    });
  });
}
