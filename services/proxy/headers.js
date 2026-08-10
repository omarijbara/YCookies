/**
 * Header Handling — Request/Response header filtering for proxy.
 *
 * A proper reverse proxy forwards ALL headers except hop-by-hop ones.
 * This is a significant improvement over the Laravel middleware which
 * only forwarded a small safe-header subset.
 */

/**
 * HTTP/1.1 hop-by-hop headers that MUST NOT be forwarded by a proxy.
 * See RFC 7230 Section 6.1 and RFC 9110.
 */
export const HOP_BY_HOP = new Set([
  'connection',
  'keep-alive',
  'transfer-encoding',
  'te',
  'trailer',
  'proxy-authorization',
  'proxy-authenticate',
  'upgrade',           // Conditionally forwarded for WebSocket (Phase 3)
]);

/**
 * Build the full hop-by-hop set including dynamic tokens from the Connection header.
 *
 * RFC 7230 §6.1: "Each token in the Connection header field of a message
 * identifies a header field name that is only relevant for the current
 * connection and MUST NOT be forwarded by a proxy."
 *
 * @param {object} headers - Raw headers object
 * @returns {Set<string>} Combined static + dynamic hop-by-hop set
 */
function buildHopByHopSet(headers) {
  const connectionValue = headers['connection'] || headers['Connection'] || '';
  if (!connectionValue) return HOP_BY_HOP;

  // Parse comma-separated token list (e.g. "keep-alive, X-Custom, close")
  const dynamic = new Set(HOP_BY_HOP);
  for (const token of connectionValue.split(',')) {
    const trimmed = token.trim().toLowerCase();
    if (trimmed) dynamic.add(trimmed);
  }
  return dynamic;
}

/**
 * Headers the proxy adds/overrides on upstream requests.
 */
const PROXY_REQUEST_HEADERS = new Set([
  'x-forwarded-for',
  'x-forwarded-host',
  'x-forwarded-proto',
  'x-request-id',
  'accept-encoding',  // Stripped — proxy requests identity from origin to avoid Z_BUF_ERROR
]);

/**
 * Filter incoming request headers for upstream forwarding.
 *
 * - Strips hop-by-hop headers
 * - Adds X-Forwarded-* headers
 * - Preserves all other headers (unlike Laravel's safe-subset approach)
 *
 * @param {object} incomingHeaders - Raw request headers
 * @param {string} clientIp - Client's IP address
 * @returns {object} Headers to forward upstream
 */
export function filterRequestHeaders(incomingHeaders, clientIp) {
  const forwarded = {};
  const hopByHop = buildHopByHopSet(incomingHeaders);

  for (const [key, value] of Object.entries(incomingHeaders)) {
    const lower = key.toLowerCase();

    // Skip HTTP/2 pseudo-headers (colon-prefixed) — must not be forwarded as H/1 headers
    if (lower.startsWith(':')) continue;

    // Skip hop-by-hop (static set + dynamic Connection tokens per RFC 7230 §6.1)
    if (hopByHop.has(lower)) continue;

    // Skip proxy-added headers (we'll set our own)
    if (PROXY_REQUEST_HEADERS.has(lower)) continue;

    forwarded[key] = value;
  }

  // Add standard proxy headers
  forwarded['x-forwarded-for'] = clientIp;
  forwarded['x-forwarded-host'] = incomingHeaders.host || '';
  forwarded['x-forwarded-proto'] = 'https';

  // Request uncompressed content from origin — proxy injection/blocking
  // operates on raw HTML. Traefik re-compresses for the client.
  // This prevents Z_BUF_ERROR crashes from truncated compressed streams.
  forwarded['accept-encoding'] = 'identity';

  // Realistic User-Agent if not present
  if (!forwarded['user-agent']) {
    forwarded['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
  }

  return forwarded;
}

/**
 * Filter upstream response headers for client delivery.
 *
 * - Strips hop-by-hop headers
 * - Excludes set-cookie (handled separately by cookie-filter.js)
 * - Passes everything else through (preserving Cache-Control, ETag, etc.)
 *
 * @param {object} upstreamHeaders - Headers from origin response
 * @returns {object} Headers to send to client
 */
export function filterResponseHeaders(upstreamHeaders) {
  const filtered = {};
  const hopByHop = buildHopByHopSet(upstreamHeaders);

  for (const [key, value] of Object.entries(upstreamHeaders)) {
    const lower = key.toLowerCase();

    // Skip HTTP/2 pseudo-headers (colon-prefixed)
    if (lower.startsWith(':')) continue;

    // Skip hop-by-hop (static set + dynamic Connection tokens per RFC 7230 §6.1)
    if (hopByHop.has(lower)) continue;

    // Skip set-cookie — handled by cookie-filter.js
    if (lower === 'set-cookie') continue;

    filtered[key] = value;
  }

  return filtered;
}
