import zlib from 'node:zlib';
import { inc } from './proxy-counters.js';

/**
 * Automatically wraps a compressed readable stream with the appropriate decompressor.
 * Modifies the reply headers to strip content-encoding if decompression is applied.
 * 
 * @param {import('node:stream').Readable} stream - The incoming stream from upstream
 * @param {import('fastify').FastifyReply} reply - The Fastify reply object (to strip headers)
 * @param {import('fastify').FastifyRequest} request - The Fastify request object (for logging)
 * @param {string} requestId - Correlation ID
 * @returns {import('node:stream').Readable} The uncompressed stream (or the original if not compressed)
 */
export function applyDecompressor(stream, reply, request, requestId) {
  const contentEncoding = (reply.getHeader('content-encoding') || '').toLowerCase();
  
  if (!contentEncoding) {
    return stream;
  }

  let decStream = stream;
  const hostname = request.hostname;

  if (contentEncoding.includes('br')) {
    inc('decompress_br');
    const decompressor = zlib.createBrotliDecompress();
    decompressor.on('error', (err) => {
      request.log.warn({ requestId, hostname, err: err.message }, 'Brotli decompression failed — passing raw');
    });
    decStream = stream.pipe(decompressor);
  } else if (contentEncoding.includes('gzip')) {
    inc('decompress_gzip');
    const decompressor = zlib.createGunzip();
    decompressor.on('error', (err) => {
      request.log.warn({ requestId, hostname, err: err.message }, 'Gzip decompression failed — passing raw');
    });
    decStream = stream.pipe(decompressor);
  } else if (contentEncoding.includes('deflate')) {
    inc('decompress_deflate');
    const decompressor = zlib.createInflate();
    decompressor.on('error', (err) => {
      request.log.warn({ requestId, hostname, err: err.message }, 'Deflate decompression failed — passing raw');
    });
    decStream = stream.pipe(decompressor);
  } else {
      return stream; // Unsupported encoding
  }

  // Client will receive uncompressed HTML (Traefik in front of us will re-compress it)
  reply.removeHeader('content-encoding');
  return decStream;
}
