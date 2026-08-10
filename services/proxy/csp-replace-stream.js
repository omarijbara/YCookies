import { Transform } from 'node:stream';

/**
 * Creates a transform stream that replaces a specific token with a nonce.
 * Safely handles chunk boundaries by buffering the end of chunks.
 *
 * @param {string} token - The token to replace (e.g. '__YCOOKIES_NONCE_TOKEN__')
 * @param {string} rootNonce - The fresh nonce to substitute
 * @returns {Transform}
 */
export function createNonceReplaceStream(token, rootNonce) {
  if (!token || !rootNonce) {
    return new Transform({
      transform(chunk, encoding, callback) {
        callback(null, chunk);
      }
    });
  }

  const tokenBuf = Buffer.from(token, 'utf8');
  const nonceBuf = Buffer.from(rootNonce, 'utf8');
  const bufferLen = tokenBuf.length - 1;

  let tailBuffer = Buffer.alloc(0);

  return new Transform({
    transform(chunk, encoding, callback) {
      if (!chunk || chunk.length === 0) {
        return callback(null, chunk);
      }
      
      const combined = Buffer.concat([tailBuffer, chunk]);
      const resultChunks = [];
      let offset = 0;

      while (offset < combined.length) {
        const matchIndex = combined.indexOf(tokenBuf, offset);
        
        if (matchIndex === -1) {
          // No match found in the current buffer.
          // Buffer the last `bufferLen` bytes in case the token spans chunks.
          const remaining = combined.length - offset;
          if (remaining > bufferLen) {
            // Safe to push everything up to the tail we need to keep
            const pushLen = remaining - bufferLen;
            resultChunks.push(combined.subarray(offset, offset + pushLen));
            tailBuffer = combined.subarray(offset + pushLen);
          } else {
            // The whole remaining buffer is too small, keep it all as tail
            tailBuffer = combined.subarray(offset);
          }
          break;
        } else {
          // Found match
          if (matchIndex > offset) {
            resultChunks.push(combined.subarray(offset, matchIndex));
          }
          resultChunks.push(nonceBuf);
          offset = matchIndex + tokenBuf.length;
        }
      }

      if (resultChunks.length > 0) {
        callback(null, Buffer.concat(resultChunks));
      } else {
        callback();
      }
    },

    flush(callback) {
      if (tailBuffer.length > 0) {
        // If the tail ends up containing the token somehow (it shouldn't since it's smaller
        // than the token length), but let's do one last replace just in case.
        const tailStr = tailBuffer.toString('utf8').replace(new RegExp(token, 'g'), rootNonce);
        callback(null, Buffer.from(tailStr, 'utf8'));
      } else {
        callback();
      }
      tailBuffer = Buffer.alloc(0);
    }
  });
}
