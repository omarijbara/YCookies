export const DOCKER_INTERNAL = ['10.', '172.', '192.168.', '127.0.0.1', '::1', '::ffff:10.', '::ffff:172.', '::ffff:192.168.', '::ffff:127.'];

export function isInternalProxyIp(request) {
  const ip = request.ip || '';
  return DOCKER_INTERNAL.some(prefix => ip.startsWith(prefix));
}

export function resolveRateLimitKey(request) {
  const xff = request.headers['x-forwarded-for'];
  if (xff) {
    return xff.split(',')[0].trim();
  }
  return request.headers['x-real-ip'] || request.ip;
}

export function applyRateLimitHeaders(reply, limit) {
  if (limit.isAllowed) {
    return;
  }

  reply.header('x-ratelimit-limit', String(limit.max));
  reply.header('x-ratelimit-remaining', String(limit.remaining));
  reply.header('x-ratelimit-reset', String(limit.ttlInSeconds));

  if (limit.isExceeded) {
    reply.header('retry-after', String(limit.ttlInSeconds));
  }
}

export function buildRateLimitExceededPayload(limit) {
  const seconds = Math.max(1, Number(limit.ttlInSeconds || Math.ceil((limit.ttl || 0) / 1000)));

  return {
    statusCode: 429,
    error: 'Too Many Requests',
    message: `Rate limit exceeded, retry in ${seconds} seconds`,
  };
}

/**
 * Enforces rate limiting using the Fastify checkDomainRateLimit hook.
 * Returns true if allowed, false if blocked (and sends the 429).
 */
export async function enforceDomainRateLimit(request, reply, config, pathname, checkDomainRateLimit) {
  request.ycRateLimitConfig = config?.rate_limit || null;
  request.ycRateLimitPath = pathname || '/';

  const limit = await checkDomainRateLimit(request);
  applyRateLimitHeaders(reply, limit);

  if (!limit.isAllowed && (limit.isExceeded || limit.isBanned)) {
    const statusCode = limit.isBanned ? 403 : 429;
    const payload = limit.isBanned
      ? {
          statusCode: 403,
          error: 'Forbidden',
          message: 'Rate limit ban active',
        }
      : buildRateLimitExceededPayload(limit);

    reply.code(statusCode).type('application/json').send(payload);
    return false;
  }

  return true;
}
