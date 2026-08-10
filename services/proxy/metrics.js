/**
 * Edge Metrics — Bounded in-memory queue with async batch flush.
 *
 * Captures per-request telemetry from the proxy hot path, batches it,
 * and sends to Laravel's /api/metrics/batch endpoint. Designed for
 * minimal hot-path cost (~40µs per recordMetric call).
 *
 * Schema designed queue-ready — swap HTTP for Redis Streams/NATS later
 * by replacing flush() transport only.
 *
 * Privacy: no raw IPs, no cookie values, no query strings, no UA strings.
 */

import { randomUUID } from 'node:crypto';
import { postMetricsBatch } from './config-resolver.js';
import { fingerprint } from './route-fingerprint.js';
import { createRequire } from 'node:module';

// Read proxy version from package.json at startup
const require = createRequire(import.meta.url);
const { version: PROXY_VERSION } = require('./package.json');

// ── Configuration ──────────────────────────────────────────

const MAX_BATCH   = parseInt(process.env.METRICS_BATCH_SIZE   || '200', 10);
const MAX_QUEUE   = parseInt(process.env.METRICS_QUEUE_MAX    || '500', 10);
const FLUSH_MS    = parseInt(process.env.METRICS_FLUSH_MS     || '10000', 10);
const ENABLED     = process.env.METRICS_ENABLED !== 'false';  // on by default

// ── State ──────────────────────────────────────────────────

const queue     = [];
let lastFlush   = Date.now();
let flushCount  = 0;
let sendErrors  = 0;
let totalEvents = 0;
let droppedEvents = 0;
let flushTimer  = null;
let consecutiveFailures = 0;
let lastSuccessAt = null;
let lastFlushDurationMs = 0;

// ── Public API ─────────────────────────────────────────────

/**
 * Record a single edge metric event.
 * Called once per proxy request, after the response is sent.
 *
 * @param {object} m - Metric fields (see canonical schema in implementation_plan.md)
 */
export function recordMetric(m) {
  if (!ENABLED) return;

  totalEvents++;

  // Bounded queue — drop oldest if at capacity
  if (queue.length >= MAX_QUEUE) {
    queue.shift();
    droppedEvents++;
    console.warn(`[metrics] queue full (max ${MAX_QUEUE}), oldest event dropped`);
  }

  // Fingerprint the path before queueing — raw path never leaves the proxy
  const route_pattern = m.path ? fingerprint(m.path) : '/';

  queue.push({
    id:             randomUUID(),
    ts:             Date.now(),
    proxy_version:  PROXY_VERSION,
    route_pattern,
    ...m,
    path: undefined, // strip raw path from event — privacy
  });

  // Flush if batch threshold reached
  if (queue.length >= MAX_BATCH) {
    flush();
  }
}

/**
 * Flush queued metrics to Laravel.
 * Fire-and-forget — errors are logged and events pushed back for retry.
 */
export async function flush() {
  if (queue.length === 0) return;

  const batch = queue.splice(0, MAX_BATCH);
  const batchId = randomUUID(); // for server-side idempotency
  lastFlush = Date.now();
  flushCount++;

  const sendStart = Date.now();
  try {
    await postMetricsBatch(batch, batchId);
    consecutiveFailures = 0;
    lastSuccessAt = Date.now();
    lastFlushDurationMs = Date.now() - sendStart;
  } catch (err) {
    sendErrors++;
    consecutiveFailures++;
    lastFlushDurationMs = Date.now() - sendStart;
    // Push failed batch back to front of queue (bounded — will drop oldest if full)
    const spaceAvailable = MAX_QUEUE - queue.length;
    const toRestore = batch.slice(0, spaceAvailable);
    queue.unshift(...toRestore);
    if (batch.length > spaceAvailable) {
      droppedEvents += (batch.length - spaceAvailable);
    }
    // Log but don't crash
    console.error('[metrics] batch send failed:', err.message);
  }
}

/**
 * Get metrics subsystem stats for /statsz.
 */
export function getMetricsStats() {
  return {
    enabled:        ENABLED,
    queueSize:      queue.length,
    maxQueue:       MAX_QUEUE,
    batchSize:      MAX_BATCH,
    flushIntervalMs: FLUSH_MS,
    totalEvents,
    droppedEvents,
    flushCount,
    sendErrors,
    consecutiveFailures,
    lastFlushAt:      lastFlush ? new Date(lastFlush).toISOString() : null,
    lastSuccessAt:    lastSuccessAt ? new Date(lastSuccessAt).toISOString() : null,
    lastFlushDurationMs,
  };
}

/**
 * Start the background flush timer.
 * Call once at startup. Timer ensures metrics are flushed even during low traffic.
 */
export function startFlushTimer() {
  if (!ENABLED) return;
  if (flushTimer) return; // idempotent

  flushTimer = setInterval(() => {
    flush().catch(err => {
      console.error('[metrics] timer flush failed:', err.message);
    });
  }, FLUSH_MS);

  // Don't prevent Node from exiting
  if (flushTimer.unref) flushTimer.unref();
}

/**
 * Stop the flush timer and drain remaining events.
 * Call during graceful shutdown.
 */
export async function stopAndDrain() {
  if (flushTimer) {
    clearInterval(flushTimer);
    flushTimer = null;
  }
  // Final flush attempt
  if (queue.length > 0) {
    try {
      await flush();
    } catch {
      // Best-effort on shutdown
    }
  }
}
