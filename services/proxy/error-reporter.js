/**
 * Lightweight error reporter for the Node proxy.
 * Buffers errors in memory and flushes to Laravel every 60s.
 * - Max 50 errors per batch (oldest dropped if exceeded)
 * - Never blocks the request pipeline
 * - Never crashes the proxy if Laravel is unreachable
 */
import { postErrorBatch } from './config-resolver.js';

const buffer = [];
const MAX_BUFFER = 50;
const FLUSH_INTERVAL_MS = 60000; // 1 minute
const BASE_WINDOW_MS = 300000;     // 5 minutes (base window)
const MAX_WINDOW_MS = 21600000;    // 6 hours (max window)
const recentFingerprints = new Map(); // fingerprint → { firstSeen: timestamp, count: number, windowMs: number }

export function reportError(err, context = {}) {
    const message = err.message || String(err);
    const stackLines = err.stack?.split('\n') || [];
    const firstTraceLine = stackLines[1]?.trim() || '';
    const fingerprint = `${message.slice(0, 100)}:${firstTraceLine}`;

    // Dedup with exponential backoff
    const recent = recentFingerprints.get(fingerprint);
    const now = Date.now();
    
    if (recent) {
        const timeSince = now - recent.firstSeen;
        
        if (timeSince > recent.windowMs * 2) {
            // It's been silent for 2x the window; let's reset the backoff
            recent.windowMs = BASE_WINDOW_MS;
            recent.firstSeen = now;
            recent.count = 1;
        } else if (timeSince < recent.windowMs) {
            // Still within the silencing window - deduplicate secretly
            recent.count++;
            return;
        } else {
            // Window expired but error is recurring. Increase backoff and emit a warning
            recent.windowMs = Math.min(recent.windowMs * 3, MAX_WINDOW_MS);
            recent.firstSeen = now;
            recent.count = 1; 
            console.warn(`[Error Bridge] Recurring error detected. Next dedup window expanded to ${recent.windowMs / 1000 / 60}m. Fingerprint: ${fingerprint}`);
        }
    } else {
        recentFingerprints.set(fingerprint, { firstSeen: now, count: 1, windowMs: BASE_WINDOW_MS });
    }

    // Add to buffer
    if (buffer.length >= MAX_BUFFER) {
        buffer.shift(); // Drop oldest to prevent memory growth
    }
    buffer.push({
        level: context.level || 'error',
        source: 'node-proxy',
        message: message,
        stack_trace: err.stack || '',
        context: {
            hostname: context.hostname || '',
            url: context.url || '',
            method: context.method || '',
            status_code: context.statusCode || 0,
            node_version: process.version,
            memory_mb: Math.round(process.memoryUsage().heapUsed / 1048576),
            uptime_s: Math.round(process.uptime()),
            ...context.extra,
        },
        occurred_at: new Date().toISOString(),
        fingerprint,
        occurrence_count: 1, // Will be updated during flush
    });
}

// Periodic flush
let flushTimer = null;
export function startErrorFlush() {
    if (flushTimer) return;
    flushTimer = setInterval(async () => {
        if (buffer.length === 0) return;
        
        const batch = buffer.splice(0, buffer.length);
        
        // Update occurrence counts from the dedup map
        for (const entry of batch) {
            const recent = recentFingerprints.get(entry.fingerprint);
            if (recent) {
                entry.occurrence_count = recent.count;
            }
        }
        
        // Cleanup expired fingerprints from map
        const now = Date.now();
        for (const [fp, data] of recentFingerprints.entries()) {
            if (now - data.firstSeen > data.windowMs * 2) {
                recentFingerprints.delete(fp);
            }
        }

        try {
            await postErrorBatch(batch);
        } catch (e) {
            // Non-fatal — we're already in a background flush
            console.error(`[Error Bridge] Failed to flush error batch: ${e.message}`);
        }
    }, FLUSH_INTERVAL_MS);
}

export function stopErrorFlush() {
    if (flushTimer) {
        clearInterval(flushTimer);
        flushTimer = null;
    }
}
