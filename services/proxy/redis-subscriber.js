/**
 * Redis Subscriber — Real-time config cache invalidation.
 *
 * Subscribes to 'domain-config-updated' channel published by Laravel.
 * When a domain config changes, Laravel bumps config_version and publishes
 * {host, version} to Redis. This module receives the message and instantly
 * flushes the in-memory config cache for that host.
 *
 * Connection failures are handled gracefully — the proxy continues to work
 * with TTL-based cache expiry as a fallback.
 *
 * Hardening (AI-consensus):
 * - Heartbeat tracking: every pub/sub message updates lastEventAt.
 * - If >30s pass without any event AND connected===false, switch to
 *   5s HTTP polling of Laravel's proxy-config healthcheck.
 * - When pub/sub reconnects, polling is automatically disabled.
 * - /healthz surfaces mode + lastEventAt so Traefik can gate traffic.
 */

import Redis from 'ioredis';
import { invalidateConfig } from './config-resolver.js';
import { invalidateHtmlCacheByHost } from './cache-store.js';

const REDIS_URL = process.env.REDIS_URL || 'redis://127.0.0.1:6379';
const LARAVEL_URL = process.env.LARAVEL_URL || 'http://127.0.0.1:8000';
const CHANNEL = 'domain-config-updated';

// ── State ────────────────────────────────────────────────────────
let connected = false;
let totalInvalidations = 0;
let lastInvalidationAt = null;
let lastEventAt = Date.now();   // Tracks freshness of pub/sub
let pollTimer = null;           // Set when polling fallback is active
let _logger = console;          // Replaced in initRedisSubscriber

// ── Heartbeat constants ──────────────────────────────────────────
const HEARTBEAT_CHECK_INTERVAL = 10_000;  // Check every 10s
const HEARTBEAT_STALE_THRESHOLD = 30_000; // 30s without event = stale
const POLL_INTERVAL = 5_000;              // Poll Laravel every 5s

/**
 * Initialize Redis subscriber.
 * Call this once at proxy startup.
 *
 * @param {import('pino').Logger} logger - Fastify/Pino logger instance
 */
export function initRedisSubscriber(logger) {
  _logger = logger;

  const subscriber = new Redis(REDIS_URL, {
    retryStrategy(times) {
      // Exponential backoff: 1s, 2s, 4s, 8s, max 30s + jitter
      const jitter = Math.floor(Math.random() * 1000); // 0-1s random
      const delay = Math.min(1000 * Math.pow(2, times - 1), 30000) + jitter;
      logger.warn({ attempt: times, delay }, 'Redis reconnecting...');
      return delay;
    },
    maxRetriesPerRequest: null, // Never give up on reconnecting
    lazyConnect: false,
  });

  subscriber.on('connect', () => {
    connected = true;
    lastEventAt = Date.now();
    logger.info({ channel: CHANNEL }, 'Redis subscriber connected');
    disablePollingFallback(); // Pub/sub is back — stop polling
  });

  subscriber.on('error', (err) => {
    connected = false;
    logger.error({ err: err.message }, 'Redis subscriber error');
  });

  subscriber.on('close', () => {
    connected = false;
    logger.warn('Redis subscriber disconnected');
  });

  subscriber.subscribe(CHANNEL, (err) => {
    if (err) {
      logger.error({ err: err.message }, `Failed to subscribe to ${CHANNEL}`);
    } else {
      logger.info(`Subscribed to Redis channel: ${CHANNEL}`);
    }
  });

  subscriber.on('message', (channel, message) => {
    if (channel !== CHANNEL) return;

    try {
      const { host, version, action } = JSON.parse(message);
      if (!host) return;

      invalidateConfig(host, action);
      
      // Also invalidate HTML edge cache to free memory from orphaned revision keys
      invalidateHtmlCacheByHost(host, logger);

      totalInvalidations++;
      lastInvalidationAt = new Date().toISOString();
      lastEventAt = Date.now(); // ← Heartbeat: mark freshness

      logger.info({ host, version, action }, '[config] invalidated/pushed via Redis');
    } catch (err) {
      logger.warn({ err: err.message, raw: message }, 'Invalid Redis message');
    }
  });

  // ── Heartbeat monitor ──────────────────────────────────────────
  // Every 10s check if we've gone stale (no event for 30s AND disconnected).
  setInterval(() => {
    const age = Date.now() - lastEventAt;
    if (age > HEARTBEAT_STALE_THRESHOLD && !connected) {
      enablePollingFallback();
    }
  }, HEARTBEAT_CHECK_INTERVAL);

  return subscriber;
}

/**
 * Enable HTTP polling fallback — hits Laravel's proxy-config healthcheck
 * to confirm the connection path is alive and to trigger config refreshes.
 */
function enablePollingFallback() {
  if (pollTimer) return; // Already polling

  _logger.warn('[redis-subscriber] pub/sub stale + disconnected — switching to polling fallback');

  pollTimer = setInterval(async () => {
    try {
      const res = await fetch(`${LARAVEL_URL}/api/proxy-config/healthcheck`, {
        method: 'HEAD',
        signal: AbortSignal.timeout(3000),
        headers: { 'X-YCookies-Internal': '1' },
      });
      if (res.ok) {
        lastEventAt = Date.now(); // Laravel is reachable
      }
    } catch {
      // Ignore — we'll try again in 5s
    }
  }, POLL_INTERVAL);
}

/**
 * Disable polling fallback — called when pub/sub reconnects.
 */
function disablePollingFallback() {
  if (!pollTimer) return;
  clearInterval(pollTimer);
  pollTimer = null;
  _logger.info('[redis-subscriber] pub/sub reconnected — polling fallback disabled');
}

/**
 * Get Redis subscriber status for health endpoint.
 */
export function getRedisStatus() {
  return {
    connected,
    mode: pollTimer ? 'polling' : 'pubsub',
    lastEventAt: new Date(lastEventAt).toISOString(),
    lastEventAgeMs: Date.now() - lastEventAt,
    totalInvalidations,
    lastInvalidationAt,
  };
}

/**
 * Check if the subscriber connection is currently active.
 * Used by laravel-client metrics to report Pub/Sub health.
 */
export function isSubscriberConnected() {
  return connected;
}
