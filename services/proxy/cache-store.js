/**
 * Edge Page-Cache Engine
 * 
 * Provides a unified caching layer storing parsed buffers and headers.
 * Uses `ioredis` if REDIS_URL is present, otherwise falls back to a 
 * resilient in-memory Map (LRU-like cache).
 */

import Redis from 'ioredis';

const REDIS_URL = process.env.REDIS_URL || '';
const CACHE_ENABLED = process.env.CACHE_ENABLED !== 'false';
const PREFIX = 'yc_cache:';

let redisClient = null;
const memoryCache = new Map();
const MEMORY_LIMIT = 2000; // max items to avoid OOM

let cacheWriteFailures = 0;
let pendingCacheWrites = 0;
const MAX_PENDING_WRITES = parseInt(process.env.CACHE_MAX_PENDING_WRITES || '50', 10);

/**
 * Initialize the Cache Store.
 * @param {import('pino').Logger} logger - Fastify/Pino logger instance
 */
export function initCacheStore(logger) {
  if (REDIS_URL && CACHE_ENABLED) {
    redisClient = new Redis(REDIS_URL, {
      lazyConnect: false,
      retryStrategy(times) {
        return Math.min(times * 500, 5000);
      }
    });

    redisClient.on('connect', () => {
      logger.info('Edge Cache connected to Redis');
    });

    redisClient.on('error', (err) => {
      logger.error({ err: err.message }, 'Edge Cache Redis connection error');
    });
  } else {
    logger.info('Edge Cache using in-memory store (Redis not configured or disabled)');
  }
}

/**
 * Retrieve an item from the cache.
 * 
 * @param {string} key - Cache key representing the request (Tenant:Method:Path)
 * @returns {Promise<{ headers: object, body: Buffer } | null>}
 */
export async function getCache(key) {
  if (!CACHE_ENABLED) return null;
  const fullKey = `${PREFIX}${key}`;

  try {
    if (redisClient && redisClient.status === 'ready') {
      const data = await redisClient.getBuffer(fullKey);
      if (!data) return null;
      
      return deserialize(data);
    } else {
      const entry = memoryCache.get(fullKey);
      if (entry) {
        if (Date.now() > entry.expiresAt) {
          memoryCache.delete(fullKey);
          return null;
        }
        return entry.data;
      }
    }
  } catch (err) {
    // Fail open on read errors
    return null;
  }
  return null;
}

/**
 * Store an item in the cache with a specified TTL.
 * 
 * @param {string} key - Cache key representing the request
 * @param {object} headers - Filtered HTTP response headers
 * @param {Buffer} body - Raw Buffer of the payload
 * @param {number} ttlSeconds - Time-to-Live in seconds
 * @returns {Promise<boolean>}
 */
export async function setCache(key, headers, body, ttlSeconds = 300) {
  if (!CACHE_ENABLED) return false;
  const fullKey = `${PREFIX}${key}`;

  try {
    const payload = serialize(headers, body);

    if (redisClient && redisClient.status === 'ready') {
      if (pendingCacheWrites >= MAX_PENDING_WRITES) {
          // Backpressure: drop write to prevent OOM
          return false;
      }
      pendingCacheWrites++;
      try {
          await redisClient.setex(fullKey, ttlSeconds, payload);
      } finally {
          pendingCacheWrites--;
      }
    } else {
      enforceMemoryLimit();
      memoryCache.set(fullKey, {
        expiresAt: Date.now() + (ttlSeconds * 1000),
        data: { headers, body }
      });
    }
    return true;
  } catch (err) {
    cacheWriteFailures++;
    // Log but don't block
    return false;
  }
}

/**
 * Get internal stats of the cache store for observability.
 * @returns {Promise<{ store: string, items: number|string, write_failures: number }>}
 */
export async function getStoreStats() {
  let items = 0;
  let store = 'none';
  let redis_memory = null;

  if (CACHE_ENABLED) {
    if (redisClient && redisClient.status === 'ready') {
      store = 'redis';
      try {
        items = await redisClient.dbsize();
      } catch (err) {
        items = -1;
      }

      // Collect Redis memory info for monitoring
      try {
        const info = await redisClient.info('memory');
        const usedMatch = info.match(/used_memory:(\d+)/);
        const maxMatch = info.match(/maxmemory:(\d+)/);
        const peakMatch = info.match(/used_memory_peak:(\d+)/);
        const fragMatch = info.match(/mem_fragmentation_ratio:([\d.]+)/);

        const used = usedMatch ? parseInt(usedMatch[1], 10) : 0;
        const max = maxMatch ? parseInt(maxMatch[1], 10) : 0;
        const peak = peakMatch ? parseInt(peakMatch[1], 10) : 0;
        const frag = fragMatch ? parseFloat(fragMatch[1]) : 0;

        // Get eviction policy
        let policy = 'unknown';
        try {
          const configResult = await redisClient.config('GET', 'maxmemory-policy');
          if (Array.isArray(configResult) && configResult.length >= 2) {
            policy = configResult[1];
          }
        } catch { /* config command may be disabled */ }

        redis_memory = {
          used_mb: Math.round(used / 1024 / 1024 * 100) / 100,
          max_mb: max > 0 ? Math.round(max / 1024 / 1024 * 100) / 100 : 'unlimited',
          peak_mb: Math.round(peak / 1024 / 1024 * 100) / 100,
          usage_pct: max > 0 ? Math.round(used / max * 10000) / 100 : null,
          fragmentation_ratio: frag,
          eviction_policy: policy,
        };
      } catch { /* Redis info not available — non-fatal */ }
    } else {
      store = 'memory';
      items = memoryCache.size;
    }
  }

  return {
    store,
    items,
    write_failures: cacheWriteFailures,
    ...(redis_memory && { redis_memory }),
  };
}

/**
 * Serialize headers and body Buffer into a single Buffer
 * Memory layout: [4 bytes header length] + [JSON String Headers] + [Raw Body]
 */
function serialize(headers, body) {
  const headerStr = JSON.stringify(headers);
  const headerBuf = Buffer.from(headerStr, 'utf8');
  
  const lengthBuf = Buffer.alloc(4);
  lengthBuf.writeUInt32BE(headerBuf.length, 0);
  
  return Buffer.concat([lengthBuf, headerBuf, body]);
}

/**
 * Deserialize a combined Buffer back into headers and body
 */
function deserialize(buffer) {
  if (buffer.length < 4) throw new Error('Invalid cache payload');
  
  const headerLen = buffer.readUInt32BE(0);
  if (buffer.length < 4 + headerLen) throw new Error('Truncated cache payload');
  
  const headerStr = buffer.slice(4, 4 + headerLen).toString('utf8');
  const headers = JSON.parse(headerStr);
  
  const body = buffer.slice(4 + headerLen);
  return { headers, body };
}

/**
 * Primitive LRU enforcement for Map.
 */
function enforceMemoryLimit() {
  if (memoryCache.size >= MEMORY_LIMIT) {
    const oldestKey = memoryCache.keys().next().value;
    memoryCache.delete(oldestKey);
  }
}

/**
 * Invalidate all cached HTML responses for a specific tenant.
 * Used when a new config revision is published to instantly free memory
 * and ensure no stale data remains, even though the cache key includes the revision.
 * 
 * @param {string} host - The tenant hostname
 * @param {import('pino').Logger} logger - Logger instance
 */
export async function invalidateHtmlCacheByHost(host, logger) {
  if (!CACHE_ENABLED) return;
  
  const pattern = `${PREFIX}${host}:*`;
  
  if (redisClient && redisClient.status === 'ready') {
    try {
      let cursor = '0';
      let deletedCount = 0;
      do {
        // Use a conservative COUNT to prevent blocking Redis event loop
        const [nextCursor, keys] = await redisClient.scan(
          cursor, 
          'MATCH', pattern, 
          'COUNT', '100'
        );
        cursor = nextCursor;
        if (keys.length > 0) {
          // del is variadic and blocks minimally for small numbers of keys
          await redisClient.del(...keys);
          deletedCount += keys.length;
        }
      } while (cursor !== '0');
      
      if (logger) {
        logger.info({ host, deletedCount }, 'Edge Cache keys invalidated via flush');
      }
    } catch (err) {
      if (logger) {
        logger.warn({ err: err.message, host }, 'Failed to invalidate Edge Cache keys');
      }
    }
  } else {
    // In-memory fallback
    let deletedCount = 0;
    const prefix = pattern.replace('*', '');
    for (const key of memoryCache.keys()) {
      if (key.startsWith(prefix)) {
        memoryCache.delete(key);
        deletedCount++;
      }
    }
    if (logger && deletedCount > 0) {
      logger.info({ host, deletedCount }, 'Edge Cache in-memory keys invalidated via flush');
    }
  }
}

