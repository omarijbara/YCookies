export const PROXY_ERRORS = {
  DOMAIN_NOT_CONFIGURED: { code: 'PROXY_E001', status: 503, message: 'Domain not configured for proxying' },
  ORIGIN_UNREACHABLE:    { code: 'PROXY_E002', status: 502, message: 'Origin address is invalid' },
  SSRF_BLOCKED:          { code: 'PROXY_E003', status: 502, message: 'Origin address resolves to a restricted network' },
  RATE_LIMITED:          { code: 'PROXY_E004', status: 429, message: 'Rate limit exceeded' },
  CONFIG_FETCH_FAILED:   { code: 'PROXY_E005', status: 503, message: 'Proxy configuration could not be loaded' },
  UPSTREAM_TIMEOUT:      { code: 'PROXY_E006', status: 504, message: 'Upstream connection timed out' },
};

/**
 * Sends a structured error response, automatically handling content negotiation (HTML vs JSON).
 * 
 * @param {import('fastify').FastifyReply} reply 
 * @param {import('fastify').FastifyRequest} request 
 * @param {object} errorDef - From PROXY_ERRORS
 * @param {string} [title='Service Error'] - Title for the HTML body
 */
export function sendProxyError(reply, request, errorDef, title = 'Service Error') {
  const acceptsJson = request.headers.accept?.includes('application/json');

  reply.code(errorDef.status).header('cache-control', 'no-store');

  if (acceptsJson) {
      return reply.send({
          error: {
              code: errorDef.code,
              message: errorDef.message
          }
      });
  }

  return reply.type('text/html').send(
      `<html><body><h1>${title}</h1><p>${errorDef.message}</p><code>${errorDef.code}</code></body></html>`
  );
}
